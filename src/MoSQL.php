<?php

namespace Dovstone\MoSQL;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Dovstone\MoSQL\Cache\CacheManager;
use Dovstone\MoSQL\Cache\ArrayCache;
use Dovstone\MoSQL\Query\QueryBuilder;
use Dovstone\MoSQL\Schema\SchemaManager;
use Dovstone\MoSQL\Document\DocumentManager;
use Dovstone\MoSQL\Uid\UidGenerator;
use Dovstone\MoSQL\Exception\DocumentNotFoundException;
use Dovstone\MoSQL\Exception\DuplicateException;
use Dovstone\MoSQL\Exception\InvalidArgumentException;
use Dovstone\MoSQL\Exception\DatabaseException;

class MoSQL
{
    private Connection $connection;
    private string $collection;
    private QueryBuilder $queryBuilder;
    private SchemaManager $schemaManager;
    private DocumentManager $documentManager;
    private CacheManager $cacheManager;
    private UidGenerator $uidGenerator;
    private array $options;
    private array $uidCache = [];

    public function __construct(string $collection, array $dbParams = [], array $options = [])
    {
        $this->collection = $collection;
        $this->options = array_merge([
            'uid_length' => 8,
            'table_prefix' => '',
            'auto_create_schema' => true,
            'cache_enabled' => false,
            'cache_ttl' => 3600,
        ], $options);

        $this->connection = DriverManager::getConnection($dbParams);
        $this->schemaManager = new SchemaManager($this->connection, $collection, $this->options);
        $this->documentManager = new DocumentManager($this->connection, $this->schemaManager);
        $this->queryBuilder = new QueryBuilder($this->connection, $this->schemaManager);
        $this->uidGenerator = new UidGenerator($this->options['uid_length']);
        $this->cacheManager = new CacheManager(
            new ArrayCache(),
            $this->options['cache_enabled'],
            $this->options['cache_ttl']
        );

        $this->schemaManager->ensureTableExists();
    }

    // ===== REQUÊTES =====

    public function where(string $field, string $operator, mixed $value): self
    {
        $this->queryBuilder->where($field, $operator, $value);
        return $this;
    }

    public function orWhere(string $field, string $operator, mixed $value): self
    {
        $this->queryBuilder->orWhere($field, $operator, $value);
        return $this;
    }

    public function andWhere(callable $callback): self
    {
        $this->queryBuilder->andWhere($callback);
        return $this;
    }

    public function orWhereGroup(callable $callback): self
    {
        $this->queryBuilder->orWhereGroup($callback);
        return $this;
    }

    public function whereIn(string $field, array $values): self
    {
        $this->queryBuilder->whereIn($field, $values);
        return $this;
    }

    public function whereNotIn(string $field, array $values): self
    {
        $this->queryBuilder->whereNotIn($field, $values);
        return $this;
    }

    public function whereLike(string $field, string $pattern): self
    {
        $this->queryBuilder->whereLike($field, $pattern);
        return $this;
    }

    public function whereBetween(string $field, $min, $max): self
    {
        $this->queryBuilder->whereBetween($field, $min, $max);
        return $this;
    }

    public function whereNull(string $field): self
    {
        $this->queryBuilder->whereNull($field);
        return $this;
    }

    public function whereNotNull(string $field): self
    {
        $this->queryBuilder->whereNotNull($field);
        return $this;
    }

    public function select(array $fields): self
    {
        $this->queryBuilder->select($fields);
        return $this;
    }

    public function orderBy(string $field, string $direction = 'ASC'): self
    {
        $this->queryBuilder->orderBy($field, $direction);
        return $this;
    }

    public function limit(int $limit, ?int $offset = null): self
    {
        $this->queryBuilder->limit($limit, $offset);
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->queryBuilder->offset($offset);
        return $this;
    }

    public function join(string $collection, string $localField, string $operator, string $foreignField, string $type = 'inner'): self
    {
        $this->queryBuilder->join($collection, $localField, $operator, $foreignField, $type);
        return $this;
    }

