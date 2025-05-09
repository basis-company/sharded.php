<?php

namespace Basis\Sharding\Driver;

use Basis\Sharding\Database;
use Basis\Sharding\Entity\Change;
use Basis\Sharding\Entity\Subscription;
use Basis\Sharding\Interface\Bootstrap;
use Basis\Sharding\Interface\Driver;
use Basis\Sharding\Schema\Model;
use Exception;
use Tarantool\Client\Client;
use Tarantool\Client\Schema\Criteria;
use Tarantool\Client\Schema\Operations;
use Tarantool\Mapper\Mapper;

class Tarantool implements Driver
{
    public readonly Mapper $mapper;
    private array $context = [];

    public function __construct(
        protected readonly string $dsn,
    ) {
        $this->mapper = new Mapper(Client::fromDsn($dsn));
    }

    public function create(string $table, array $data): object
    {
        $listeners = $this->getListeners($table);
        if (!count($listeners)) {
            return $this->mapper->create($table, $data);
        }

        return $this->processLuaResult(
            table: $table,
            action: 'create',
            params: $this->mapper->getSpace($table)->getTuple($data),
            query: 'local tuple = box.space[table]:insert(params)',
        );
    }

    public function processLuaResult(string $table, array $params, string $query, string $action): object
    {
        $listeners = $this->getListeners($table);
        $context = $this->context;

        [$result] = $this->mapper->call(
            <<<LUA
                box.begin()
                local register_changes = true
                {$query}
                if box.sequence.sharding_change == nil then
                    box.schema.sequence.create(sharding_change)
                end
                if register_changes then
                    for i, listener in pairs(listeners) do
                        box.space.sharding_change:insert({
                            box.sequence.sharding_change:next(),
                            listener,
                            table,
                            action,
                            tuple:tomap({names_only = true }),
                            context
                        })
                    end
                end
                box.commit()
                return tuple
            LUA,
            compact('table', 'action', 'params', 'listeners', 'context')
        );

        return (object) $this->mapper->getSpace($table)->getInstance($result);
    }

    public function delete(string|object $table, array|int|null|string $id = null): ?object
    {
        $listeners = $this->getListeners($table);
        if (!count($listeners)) {
            return $this->mapper->delete($table, is_array($id) ? $id : ['id' => $id]);
        }

        return $this->processLuaResult(
            table: $table,
            action: 'delete',
            params: is_array($id) ? [$id['id']] : [$id],
            query: 'local tuple = box.space[table]:delete(params)',
        );
    }

    public function find(string $table, array $query = []): array
    {
        return $this->mapper->find($table, $query);
    }

    public function findOne(string $table, array $query): ?object
    {
        return $this->mapper->findOne($table, $query);
    }

    public function findOrCreate(string $table, array $query, array $data = []): object
    {
        if (!count($this->getListeners($table))) {
            return $this->mapper->findOrCreate($table, $query, $data);
        }

        $index = $this->mapper->getSpace($table)->castIndex(array_keys($query));
        $select = [];
        foreach ($index['fields'] as $field) {
            if (array_key_exists($field, $query)) {
                $select[] = $query[$field];
            } else {
                break;
            }
        }

        return $this->processLuaResult(
            table: $table,
            action: 'create',
            params: [
                $index['iid'],
                $select,
                $this->mapper->getSpace($table)->getTuple($data)
            ],
            query: <<<QUERY
                local tuples = box.space[table].index[params[1]]:select(params[2], {limit=1})
                local tuple = tuples[1]
                if tuple == nil then
                    tuple = box.space[table]:insert(params[3])
                else
                    register_changes = false
                end
            QUERY,
        );
    }

    public function findOrFail(string $table, array $query): ?object
    {
        $row = $this->findOne($table, $query);
        if (!$row) {
            throw new Exception('No ' . $table . ' found');
        }
        return $row;
    }

    public function getDsn(): string
    {
        return $this->dsn;
    }

    public function getMapper(): Mapper
    {
        return $this->mapper;
    }

