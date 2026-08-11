<?php

namespace Dovstone\MoSQL\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder as DBALQueryBuilder;
use Dovstone\MoSQL\Schema\SchemaManager;
use Dovstone\MoSQL\Exception\InvalidArgumentException;

class QueryBuilder
{
    private Connection $connection;
    private SchemaManager $schemaManager;
    private array $conditions = [];
    private array $projection = [];
    private array $orderBy = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private array $joins = [];
    private string $currentGroup = 'AND';
    private array $groups = [];

    public function __construct(Connection $connection, SchemaManager $schemaManager)
    {
        $this->connection = $connection;
        $this->schemaManager = $schemaManager;
    }

    // ===== CONDITIONS =====

    public function where(string $field, string $operator, mixed $value): self
    {
        $this->validateOperator($operator);
        $this->conditions[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
            'group' => $this->currentGroup,
        ];
        return $this;
    }

    public function orWhere(string $field, string $operator, mixed $value): self
    {
        $this->validateOperator($operator);
        $this->conditions[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
            'group' => 'OR',
        ];
        return $this;
    }

    public function andWhere(callable $callback): self
    {
        $previousGroup = $this->currentGroup;
        $this->currentGroup = 'AND';

        $sub = new self($this->connection, $this->schemaManager);
        $callback($sub);
        $subConditions = $sub->getConditions();

        if (!empty($subConditions)) {
            $this->groups[] = [
                'type' => 'AND',
                'conditions' => $subConditions,
            ];
        }

        $this->currentGroup = $previousGroup;
        return $this;
    }

    public function orWhereGroup(callable $callback): self
    {
        $previousGroup = $this->currentGroup;
        $this->currentGroup = 'OR';

        $sub = new self($this->connection, $this->schemaManager);
        $callback($sub);
        $subConditions = $sub->getConditions();

        if (!empty($subConditions)) {
            $this->groups[] = [
                'type' => 'OR',
                'conditions' => $subConditions,
            ];
        }

        $this->currentGroup = $previousGroup;
        return $this;
    }

    public function whereIn(string $field, array $values): self
    {
        if (empty($values)) {
            throw new InvalidArgumentException("Values cannot be empty for IN operator");
        }
        return $this->where($field, 'IN', $values);
    }

    public function whereNotIn(string $field, array $values): self
    {
        if (empty($values)) {
            throw new InvalidArgumentException("Values cannot be empty for NOT IN operator");
        }
        return $this->where($field, 'NOT IN', $values);
    }

    public function whereLike(string $field, string $pattern): self
    {
        return $this->where($field, 'LIKE', $pattern);
    }

    public function whereBetween(string $field, $min, $max): self
    {
        if ($min === null || $max === null) {
            throw new InvalidArgumentException("Min and max values cannot be null for BETWEEN");
        }
        return $this->where($field, 'BETWEEN', [$min, $max]);
    }

    public function whereNull(string $field): self
    {
        return $this->where($field, 'IS NULL', null);
    }

    public function whereNotNull(string $field): self
    {
        return $this->where($field, 'IS NOT NULL', null);
    }

    // ===== TRI ET LIMITE =====

    public function select(array $fields): self
    {
        $this->projection = $fields;
        return $this;
    }