    public function leftJoin(string $collection, string $localField, string $operator, string $foreignField): self
    {
        return $this->join($collection, $localField, $operator, $foreignField, 'left');
    }

    public function rightJoin(string $collection, string $localField, string $operator, string $foreignField): self
    {
        return $this->join($collection, $localField, $operator, $foreignField, 'right');
    }

    // ===== EXÉCUTION =====

    public function find(): array
    {
        $cacheKey = $this->buildCacheKey();
        $cached = $this->cacheManager->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $qb = $this->queryBuilder->build();
        $results = $qb->fetchAllAssociative();
        $results = $this->documentManager->hydrateAll($results);

        $this->cacheManager->set($cacheKey, $results);
        return $results;
    }

    public function findOne(): ?array
    {
        $this->limit(1);
        $results = $this->find();
        return $results[0] ?? null;
    }

    public function count(): int
    {
        $qb = $this->queryBuilder->build();
        $qb->select('COUNT(*) as count');
        $result = $qb->fetchAssociative();
        return (int)($result['count'] ?? 0);
    }

    // ===== FIND MÉTHODES =====

    public function find(string $uid): ?array
    {
        $cached = $this->cacheManager->get("document_uid_{$uid}");
        if ($cached !== null) {
            return $cached;
        }

        $this->reset();
        $result = $this->where('uid', '=', $uid)->findOne();
        if ($result) {
            $this->cacheManager->set("document_uid_{$uid}", $result);
        }
        return $result;
    }

    public function findOrFail(string $uid): array
    {
        $result = $this->find($uid);
        if ($result === null) {
            throw new DocumentNotFoundException("Document with UID '{$uid}' not found");
        }
        return $result;
    }

    public function findById(int $id): ?array
    {
        $cached = $this->cacheManager->get("document_id_{$id}");
        if ($cached !== null) {
            return $cached;
        }

        $this->reset();
        $result = $this->where('id', '=', $id)->findOne();
        if ($result) {
            $this->cacheManager->set("document_id_{$id}", $result);
        }
        return $result;
    }

    public function findByIdOrFail(int $id): array
    {
        $result = $this->findById($id);
        if ($result === null) {
            throw new DocumentNotFoundException("Document with ID '{$id}' not found");
        }
        return $result;
    }

    public function findAll(): array
    {
        $this->reset();
        return $this->find();
    }

    public function first(): ?array
    {
        $this->reset();
        $this->limit(1);
        $results = $this->find();
        return $results[0] ?? null;
    }

    public function last(): ?array
    {
        $this->reset();
        $this->orderBy('id', 'DESC')->limit(1);
        $results = $this->find();
        return $results[0] ?? null;
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        $this->reset();

        foreach ($criteria as $field => $value) {
            if (is_array($value)) {
                $this->whereIn($field, $value);
            } else {
                $this->where($field, '=', $value);
            }
        }

        if ($orderBy) {
            foreach ($orderBy as $field => $direction) {
                $this->orderBy($field, $direction);
            }
        }

        if ($limit !== null) {
            $this->limit($limit, $offset);
        }

        return $this->find();
    }

    public function findOneBy(array $criteria): ?array
    {
        $this->reset();

        foreach ($criteria as $field => $value) {
            $this->where($field, '=', $value);
        }

        return $this->findOne();
    }

    public function findIdByUid(string $uid): ?int
    {
        if (isset($this->uidCache[$uid])) {
            return $this->uidCache[$uid];
        }

        $this->reset();
        $result = $this->where('uid', '=', $uid)->select(['id'])->findOne();
        if ($result) {
            $this->uidCache[$uid] = (int)$result['id'];
            return (int)$result['id'];
        }
        return null;
    }

    public function findUidById(int $id): ?string
    {
        $this->reset();
        $result = $this->where('id', '=', $id)->select(['uid'])->findOne();
        return $result['uid'] ?? null;
    }