    public function getTarantoolType(string $type): string
    {
        return match ($type) {
            'int' => 'unsigned',
            'string' => 'string',
            'array' => '*',
            default => throw new Exception('Invalid type'),
        };
    }

    public function getUsage(): int
    {
        return $this->mapper->evaluate("return box.slab.info().items_size")[0];
    }

    public function hasTable(string $table): bool
    {
        return $this->mapper->hasSpace($table);
    }

    public function syncSchema(Database $database, string $segment): void
    {
        $bootstrap = [];
        foreach ($database->schema->getSegmentByName($segment, create: false)->getModels() as $model) {
            if (!$this->mapper->hasSpace($model->table) && is_a($model->class, Bootstrap::class, true)) {
                $bootstrap[] = $model->class;
            }
            $this->syncModel($model);
        }

        foreach ($bootstrap as $class) {
            $class::bootstrap($database);
        }
    }

    public function syncModel(Model $model)
    {
        $present = $this->mapper->hasSpace($model->table);
        if ($present) {
            $space = $this->mapper->getSpace($model->table);
        } else {
            $space = $this->mapper->createSpace($model->table, [
                'if_not_exists' => true,
            ]);
        }

        foreach ($model->getProperties() as $property) {
            if (in_array($property->name, $space->getFields())) {
                continue;
            }
            $space->addProperty($property->name, $this->getTarantoolType($property->type));
        }

        foreach ($model->getIndexes() as $index) {
            $space->addIndex($index->fields, [
                'if_not_exists' => true,
                'name' => $index->name,
                'unique' => $index->unique,
            ]);
        }
    }

    public function reset(): self
    {
        $this->mapper->dropUserSpaces();
        return $this;
    }

    public function update(string|object $table, array|int|string $id, ?array $data = null): ?object
    {
        if (!count($this->getListeners($table))) {
            $operations = null;
            foreach ($data as $k => $v) {
                if ($operations instanceof Operations) {
                    $operations = $operations->andSet($k, $v);
                } else {
                    $operations = Operations::set($k, $v);
                }
            }

            $changes = $this->mapper->client->getSpace($table)->update([$id], $operations);
            return count($changes) ? (object) $changes[0] : null;
        }

        $operations = [];
        foreach ($data as $k => $v) {
            $operations[] = ['=', $k, $v];
        }

        return $this->processLuaResult(
            table: $table,
            action: 'update',
            params: [
                $id,
                $operations,
            ],
            query: 'local tuple = box.space[table]:update(params[1], params[2])',
        );
    }


    public function ackChanges(array $changes): void
    {
        array_map(fn ($change) => $this->mapper->delete(Change::getSpaceName(), $change), $changes);
    }

    public function getChanges(string $listener = '', int $limit = 100): array
    {
        if (!$this->hasTable(Subscription::getSpaceName())) {
            return [];
        }
        if ($listener) {
            $criteria = Criteria::index('listener')->andKey([$listener]);
        } else {
            $criteria = Criteria::allIterator();
        }

        return $this->mapper->find(Change::getSpaceName(), $criteria->andLimit($limit));
    }

    public function registerChanges(string $table, string $listener): void
    {
        if (!$this->hasTable(Subscription::getSpaceName())) {
            $this->syncModel(new Model(Change::class, Change::getSpaceName()));
            $this->syncModel(new Model(Subscription::class, Subscription::getSpaceName()));
        }

        $this->mapper->create(Subscription::getSpaceName(), [
            'listener' => $listener,
            'table' => $table,
        ]);
    }

    public function getListeners(string $table): array
    {
        if (!$this->hasTable(Subscription::getSpaceName())) {
            return [];
        }

        $listeners = [];
        foreach ($this->find(Subscription::getSpaceName()) as $subscription) {
            if (in_array($subscription->table, [$table, '*'])) {
                $listeners[$subscription->listener] = $subscription->listener;
            }
        }

        return array_values($listeners);
    }

    public function hasListeners(string $table): bool
    {
        return count($this->find(Subscription::getSpaceName(), ['table' => $table])) > 0;
    }

    public function setContext(array $context): void
    {
        $this->context = $context;
    }
}
