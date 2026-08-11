<?php

namespace Dovstone\MoSQL\Document;

use Doctrine\DBAL\Connection;
use Dovstone\MoSQL\Schema\SchemaManager;
use Dovstone\MoSQL\Exception\DatabaseException;
use Dovstone\MoSQL\Exception\DocumentNotFoundException;

class DocumentManager
{
    private Connection $connection;
    private SchemaManager $schemaManager;

    public function __construct(Connection $connection, SchemaManager $schemaManager)
    {
        $this->connection = $connection;
        $this->schemaManager = $schemaManager;
    }

    public function insert(array $document): int
    {
        try {
            $this->schemaManager->adaptSchema($document);
            $data = $this->normalize($document);
            $this->connection->insert($this->schemaManager->getTableName(), $data);
            return (int)$this->connection->lastInsertId();
        } catch (\Exception $e) {
            throw new DatabaseException("Insert failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function update(array $data, array $conditions): int
    {
        if (empty($conditions)) {
            throw new \InvalidArgumentException("Conditions cannot be empty for update");
        }

        try {
            $this->schemaManager->adaptSchema($data);
            $data = $this->normalize($data);

            $qb = $this->connection->createQueryBuilder();
            $qb->update($this->schemaManager->getTableName());

            foreach ($data as $field => $value) {
                if (!in_array($field, ['id', 'uid'])) {
                    $qb->set($field, ":$field");
                    $qb->setParameter($field, $value);
                }
            }

            $qb->set('updated_at', ':updated_at')
                ->setParameter('updated_at', date('Y-m-d H:i:s'));

            foreach ($conditions as $field => $value) {
                $qb->andWhere("$field = :cond_$field")
                    ->setParameter("cond_$field", $value);
            }

            return $qb->executeStatement();
        } catch (\Exception $e) {
            throw new DatabaseException("Update failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function delete(array $conditions): int
    {
        if (empty($conditions)) {
            throw new \InvalidArgumentException("Conditions cannot be empty for delete");
        }

        try {
            $qb = $this->connection->createQueryBuilder();
            $qb->delete($this->schemaManager->getTableName());

            foreach ($conditions as $field => $value) {
                $qb->andWhere("$field = :$field")
                    ->setParameter($field, $value);
            }

            return $qb->executeStatement();
        } catch (\Exception $e) {
            throw new DatabaseException("Delete failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function findOne(array $conditions): ?array
    {
        try {
            $qb = $this->connection->createQueryBuilder();
            $qb->select('*')
                ->from($this->schemaManager->getTableName())
                ->setMaxResults(1);

            foreach ($conditions as $field => $value) {
                $qb->andWhere("$field = :$field")
                    ->setParameter($field, $value);
            }

            $result = $qb->fetchAssociative();
            return $result ? $this->hydrate($result) : null;
        } catch (\Exception $e) {
            throw new DatabaseException("Find failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function findMany(array $conditions, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        try {
            $qb = $this->connection->createQueryBuilder();
            $qb->select('*')
                ->from($this->schemaManager->getTableName());

            foreach ($conditions as $field => $value) {
                if (is_array($value)) {
                    $qb->andWhere("$field IN (:" . $field . ")")
                        ->setParameter($field, $value, Connection::PARAM_STR_ARRAY);
                } else {
                    $qb->andWhere("$field = :$field")
                        ->setParameter($field, $value);
                }
            }

            if ($orderBy) {
                foreach ($orderBy as $field => $direction) {
                    $qb->addOrderBy($field, $direction);
                }
            }

            if ($limit !== null) {
                $qb->setMaxResults($limit);
            }
            if ($offset !== null) {
                $qb->setFirstResult($offset);
            }

            $results = $qb->fetchAllAssociative();
            return $this->hydrateAll($results);
        } catch (\Exception $e) {
            throw new DatabaseException("Find failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function count(array $conditions): int
    {
        try {
            $qb = $this->connection->createQueryBuilder();
            $qb->select('COUNT(*) as count')
                ->from($this->schemaManager->getTableName());

            foreach ($conditions as $field => $value) {
                if (is_array($value)) {
                    $qb->andWhere("$field IN (:" . $field . ")")
                        ->setParameter($field, $value, Connection::PARAM_STR_ARRAY);
                } else {
                    $qb->andWhere("$field = :$field")
                        ->setParameter($field, $value);
                }
            }

            $result = $qb->fetchAssociative();
            return (int)($result['count'] ?? 0);
        } catch (\Exception $e) {
            throw new DatabaseException("Count failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function hydrate(array $row): array
    {
        $schema = $this->schemaManager->getSchema();
        $result = [];

        foreach ($row as $field => $value) {
            if ($value === null) {
                $result[$field] = null;
                continue;
            }

            $type = $schema[$field]['type'] ?? null;

            $result[$field] = match ($type) {
                'json' => is_string($value) ? json_decode($value, true) : $value,
                'integer' => (int)$value,
                'float' => (float)$value,
                'boolean' => (bool)$value,
                'datetime' => $value,
                default => $value,
            };
        }

        return $result;
    }

    public function hydrateAll(array $rows): array
    {
        return array_map([$this, 'hydrate'], $rows);
    }

    private function normalize(array $document): array
    {
        $schema = $this->schemaManager->getSchema();
        $data = [];

        foreach ($document as $field => $value) {
            if (in_array($field, ['id', 'uid', 'created_at', 'updated_at'])) {
                $data[$field] = $value;
                continue;
            }

            if (isset($schema[$field])) {
                $data[$field] = $this->castToSqlType($value, $schema[$field]['type']);
            }
        }

        return $data;
    }

    private function castToSqlType($value, string $sqlType)
    {
        return match ($sqlType) {
            'json' => is_string($value) ? $value : json_encode($value),
            'integer' => (int)$value,
            'float' => (float)$value,
            'boolean' => (int)(bool)$value,
            'datetime' => is_string($value) ? $value : date('Y-m-d H:i:s', $value),
            default => (string)$value,
        };
    }

    public function truncate(): void
    {
        try {
            $this->connection->executeStatement("TRUNCATE TABLE {$this->schemaManager->getTableName()}");
        } catch (\Exception $e) {
            throw new DatabaseException("Truncate failed: " . $e->getMessage(), 0, $e);
        }
    }
}