    // ===== MÉTHODES MAGIQUES =====

    public function __call(string $method, array $arguments)
    {
        if (str_starts_with($method, 'findBy') && strlen($method) > 6) {
            return $this->handleFindByMagic($method, $arguments);
        }

        if (str_starts_with($method, 'findOneBy') && strlen($method) > 9) {
            return $this->handleFindOneByMagic($method, $arguments);
        }

        throw new \BadMethodCallException("Method '{$method}' does not exist");
    }

    private function handleFindByMagic(string $method, array $arguments): array
    {
        $field = substr($method, 6);
        $operator = '=';
        $operators = ['GreaterThan', 'LessThan', 'GreaterThanOrEqual', 'LessThanOrEqual', 'Like', 'NotLike', 'In', 'NotIn'];

        foreach ($operators as $op) {
            if (str_ends_with($field, $op)) {
                $field = substr($field, 0, -strlen($op));
                $operator = match ($op) {
                    'GreaterThan' => '>',
                    'LessThan' => '<',
                    'GreaterThanOrEqual' => '>=',
                    'LessThanOrEqual' => '<=',
                    'Like' => 'LIKE',
                    'NotLike' => 'NOT LIKE',
                    'In' => 'IN',
                    'NotIn' => 'NOT IN',
                    default => '=',
                };
                break;
            }
        }

        $this->reset();
        $this->where($field, $operator, $arguments[0]);

        if (isset($arguments[1]) && is_array($arguments[1])) {
            foreach ($arguments[1] as $f => $d) {
                $this->orderBy($f, $d);
            }
        }

        if (isset($arguments[2])) {
            $this->limit($arguments[2]);
        }

        return $this->find();
    }

    private function handleFindOneByMagic(string $method, array $arguments): ?array
    {
        $field = substr($method, 9);
        $operator = '=';
        $operators = ['GreaterThan', 'LessThan', 'GreaterThanOrEqual', 'LessThanOrEqual', 'Like', 'NotLike'];

        foreach ($operators as $op) {
            if (str_ends_with($field, $op)) {
                $field = substr($field, 0, -strlen($op));
                $operator = match ($op) {
                    'GreaterThan' => '>',
                    'LessThan' => '<',
                    'GreaterThanOrEqual' => '>=',
                    'LessThanOrEqual' => '<=',
                    'Like' => 'LIKE',
                    'NotLike' => 'NOT LIKE',
                    default => '=',
                };
                break;
            }
        }

        $this->reset();
        $this->where($field, $operator, $arguments[0]);
        return $this->findOne();
    }

    // ===== CRUD =====

