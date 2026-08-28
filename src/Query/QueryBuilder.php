<?php

namespace Dovstone\MoSQL\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder as DBALQueryBuilder;
use Dovstone\MoSQL\Schema\SchemaManager;

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
    private array $groups = [];

    private array $groupStack = ['AND'];

    private ?array $orderByField = null;
    private ?array $orderByRaw = null;
    private int $conditionCounter = 0;
    private array $groupBy = [];
    private array $having = [];
    private array $rawConditions = [];
    private array $subConditions = [];

    private ?array $schemaCache = null;
    private ?array $schemaColumnsCache = null;

    // Cache des colonnes réellement présentes en base (introspection physique),
    // distinct du schéma déclaré qui peut contenir des colonnes pas encore créées.
    private ?array $physicalColumnsCache = null;

    public function __construct(Connection $connection, SchemaManager $schemaManager)
    {
        $this->connection = $connection;
        $this->schemaManager = $schemaManager;
    }

    // ================================================================
    // MÉTHODES DE SCHÉMA
    // ================================================================

    /**
     * Colonnes "sûres" à utiliser dans une requête : intersection entre le
     * schéma déclaré (SchemaManager) et les colonnes réellement présentes
     * dans la table en base. Évite les "Column not found" quand une colonne
     * a été déclarée mais pas encore matérialisée (ex: auto-création différée
     * jusqu'au premier insert).
     */
    public function getExistingColumns(): array
    {
        if ($this->schemaColumnsCache === null) {
            $declared = array_keys($this->schemaManager->getSchema());
            $physical = $this->getPhysicalColumns();

            if (empty($physical)) {
                // Table absente ou introspection impossible : on ne peut pas
                // garantir la présence des colonnes déclarées, on renvoie donc
                // une liste vide plutôt que de risquer une erreur SQL.
                $this->schemaColumnsCache = [];
            } else {
                $this->schemaColumnsCache = array_values(array_intersect($declared, $physical));
            }
        }
        return $this->schemaColumnsCache;
    }

    /**
     * Retourne les colonnes réellement présentes dans la table, via
     * introspection DBAL. Mise en cache pour la durée de vie de l'instance.
     */
    private function getPhysicalColumns(): array
    {
        if ($this->physicalColumnsCache === null) {
            $tableName = $this->schemaManager->getTableName();

            try {
                $sm = $this->connection->createSchemaManager();
                if ($sm->tablesExist([$tableName])) {
                    $this->physicalColumnsCache = array_map(
                        static fn($col) => $col->getName(),
                        $sm->listTableColumns($tableName)
                    );
                } else {
                    $this->physicalColumnsCache = [];
                }
            } catch (\Exception $e) {
                // En cas d'échec d'introspection, on considère qu'on n'a
                // aucune garantie sur les colonnes disponibles.
                $this->physicalColumnsCache = [];
            }
        }

        return $this->physicalColumnsCache;
    }

    public function hasColumn(string $field): bool
    {
        $field = $this->normalizeField($field);
        return in_array($field, $this->getExistingColumns());
    }

    public function filterValidColumns(array $fields): array
    {
        $existing = $this->getExistingColumns();
        return array_intersect($fields, $existing);
    }

    /**
     * Invalide les caches de schéma (déclaré + physique). À appeler si l'on
     * sait que la structure de la table vient de changer (ex: après un
     * insert qui a déclenché une auto-création de colonne) et que l'on
     * réutilise la même instance de QueryBuilder pour une requête suivante.
     */
    public function refreshSchemaCache(): self
    {
        $this->schemaColumnsCache = null;
        $this->physicalColumnsCache = null;
        $this->schemaCache = null;
        return $this;
    }

    private function isExpression(string $field): bool
    {
        $expressions = ['DATE', 'MONTH', 'YEAR', 'DAY', 'HOUR', 'MINUTE', 'SOUNDEX', 'JSON', 'NOW', 'CURRENT'];
        $upper = strtoupper($field);
        foreach ($expressions as $expr) {
            if (strpos($upper, $expr) === 0) {
                return true;
            }
        }
        return false;
    }

    // ================================================================
    // CONDITIONS DE BASE
    // ================================================================

    public function where(string $field, string $operator, mixed $value): self
    {
        $field = $this->normalizeField($field);
        $operator = strtoupper($operator);

        if (!$this->isExpression($field) && !$this->hasColumn($field)) {
            return $this;
        }

        if ($this->isJsonPath($field)) {
            return $this->addJsonCondition($field, $operator, $value);
        }

        return $this->addCondition($field, $operator, $value);
    }

    public function orWhere(string $field, string $operator, mixed $value): self
    {
        $this->pushGroup('OR');
        $result = $this->where($field, $operator, $value);
        $this->popGroup();
        return $result;
    }

    public function andWhere(callable $callback): self
    {
        $this->pushGroup('AND');

        $sub = new self($this->connection, $this->schemaManager);
        $callback($sub);
        $subConditions = $sub->getConditions();

        if (!empty($subConditions)) {
            $this->groups[] = [
                'type' => 'group',
                'groupType' => 'AND',
                'conditions' => $subConditions,
            ];
        }

        $this->popGroup();
        return $this;
    }

    public function orWhereGroup(callable $callback): self
    {
        $this->pushGroup('OR');

        $sub = new self($this->connection, $this->schemaManager);
        $callback($sub);
        $subConditions = $sub->getConditions();

        if (!empty($subConditions)) {
            $this->groups[] = [
                'type' => 'group',
                'groupType' => 'OR',
                'conditions' => $subConditions,
            ];
        }

        $this->popGroup();
        return $this;
    }

    public function whereOneBy($field, $value = null): self
    {
        if (is_array($field)) {
            foreach ($field as $key => $val) {
                if ($this->hasColumn($key)) {
                    $this->where($key, '=', $val);
                }
            }
            return $this;
        }

        if ($this->hasColumn($field)) {
            $this->where($field, '=', $value);
        }

        return $this;
    }

    public function orWhereLike(string $field, string $pattern): self
    {
        return $this->orWhere($field, 'LIKE', $pattern);
    }

    public function andWhereLike(string $field, string $pattern): self
    {
        $this->pushGroup('AND');
        $this->whereLike($field, $pattern);
        $this->popGroup();
        return $this;
    }

    // ================================================================
    // CONDITIONS SPÉCIALES
    // ================================================================

    public function whereIn(string $field, array $values): self
    {
        if (empty($values)) {
            return $this;
        }

        $field = $this->normalizeField($field);

        if (!$this->hasColumn($field)) {
            return $this;
        }

        if ($this->isJsonColumn($field)) {
            return $this->addJsonInCondition($field, $values);
        }

        return $this->addCondition($field, 'IN', $values);
    }

    public function whereNotIn(string $field, array $values): self
    {
        if (empty($values)) {
            return $this;
        }

        $field = $this->normalizeField($field);

        if (!$this->hasColumn($field)) {
            return $this;
        }

        if ($this->isJsonColumn($field)) {
            foreach ($values as $value) {
                $this->whereJsonNotContains($field, $value);
            }
            return $this;
        }

        return $this->addCondition($field, 'NOT IN', $values);
    }

    public function whereLike(string $field, string $pattern): self
    {
        return $this->where($field, 'LIKE', $pattern);
    }

    public function whereBetween(string $field, mixed $min, mixed $max): self
    {
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

    // ================================================================
    // CONDITIONS JSON
    // ================================================================

    public function whereJsonContains(string $field, string $value): self
    {
        return $this->addJsonCondition($field, 'JSON_CONTAINS', $value);
    }

    public function whereJsonNotContains(string $field, string $value): self
    {
        return $this->addJsonCondition($field, 'JSON_NOT_CONTAINS', $value);
    }

    public function orWhereJsonContains(string $field, string $value): self
    {
        $this->pushGroup('OR');
        $result = $this->whereJsonContains($field, $value);
        $this->popGroup();
        return $result;
    }

    public function orWhereJsonNotContains(string $field, string $value): self
    {
        $this->pushGroup('OR');
        $result = $this->whereJsonNotContains($field, $value);
        $this->popGroup();
        return $result;
    }

    public function whereJsonContainsAny(string $field, array $values): self
    {
        if (empty($values)) {
            return $this;
        }

        $this->pushGroup('OR');
        foreach ($values as $value) {
            $this->whereJsonContains($field, $value);
        }
        $this->popGroup();
        return $this;
    }

    public function whereJsonNotContainsAny(string $field, array $values): self
    {
        if (empty($values)) {
            return $this;
        }

        $this->pushGroup('OR');
        foreach ($values as $value) {
            $this->whereJsonNotContains($field, $value);
        }
        $this->popGroup();
        return $this;
    }

    public function whereJsonContainsAll(string $field, array $values): self
    {
        foreach ($values as $value) {
            $this->whereJsonContains($field, $value);
        }
        return $this;
    }

    public function whereJsonNotContainsAll(string $field, array $values): self
    {
        foreach ($values as $value) {
            $this->whereJsonNotContains($field, $value);
        }
        return $this;
    }

    // ================================================================
    // CONDITIONS DE DATE/TEMPS
    // ================================================================

    public function whereDate(string $field, string $operator, string $date): self
    {
        return $this->where("DATE($field)", $operator, $date);
    }

    public function whereMonth(string $field, string $operator, int $month): self
    {
        return $this->where("MONTH($field)", $operator, $month);
    }

    public function whereYear(string $field, string $operator, int $year): self
    {
        return $this->where("YEAR($field)", $operator, $year);
    }

    public function whereDay(string $field, string $operator, int $day): self
    {
        return $this->where("DAY($field)", $operator, $day);
    }

    public function whereHour(string $field, string $operator, int $hour): self
    {
        return $this->where("HOUR($field)", $operator, $hour);
    }

    public function whereMinute(string $field, string $operator, int $minute): self
    {
        return $this->where("MINUTE($field)", $operator, $minute);
    }

    // ================================================================
    // CONDITIONS AVANCÉES
    // ================================================================

    public function whereRaw(string $sql, array $params = []): self
    {
        $this->rawConditions[] = [
            'type' => 'raw',
            'sql' => $sql,
            'params' => $params,
        ];
        return $this;
    }

    public function whereSub(string $field, string $operator, callable $callback): self
    {
        $subQuery = new self($this->connection, $this->schemaManager);
        $callback($subQuery);
        $subQb = $subQuery->build();
        $sql = $subQb->getSQL();
        $params = $subQb->getParameters();

        $this->subConditions[] = [
            'type' => 'sub',
            'field' => $this->normalizeField($field),
            'operator' => $operator,
            'sql' => $sql,
            'params' => $params,
        ];
        return $this;
    }

    // ================================================================
    // PROJECTION ET TRI
    // ================================================================

    public function select(array $fields): self
    {
        if (empty($fields)) {
            $this->projection = $this->getExistingColumns();
            return $this;
        }

        $existingColumns = $this->getExistingColumns();
        $validFields = [];

        foreach ($fields as $field) {
            if (in_array($field, $existingColumns)) {
                $validFields[] = $field;
            }
        }

        $required = ['uid', 'id', 'coll_name'];
        foreach ($required as $col) {
            if (in_array($col, $existingColumns) && !in_array($col, $validFields)) {
                $validFields[] = $col;
            }
        }

        $this->projection = array_unique($validFields);
        return $this;
    }

    public function orderBy(string $field, string $direction = 'ASC'): self
    {
        $field = $this->normalizeField($field);

        if (!$this->hasColumn($field)) {
            return $this;
        }

        $this->orderBy[] = [$field, strtoupper($direction)];
        return $this;
    }

    public function orderByField(string $field, array $values, string $direction = 'ASC'): self
    {
        if (empty($values)) {
            return $this;
        }

        $field = $this->normalizeField($field);

        if (!$this->hasColumn($field)) {
            return $this;
        }

        $this->orderByField = [
            'field' => $field,
            'values' => $values,
            'direction' => strtoupper($direction),
        ];

        return $this;
    }

    public function orderByRaw(string $rawSql, array $params = []): self
    {
        $this->orderByRaw = ['sql' => $rawSql, 'params' => $params];
        return $this;
    }

    public function limit(int $limit, ?int $offset = null): self
    {
        $this->limit = max(0, $limit);
        if ($offset !== null) {
            $this->offset = max(0, $offset);
        }
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);
        return $this;
    }

    public function groupBy(string|array $fields): self
    {
        if (is_string($fields)) {
            $fields = [$fields];
        }

        foreach ($fields as $field) {
            $field = $this->normalizeField($field);
            if ($this->hasColumn($field)) {
                $this->groupBy[] = $field;
            }
        }
        return $this;
    }

    public function having(string $field, string $operator, mixed $value): self
    {
        $field = $this->normalizeField($field);

        if (!$this->hasColumn($field)) {
            return $this;
        }

        $this->having[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
        ];
        return $this;
    }

    // ================================================================
    // JOINTURES
    // ================================================================

    public function join(string $collection, string $localField, string $operator, string $foreignField, string $type = 'inner'): self
    {
        $this->joins[] = [
            'collection' => $collection,
            'localField' => $this->normalizeJoinField($localField),
            'operator' => $operator,
            'foreignField' => $this->normalizeJoinField($foreignField),
            'type' => $type,
            'complex' => false,
        ];
        return $this;
    }

    public function leftJoin(string $collection, string $localField, string $operator, string $foreignField): self
    {
        return $this->join($collection, $localField, $operator, $foreignField, 'left');
    }

    public function innerJoin(string $collection, string $localField, string $operator, string $foreignField): self
    {
        return $this->join($collection, $localField, $operator, $foreignField, 'inner');
    }

    public function rightJoin(string $collection, string $localField, string $operator, string $foreignField): self
    {
        return $this->join($collection, $localField, $operator, $foreignField, 'right');
    }

    public function joinComplex(string $collection, string $onClause, string $type = 'inner', array $params = []): self
    {
        $this->joins[] = [
            'collection' => $collection,
            'onClause' => $onClause,
            'type' => $type,
            'params' => $params,
            'complex' => true,
        ];
        return $this;
    }

    // ================================================================
    // MÉTHODES DE RECHERCHE
    // ================================================================

    private function extractWords(string $query): array
    {
        $query = trim($query);
        $query = preg_replace('/\s+/', ' ', $query);
        $query = preg_replace('/[^\w\s\-@.]/u', '', $query);

        $words = explode(' ', $query);
        $words = array_filter($words, function ($word) {
            return strlen($word) >= 2;
        });

        return array_values(array_unique($words));
    }

    private function getNumericFields(): array
    {
        $schema = $this->schemaManager->getSchema();
        $fields = [];
        $types = ['int', 'integer', 'float', 'decimal', 'double', 'numeric'];

        foreach ($schema as $field => $config) {
            if (in_array($config['type'] ?? 'string', $types) && $this->hasColumn($field)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    private function getDateFields(): array
    {
        $schema = $this->schemaManager->getSchema();
        $fields = [];
        $types = ['date', 'datetime', 'timestamp', 'time', 'year'];

        foreach ($schema as $field => $config) {
            if (in_array($config['type'] ?? 'string', $types) && $this->hasColumn($field)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    private function getTextFields(): array
    {
        $schema = $this->schemaManager->getSchema();
        $fields = [];
        $types = ['string', 'text', 'varchar', 'char', 'json'];

        foreach ($schema as $field => $config) {
            if (in_array($config['type'] ?? 'string', $types) && $this->hasColumn($field)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    public function searchWord(string $word, array $fields): self
    {
        if (empty($word) || empty($fields)) {
            return $this;
        }

        $this->pushGroup('OR');

        foreach ($fields as $field) {
            if ($this->hasColumn($field)) {
                $this->whereLike($field, '%' . addcslashes($word, '%_\\') . '%');
            }
        }

        $this->popGroup();
        return $this;
    }

    public function searchWordOr(string $word, array $fields): self
    {
        return $this->searchWord($word, $fields);
    }

    public function searchByWords(array $words, array $fields): self
    {
        if (empty($words) || empty($fields)) {
            return $this;
        }

        $this->pushGroup('OR');

        foreach ($words as $word) {
            $this->searchWord($word, $fields);
        }

        $this->popGroup();
        return $this;
    }

    public function searchStrictByWords(array $words, array $fields): self
    {
        if (empty($words) || empty($fields)) {
            return $this;
        }

        foreach ($words as $word) {
            $this->searchWord($word, $fields);
        }

        return $this;
    }

    public function searchExact(string $query, array $fields): self
    {
        if (empty($query) || empty($fields)) {
            return $this;
        }

        $this->pushGroup('OR');

        foreach ($fields as $field) {
            if ($this->hasColumn($field)) {
                $this->whereLike($field, '%' . addcslashes($query, '%_\\') . '%');
            }
        }

        $this->popGroup();
        return $this;
    }

    public function searchFuzzy(string $query, array $fields, float $threshold = 0.7): self
    {
        $words = $this->extractWords($query);

        if (empty($words) || empty($fields)) {
            return $this;
        }

        $this->pushGroup('OR');

        foreach ($words as $word) {
            $this->pushGroup('OR');

            foreach ($fields as $field) {
                if ($this->hasColumn($field)) {
                    $this->whereRaw("SOUNDEX($field) = SOUNDEX(?)", [$word]);
                    $this->orWhereLike($field, '%' . addcslashes($word, '%_\\') . '%');
                }
            }

            $this->popGroup();
        }

        $this->popGroup();
        return $this;
    }

    public function searchWithScore(string $query, array $fields): self
    {
        $words = $this->extractWords($query);

        if (empty($words) || empty($fields)) {
            return $this;
        }

        $scoreParts = [];
        $params = [];
        $paramCount = 0;

        foreach ($fields as $field => $weight) {
            if (!$this->hasColumn($field)) {
                continue;
            }

            foreach ($words as $word) {
                $paramName = "search_{$paramCount}";
                $scoreParts[] = "CASE 
                    WHEN $field LIKE :{$paramName} 
                    THEN $weight 
                    ELSE 0 
                END";
                $params[$paramName] = '%' . addcslashes($word, '%_\\') . '%';
                $paramCount++;
            }
        }

        if (empty($scoreParts)) {
            return $this;
        }

        $scoreSql = '(' . implode(' + ', $scoreParts) . ')';

        $this->projection[] = "$scoreSql as score";
        $this->orderBy('score', 'DESC');

        foreach ($params as $key => $value) {
            $this->rawConditions[] = [
                'type' => 'raw',
                'sql' => '1=1',
                'params' => [$key => $value]
            ];
        }

        return $this;
    }

    public function searchContext(array $textWords, array $numberWords, array $dateWords, array $fields = []): self
    {
        if (!empty($textWords)) {
            if (!empty($fields)) {
                $this->searchByWords($textWords, $fields);
            } else {
                $textFields = $this->getTextFields();
                if (!empty($textFields)) {
                    $this->searchByWords($textWords, $textFields);
                }
            }
        }

        if (!empty($numberWords)) {
            $numberFields = $this->getNumericFields();
            if (!empty($numberFields)) {
                foreach ($numberWords as $number) {
                    $this->pushGroup('OR');
                    foreach ($numberFields as $field) {
                        $this->where($field, '=', $number);
                    }
                    $this->popGroup();
                }
            }
        }

        if (!empty($dateWords)) {
            $dateFields = $this->getDateFields();
            if (!empty($dateFields)) {
                foreach ($dateWords as $year) {
                    $this->pushGroup('OR');
                    foreach ($dateFields as $field) {
                        $this->whereYear($field, '=', (int)$year);
                    }
                    $this->popGroup();
                }
            }
        }

        return $this;
    }

    public function search(string $query, array $fields, string $mode = 'loose'): self
    {
        if (empty($fields)) {
            return $this;
        }

        switch ($mode) {
            case 'strict':
                $words = $this->extractWords($query);
                if (!empty($words)) {
                    $this->searchStrictByWords($words, $fields);
                }
                break;
            case 'exact':
                $this->searchExact($query, $fields);
                break;
            case 'fuzzy':
                $this->searchFuzzy($query, $fields);
                break;
            case 'loose':
            default:
                $words = $this->extractWords($query);
                if (!empty($words)) {
                    $this->searchByWords($words, $fields);
                }
                break;
        }

        return $this;
    }

    // ================================================================
    // PLUCK & FIND
    // ================================================================

    public function pluck(string $field, $default = null)
    {
        if (!$this->hasColumn($field)) {
            return $default;
        }

        $this->select([$field]);
        $qb = $this->build();

        try {
            $result = $qb->executeQuery()->fetchOne();
            return $result !== false ? $result : $default;
        } catch (\Exception $e) {
            return $default;
        }
    }

    public function pluckOneBy(string $field, array $criteria = [], $default = null)
    {
        if (!$this->hasColumn($field)) {
            return $default;
        }

        foreach ($criteria as $key => $value) {
            if ($this->hasColumn($key)) {
                $this->where($key, '=', $value);
            }
        }

        return $this->pluck($field, $default);
    }

    // ================================================================
    // GETTERS
    // ================================================================

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

    public function getGroupBy(): array
    {
        return $this->groupBy;
    }

    public function getHaving(): array
    {
        return $this->having;
    }

    public function getRawConditions(): array
    {
        return $this->rawConditions;
    }

    public function getSubConditions(): array
    {
        return $this->subConditions;
    }

    public function getTableName(): string
    {
        return $this->schemaManager->getTableName();
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getSchemaManager(): SchemaManager
    {
        return $this->schemaManager;
    }

    // ================================================================
    // GESTION DES GROUPES
    // ================================================================

    public function pushGroup(string $type): self
    {
        $this->groupStack[] = $type;
        return $this;
    }

    public function popGroup(): self
    {
        if (count($this->groupStack) > 1) {
            array_pop($this->groupStack);
        }
        return $this;
    }

    public function getCurrentGroup(): string
    {
        return end($this->groupStack) ?: 'AND';
    }

    public function hasOpenGroups(): bool
    {
        return count($this->groupStack) > 1;
    }

    public function resetGroups(): self
    {
        $this->groupStack = ['AND'];
        return $this;
    }

    // ================================================================
    // BUILD & RESET
    // ================================================================

    public function build(): DBALQueryBuilder
    {
        $qb = $this->connection->createQueryBuilder();
        $tableName = $this->schemaManager->getTableName();

        if (empty($this->projection)) {
            $existingColumns = $this->getExistingColumns();
            if (!empty($existingColumns)) {
                $qb->select(implode(', ', $existingColumns));
            } else {
                // Si aucune colonne n'existe, sélectionner tout (mais cela peut échouer)
                $qb->select('*');
            }
        } else {
            $existingColumns = $this->getExistingColumns();
            $validProjection = array_intersect($this->projection, $existingColumns);

            if (empty($validProjection)) {
                $qb->select(implode(', ', $existingColumns) ?: '*');
            } else {
                $qb->select(implode(', ', $validProjection));
            }
        }

        $qb->from($tableName);
        $this->buildJoins($qb, $tableName);
        $this->buildWhere($qb);
        $this->buildOrderBy($qb);

        foreach ($this->groupBy as $field) {
            if ($this->hasColumn($field)) {
                $qb->addGroupBy($field);
            }
        }

        foreach ($this->having as $having) {
            if ($this->hasColumn($having['field'])) {
                $param = $this->generateParamName($having['field'], $having['value']);
                $qb->setParameter($param, $having['value']);
                $qb->having("{$having['field']} {$having['operator']} :$param");
            }
        }

        if ($this->limit !== null) {
            $qb->setMaxResults($this->limit);
        }
        if ($this->offset !== null) {
            $qb->setFirstResult($this->offset);
        }

        return $qb;
    }

    public function reset(): self
    {
        $this->conditions = [];
        $this->projection = [];
        $this->orderBy = [];
        $this->orderByField = null;
        $this->orderByRaw = null;
        $this->limit = null;
        $this->offset = null;
        $this->joins = [];
        $this->groups = [];
        $this->groupBy = [];
        $this->having = [];
        $this->rawConditions = [];
        $this->subConditions = [];
        $this->groupStack = ['AND'];
        $this->conditionCounter = 0;
        return $this;
    }

    public function normalizeField(string $field): string
    {
        return $field === 'id' ? 'uid' : $field;
    }

    // ================================================================
    // MÉTHODES PRIVÉES
    // ================================================================

    private function addCondition(string $field, string $operator, mixed $value): self
    {
        $this->conditions[] = [
            'type' => 'condition',
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
            'group' => $this->getCurrentGroup(),
            'id' => ++$this->conditionCounter,
        ];
        return $this;
    }

    private function addJsonCondition(string $field, string $operator, string $value): self
    {
        $parts = explode('.', $field);
        $rootField = $parts[0];
        $jsonPath = '$."' . implode('"."', array_slice($parts, 1)) . '"';

        $this->conditions[] = [
            'type' => 'condition',
            'field' => $this->normalizeField($field),
            'operator' => $operator,
            'value' => $value,
            'path' => $jsonPath,
            'rootField' => $rootField,
            'group' => $this->getCurrentGroup(),
            'id' => ++$this->conditionCounter,
        ];
        return $this;
    }

    private function addJsonInCondition(string $field, array $values): self
    {
        $this->pushGroup('OR');
        foreach ($values as $value) {
            $this->whereJsonContains($field, json_encode($value));
        }
        $this->popGroup();
        return $this;
    }

    private function isJsonPath(string $field): bool
    {
        return strpos($field, '.') !== false;
    }

    private function isJsonColumn(string $field): bool
    {
        if ($this->schemaCache === null) {
            $this->schemaCache = $this->schemaManager->getSchema();
        }
        return isset($this->schemaCache[$field]) && ($this->schemaCache[$field]['type'] ?? '') === 'json';
    }

    private function normalizeJoinField(string $field): string
    {
        $field = $this->normalizeField($field);
        return str_ends_with($field, '_uid') ? str_replace('_uid', '_id', $field) : $field;
    }

    private function generateParamName(string $field, mixed $value): string
    {
        $hash = substr(md5($field . json_encode($value) . $this->conditionCounter), 0, 8);
        return 'p_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $field) . '_' . $hash;
    }

    private function buildWhere(DBALQueryBuilder $qb): void
    {
        foreach ($this->rawConditions as $raw) {
            $qb->andWhere($raw['sql']);
            foreach ($raw['params'] as $key => $value) {
                $qb->setParameter($key, $value);
            }
        }

        foreach ($this->subConditions as $sub) {
            $qb->andWhere("{$sub['field']} {$sub['operator']} ({$sub['sql']})");
            foreach ($sub['params'] as $key => $value) {
                $qb->setParameter($key, $value);
            }
        }

        $allConditions = array_merge($this->conditions, $this->groups);
        if (empty($allConditions)) {
            return;
        }

        $clauses = $this->buildClauseList($qb, $allConditions);
        if (!empty($clauses)) {
            $qb->andWhere(count($clauses) === 1 ? $clauses[0] : implode(' AND ', $clauses));
        }
    }

    private function buildClauseList(DBALQueryBuilder $qb, array $conditions): array
    {
        $clauses = [];
        $orGroup = [];
        $count = count($conditions);

        for ($i = 0; $i < $count; $i++) {
            $cond = $conditions[$i];

            if (isset($cond['type']) && $cond['type'] === 'group') {
                if (!empty($orGroup)) {
                    $clauses[] = '(' . implode(' OR ', $orGroup) . ')';
                    $orGroup = [];
                }

                $subClauses = $this->buildClauseList($qb, $cond['conditions']);
                if (!empty($subClauses)) {
                    $clauses[] = '(' . implode(' AND ', $subClauses) . ')';
                }
                continue;
            }

            $clause = $this->buildConditionString($qb, $cond);
            $group = $cond['group'] ?? 'AND';

            $nextGroup = isset($conditions[$i + 1]) ? ($conditions[$i + 1]['group'] ?? 'AND') : null;

            if ($group === 'OR') {
                $orGroup[] = $clause;
                if ($nextGroup !== 'OR') {
                    if (!empty($orGroup)) {
                        $clauses[] = '(' . implode(' OR ', $orGroup) . ')';
                        $orGroup = [];
                    }
                }
            } else {
                if (!empty($orGroup)) {
                    $clauses[] = '(' . implode(' OR ', $orGroup) . ')';
                    $orGroup = [];
                }
                $clauses[] = $clause;
            }
        }

        if (!empty($orGroup)) {
            $clauses[] = '(' . implode(' OR ', $orGroup) . ')';
        }

        return $clauses;
    }

    private function buildConditionString(DBALQueryBuilder $qb, array $condition): string
    {
        $field = $condition['field'];
        $operator = $condition['operator'];
        $value = $condition['value'];
        $param = $this->generateParamName($field, $value);

        return match ($operator) {
            'IN' => $this->buildInCondition($qb, $field, $value, $param),
            'NOT IN' => $this->buildNotInCondition($qb, $field, $value, $param),
            'BETWEEN' => $this->buildBetweenCondition($qb, $field, $value, $param),
            'IS NULL', 'IS NOT NULL' => "{$field} {$operator}",
            'LIKE' => $this->buildLikeCondition($qb, $field, $value, $param),
            'JSON_CONTAINS' => $this->buildJsonContainsCondition($qb, $field, $value, $param),
            'JSON_NOT_CONTAINS' => $this->buildJsonNotContainsCondition($qb, $field, $value, $param),
            'JSON_EXTRACT' => $this->buildJsonExtractCondition($qb, $condition, $param),
            'JSON_CONTAINS_EXTRACT', 'JSON_NOT_CONTAINS_EXTRACT' => $this->buildJsonContainsExtractCondition($qb, $condition, $param),
            default => $this->buildSimpleCondition($qb, $field, $operator, $value, $param),
        };
    }

    private function buildSimpleCondition(DBALQueryBuilder $qb, string $field, string $operator, $value, string $param): string
    {
        $qb->setParameter($param, $value);
        return "{$field} {$operator} :{$param}";
    }

    private function buildInCondition(DBALQueryBuilder $qb, string $field, array $values, string $param): string
    {
        $placeholders = [];
        foreach ($values as $i => $value) {
            $p = "{$param}_{$i}";
            $placeholders[] = ":$p";
            $qb->setParameter($p, $value);
        }
        return "{$field} IN (" . implode(', ', $placeholders) . ")";
    }

    private function buildNotInCondition(DBALQueryBuilder $qb, string $field, array $values, string $param): string
    {
        $placeholders = [];
        foreach ($values as $i => $value) {
            $p = "{$param}_{$i}";
            $placeholders[] = ":$p";
            $qb->setParameter($p, $value);
        }
        return "{$field} NOT IN (" . implode(', ', $placeholders) . ")";
    }

    private function buildBetweenCondition(DBALQueryBuilder $qb, string $field, array $values, string $param): string
    {
        $min = "{$param}_min";
        $max = "{$param}_max";
        $qb->setParameter($min, $values[0]);
        $qb->setParameter($max, $values[1]);
        return "{$field} BETWEEN :{$min} AND :{$max}";
    }

    private function buildLikeCondition(DBALQueryBuilder $qb, string $field, string $pattern, string $param): string
    {
        $qb->setParameter($param, $pattern);
        return "{$field} LIKE :{$param}";
    }

    private function buildJsonContainsCondition(DBALQueryBuilder $qb, string $field, string $value, string $param): string
    {
        $jsonValue = json_encode($value);
        $qb->setParameter($param, $jsonValue);
        return "JSON_CONTAINS({$field}, :{$param})";
    }

    private function buildJsonNotContainsCondition(DBALQueryBuilder $qb, string $field, string $value, string $param): string
    {
        $jsonValue = json_encode($value);
        $qb->setParameter($param, $jsonValue);
        return "NOT JSON_CONTAINS({$field}, :{$param})";
    }

    private function buildJsonExtractCondition(DBALQueryBuilder $qb, array $condition, string $param): string
    {
        $rootField = $condition['rootField'];
        $path = $condition['path'];
        $value = $condition['value'];

        $pathParam = $param . '_path';
        $qb->setParameter($pathParam, $path);
        $qb->setParameter($param, $value);

        return "JSON_UNQUOTE(JSON_EXTRACT(`$rootField`, :$pathParam)) = :{$param}";
    }

    private function buildJsonContainsExtractCondition(DBALQueryBuilder $qb, array $condition, string $param): string
    {
        $field = $condition['field'];
        $value = $condition['value'];
        $operator = $condition['operator'];
        $isNot = str_starts_with($operator, 'JSON_NOT');

        $parts = explode('.', $field);
        $rootField = $parts[0];
        $path = count($parts) > 1 ? '$.' . implode('.', array_slice($parts, 1)) : '$';

        $qb->setParameter($param, json_encode($value));
        $qb->setParameter($param . '_path', $path);

        $sql = "JSON_CONTAINS(`$rootField`, :$param, :{$param}_path)";
        return $isNot ? "NOT $sql" : $sql;
    }

    private function buildOrderBy(DBALQueryBuilder $qb): void
    {
        if ($this->orderByField) {
            $this->applyOrderByField($qb);
            return;
        }

        if ($this->orderByRaw) {
            $this->applyOrderByRaw($qb);
            return;
        }

        foreach ($this->orderBy as [$field, $direction]) {
            if ($this->hasColumn($field)) {
                $qb->addOrderBy($field, $direction);
            }
        }
    }

    private function applyOrderByField(DBALQueryBuilder $qb): void
    {
        $field = $this->orderByField['field'];
        $values = $this->orderByField['values'];
        $direction = $this->orderByField['direction'];

        $placeholders = [];
        foreach ($values as $i => $value) {
            $param = "field_order_{$i}";
            $placeholders[] = ":$param";
            $qb->setParameter($param, $value);
        }

        $fieldClause = "FIELD(`$field`, " . implode(', ', $placeholders) . ")";
        $qb->addOrderBy($fieldClause, $direction);
    }

    private function applyOrderByRaw(DBALQueryBuilder $qb): void
    {
        $qb->addOrderBy($this->orderByRaw['sql']);
        foreach ($this->orderByRaw['params'] as $key => $value) {
            $qb->setParameter($key, $value);
        }
    }

    private function buildJoins(DBALQueryBuilder $qb, string $tableName): void
    {
        foreach ($this->joins as $join) {
            $method = $join['type'] . 'Join';
            $alias = $join['collection'];
            $joinTable = $this->schemaManager->getPrefix() . $join['collection'];

            if (isset($join['complex']) && $join['complex']) {
                $qb->$method($alias, $joinTable, $alias, $join['onClause']);
                foreach ($join['params'] as $key => $value) {
                    $qb->setParameter($key, $value);
                }
            } else {
                $qb->$method(
                    $alias,
                    $joinTable,
                    $alias,
                    "{$tableName}.{$join['localField']} {$join['operator']} {$alias}.{$join['foreignField']}"
                );
            }
        }
    }
}