    public function orderBy(string $field, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'])) {
            throw new InvalidArgumentException("Order direction must be ASC or DESC");
        }
        $this->orderBy[] = [$field, $direction];
        return $this;
    }

    public function limit(int $limit, ?int $offset = null): self
    {
        if ($limit < 1) {
            throw new InvalidArgumentException("Limit must be at least 1");
        }
        $this->limit = $limit;
        if ($offset !== null) {
            $this->offset = $offset;
        }
        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException("Offset must be 0 or greater");
        }
        $this->offset = $offset;
        return $this;
    }

    // ===== JOINTURES =====

    public function join(string $collection, string $localField, string $operator, string $foreignField, string $type = 'inner'): self
    {
        $type = strtolower($type);
        if (!in_array($type, ['inner', 'left', 'right'])) {
            throw new InvalidArgumentException("Join type must be inner, left, or right");
        }

        $this->joins[] = [
            'collection' => $collection,
            'localField' => $localField,
            'operator' => $operator,
            'foreignField' => $foreignField,
            'type' => $type,
        ];
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

    // ===== GETTERS =====

    public function getConditions(): array
    {
        return $this->conditions;
    }

    public function getProjection(): array
    {
        return $this->projection;
    }

    public function getOrderBy(): array
    {
        return $this->orderBy;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function getOffset(): ?int
    {
        return $this->offset;
    }

    public function getJoins(): array
    {
        return $this->joins;
    }

    public function getGroups(): array
    {
        return $this->groups;
    }

    // ===== BUILD =====

    public function build(): DBALQueryBuilder
    {
        $qb = $this->connection->createQueryBuilder();
        $tableName = $this->schemaManager->getTableName();

        // SELECT
        if (empty($this->projection)) {
            $qb->select('*');
        } else {
            $qb->select(implode(', ', $this->projection));
        }

        $qb->from($tableName);

        // JOIN
        foreach ($this->joins as $join) {
            $method = $join['type'] . 'Join';
            $alias = $join['collection'];
            $joinTable = $this->schemaManager->getPrefix() . $join['collection'];
            $qb->$method(
                $alias,
                $joinTable,
                $alias,
                "{$tableName}.{$join['localField']} {$join['operator']} {$alias}.{$join['foreignField']}"
            );
        }

        // WHERE
        $this->buildWhere($qb);

        // ORDER BY
        foreach ($this->orderBy as [$field, $direction]) {
            $qb->addOrderBy($field, $direction);
        }

        // LIMIT
        if ($this->limit !== null) {
            $qb->setMaxResults($this->limit);
        }
        if ($this->offset !== null) {
            $qb->setFirstResult($this->offset);
        }

        return $qb;
    }

    private function buildWhere(DBALQueryBuilder $qb): void
    {
        $allConditions = array_merge($this->conditions, $this->groups);

        if (empty($allConditions)) {
            return;
        }

        $clauses = [];

        foreach ($allConditions as $cond) {
            if (isset($cond['type']) && in_array($cond['type'], ['AND', 'OR'])) {
                $subClauses = [];
                foreach ($cond['conditions'] as $c) {
                    $subClauses[] = $this->buildConditionString($qb, $c);
                }
                if (!empty($subClauses)) {
                    $clauses[] = '(' . implode(" {$cond['type']} ", $subClauses) . ')';
                }
            } else {
                $clauses[] = $this->buildConditionString($qb, $cond);
            }
        }

        if (!empty($clauses)) {
            $qb->andWhere(implode(' AND ', $clauses));
        }
    }

    private function buildConditionString(DBALQueryBuilder $qb, array $condition): string
    {
        $field = $condition['field'];
        $operator = $condition['operator'];
        $value = $condition['value'];
        $param = str_replace(['.', ' ', '-', '[', ']'], '_', $field) . '_' . uniqid();

        // Vérifier si le champ existe
        if (!$this->schemaManager->hasColumn($field) && !in_array($field, ['id', 'uid'])) {
            // Champ non reconnu, on le traite comme du JSON
            // On le laisse passer tel quel
        }

        return match ($operator) {
            'IN', 'NOT IN' => $this->buildInCondition($qb, $field, $operator, $value, $param),
            'BETWEEN' => $this->buildBetweenCondition($qb, $field, $value),
            'IS NULL', 'IS NOT NULL' => "{$field} {$operator}",
            'LIKE' => $this->buildLikeCondition($qb, $field, $value, $param),
            default => $this->buildSimpleCondition($qb, $field, $operator, $value, $param),
        };
    }

    private function buildSimpleCondition(DBALQueryBuilder $qb, string $field, string $operator, $value, string $param): string
    {
        $qb->setParameter($param, $value);
        return "{$field} {$operator} :{$param}";
    }

    private function buildInCondition(DBALQueryBuilder $qb, string $field, string $operator, array $values, string $param): string
    {
        $qb->setParameter($param, $values, Connection::PARAM_STR_ARRAY);
        return "{$field} {$operator} (:{$param})";
    }

    private function buildBetweenCondition(DBALQueryBuilder $qb, string $field, array $values): string
    {
        $min = 'min_' . uniqid();
        $max = 'max_' . uniqid();
        $qb->setParameter($min, $values[0]);
        $qb->setParameter($max, $values[1]);
        return "{$field} BETWEEN :{$min} AND :{$max}";
    }

    private function buildLikeCondition(DBALQueryBuilder $qb, string $field, string $pattern, string $param): string
    {
        $qb->setParameter($param, $pattern);
        return "{$field} LIKE :{$param}";
    }

    private function validateOperator(string $operator): void
    {
        $allowed = ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'IN', 'NOT IN', 'BETWEEN', 'IS NULL', 'IS NOT NULL'];
        $operator = strtoupper($operator);

        if (!in_array($operator, $allowed)) {
            throw new InvalidArgumentException("Invalid operator: {$operator}");
        }
    }

    // ===== RESET =====

    public function reset(): self
    {
        $this->conditions = [];
        $this->projection = [];
        $this->orderBy = [];
        $this->limit = null;
        $this->offset = null;
        $this->joins = [];
        $this->groups = [];
        $this->currentGroup = 'AND';
        return $this;
    }
}