    public function insert(array $document): string
    {
        if (!isset($document['uid'])) {
            $document['uid'] = $this->uidGenerator->generate();
        }

        $existing = $this->findIdByUid($document['uid']);
        if ($existing !== null) {
            throw new DuplicateException("UID '{$document['uid']}' already exists");
        }

        try {
            $id = $this->documentManager->insert($document);
            $this->uidCache[$document['uid']] = $id;
            $this->cacheManager->clear();
            return $document['uid'];
        } catch (\Exception $e) {
            throw new DatabaseException("Insert failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function insertMany(array $documents): array
    {
        $this->connection->beginTransaction();
        $uids = [];

        try {
            foreach ($documents as $doc) {
                $uids[] = $this->insert($doc);
            }
            $this->connection->commit();
            return $uids;
        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function update(array $data): int
    {
        $conditions = [];
        foreach ($this->queryBuilder->getConditions() as $cond) {
            $conditions[$cond['field']] = $cond['value'];
        }

        if (empty($conditions)) {
            throw new InvalidArgumentException("No conditions provided for update operation");
        }

        try {
            $result = $this->documentManager->update($data, $conditions);
            $this->cacheManager->clear();
            return $result;
        } catch (\Exception $e) {
            throw new DatabaseException("Update failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function updateByUid(string $uid, array $data): int
    {
        $this->reset();
        $this->where('uid', '=', $uid);
        return $this->update($data);
    }

    public function updateById(int $id, array $data): int
    {
        $this->reset();
        $this->where('id', '=', $id);
        return $this->update($data);
    }

    public function upsert(array $data): string
    {
        if (isset($data['uid'])) {
            $existing = $this->find($data['uid']);
            if ($existing) {
                $this->updateByUid($data['uid'], $data);
                return $data['uid'];
            }
        }
        return $this->insert($data);
    }

    public function delete(): int
    {
        $conditions = [];
        foreach ($this->queryBuilder->getConditions() as $cond) {
            $conditions[$cond['field']] = $cond['value'];
        }

        if (empty($conditions)) {
            throw new InvalidArgumentException("No conditions provided for delete operation");
        }

        try {
            $result = $this->documentManager->delete($conditions);
            $this->cacheManager->clear();
            return $result;
        } catch (\Exception $e) {
            throw new DatabaseException("Delete failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function deleteByUid(string $uid): int
    {
        $this->reset();
        $this->where('uid', '=', $uid);
        return $this->delete();
    }

    public function deleteById(int $id): int
    {
        $this->reset();
        $this->where('id', '=', $id);
        return $this->delete();
    }

    // ===== CACHE =====

    public function cache(bool $enabled = true, ?int $ttl = null): self
    {
        if ($enabled) {
            $this->cacheManager->enable();
        } else {
            $this->cacheManager->disable();
        }
        if ($ttl !== null) {
            $this->cacheManager->setDefaultTTL($ttl);
        }
        return $this;
    }

    public function invalidate(string|int $identifier): self
    {
        $this->cacheManager->invalidateDocument($identifier);
        return $this;
    }

    public function invalidateAll(): self
    {
        $this->cacheManager->clear();
        return $this;
    }

    // ===== UTILITAIRES =====

    public function reset(): self
    {
        $this->queryBuilder->reset();
        return $this;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getSchema(): array
    {
        return $this->schemaManager->getSchema();
    }

    public function truncate(): void
    {
        $this->documentManager->truncate();
        $this->cacheManager->clear();
    }

    public function drop(): void
    {
        $this->schemaManager->dropTable();
        $this->cacheManager->clear();
    }

    public function paginate(int $perPage = 10, int $page = 1): array
    {
        $offset = ($page - 1) * $perPage;
        $this->limit($perPage, $offset);

        $items = $this->find();
        $total = $this->count();

        return [
            'items' => $items,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => ceil($total / $perPage),
            'from' => $offset + 1,
            'to' => $offset + count($items),
        ];
    }

    public function exists(array $criteria): bool
    {
        $this->reset();
        foreach ($criteria as $field => $value) {
            $this->where($field, '=', $value);
        }
        return $this->count() > 0;
    }

    public function distinct(string $field): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select("DISTINCT `$field`")
            ->from($this->schemaManager->getTableName());
        $results = $qb->fetchAllAssociative();
        return array_column($results, $field);
    }

    public function pluck(string $value, ?string $key = null): array
    {
        $results = $this->find();
        if ($key === null) {
            return array_column($results, $value);
        }

        $plucked = [];
        foreach ($results as $row) {
            $plucked[$row[$key] ?? ''] = $row[$value] ?? null;
        }
        return $plucked;
    }

    public function pairs(string $keyField, string $valueField): array
    {
        return $this->pluck($valueField, $keyField);
    }

    private function buildCacheKey(): string
    {
        $qb = $this->queryBuilder;
        return md5(
            $this->collection .
                json_encode($qb->getConditions()) .
                json_encode($qb->getProjection()) .
                json_encode($qb->getOrderBy()) .
                ($qb->getLimit() ?? 'null') .
                ($qb->getOffset() ?? 'null') .
                json_encode($qb->getJoins()) .
                json_encode($qb->getGroups())
        );
    }
}
