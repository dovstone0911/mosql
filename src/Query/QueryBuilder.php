<?php

namespace Dovstone\MoSQL\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder as DBALQueryBuilder;
use Dovstone\MoSQL\Schema\SchemaManager;

/**
 * QueryBuilder - Constructeur de requêtes SQL avec support JSON, sous-requêtes et conditions avancées
 * 
 * @package Dovstone\MoSQL\Query
 * @author Dovstone
 * 
 * @example
 * $qb = new QueryBuilder($connection, $schemaManager);
 * $results = $qb->where('status', '=', 'active')
 *               ->orderBy('created_at', 'DESC')
 *               ->limit(10)
 *               ->build()
 *               ->fetchAllAssociative();
 */
class QueryBuilder
{
    // ================================================================
    // PROPRIÉTÉS
    // ================================================================

    private Connection $connection;
    private SchemaManager $schemaManager;

    private array $conditions = [];
    private array $projection = [];
    private array $orderBy = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private array $joins = [];
    private array $groups = [];

    // Gestion des groupes imbriqués
    private array $groupStack = ['AND'];

    // Propriétés avancées
    private ?array $orderByField = null;
    private ?array $orderByRaw = null;
    private int $conditionCounter = 0;
    private array $groupBy = [];
    private array $having = [];
    private array $rawConditions = [];
    private array $subConditions = [];

    // Cache du schéma pour éviter les appels répétés
    private ?array $schemaCache = null;

    // ================================================================
    // CONSTRUCTEUR
    // ================================================================

    public function __construct(Connection $connection, SchemaManager $schemaManager)
    {
        $this->connection = $connection;
        $this->schemaManager = $schemaManager;
    }

    // ================================================================
    // 1. CONDITIONS DE BASE
    // ================================================================

    /**
     * Ajoute une condition WHERE
     * 
     * @param string $field Nom du champ
     * @param string $operator Opérateur (=, !=, >, <, >=, <=, LIKE, IS NULL, etc.)
     * @param mixed $value Valeur à comparer
     * @return self
     * 
     * @example
     * $qb->where('status', '=', 'active');
     * $qb->where('age', '>', 18);
     * $qb->where('user.profile.age', '>', 18); // JSON path
     */
    public function where(string $field, string $operator, mixed $value): self
    {
        $field = $this->normalizeField($field);
        $operator = strtoupper($operator);

        if ($this->isJsonPath($field)) {
            return $this->addJsonCondition($field, $operator, $value);
        }

        return $this->addCondition($field, $operator, $value);
    }

    /**
     * Ajoute une condition WHERE avec OR
     * 
     * @param string $field Nom du champ
     * @param string $operator Opérateur
     * @param mixed $value Valeur
     * @return self
     */
    public function orWhere(string $field, string $operator, mixed $value): self
    {
        $this->pushGroup('OR');
        $result = $this->where($field, $operator, $value);
        $this->popGroup();
        return $result;
    }

    /**
     * Ajoute un groupe de conditions avec AND
     * 
     * @param callable $callback Fonction qui reçoit un QueryBuilder
     * @return self
     */
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

    /**
     * Ajoute un groupe de conditions avec OR
     * 
     * @param callable $callback Fonction qui reçoit un QueryBuilder
     * @return self
     */
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

    // ================================================================
    // 2. CONDITIONS SPÉCIALES (IN, LIKE, BETWEEN, NULL)
    // ================================================================

    /**
     * Ajoute une condition WHERE IN
     * 
     * @param string $field Nom du champ
     * @param array $values Liste des valeurs
     * @return self
     */
    public function whereIn(string $field, array $values): self
    {
        if (empty($values)) {
            return $this;
        }

        $field = $this->normalizeField($field);

        if ($this->isJsonColumn($field)) {
            return $this->addJsonInCondition($field, $values);
        }

        return $this->addCondition($field, 'IN', $values);
    }

    /**
     * Ajoute une condition WHERE NOT IN
     * 
     * @param string $field Nom du champ
     * @param array $values Liste des valeurs
     * @return self
     */
    public function whereNotIn(string $field, array $values): self
    {
        if (empty($values)) {
            return $this;
        }

        $field = $this->normalizeField($field);

        if ($this->isJsonColumn($field)) {
            foreach ($values as $value) {
                $this->whereJsonNotContains($field, $value);
            }
            return $this;
        }

        return $this->addCondition($field, 'NOT IN', $values);
    }

    /**
     * Ajoute une condition WHERE LIKE
     * 
     * @param string $field Nom du champ
     * @param string $pattern Motif de recherche
     * @return self
     */
    public function whereLike(string $field, string $pattern): self
    {
        return $this->where($field, 'LIKE', $pattern);
    }

    /**
     * Ajoute une condition WHERE BETWEEN
     * 
     * @param string $field Nom du champ
     * @param mixed $min Valeur minimale
     * @param mixed $max Valeur maximale
     * @return self
     */
    public function whereBetween(string $field, mixed $min, mixed $max): self
    {
        return $this->where($field, 'BETWEEN', [$min, $max]);
    }

    /**
     * Ajoute une condition WHERE IS NULL
     * 
     * @param string $field Nom du champ
     * @return self
     */
    public function whereNull(string $field): self
    {
        return $this->where($field, 'IS NULL', null);
    }

    /**
     * Ajoute une condition WHERE IS NOT NULL
     * 
     * @param string $field Nom du champ
     * @return self
     */
    public function whereNotNull(string $field): self
    {
        return $this->where($field, 'IS NOT NULL', null);
    }

    // ================================================================
    // 3. CONDITIONS JSON
    // ================================================================

    /**
     * Ajoute une condition WHERE JSON_CONTAINS
     * 
     * @param string $field Nom du champ JSON
     * @param string $value Valeur JSON à chercher
     * @return self
     */
    public function whereJsonContains(string $field, string $value): self
    {
        return $this->addJsonCondition($field, 'JSON_CONTAINS', $value);
    }

    /**
     * Ajoute une condition WHERE JSON_NOT_CONTAINS
     * 
     * @param string $field Nom du champ JSON
     * @param string $value Valeur JSON à chercher
     * @return self
     */
    public function whereJsonNotContains(string $field, string $value): self
    {
        return $this->addJsonCondition($field, 'JSON_NOT_CONTAINS', $value);
    }

    /**
     * Ajoute une condition WHERE JSON_CONTAINS avec OR
     * 
     * @param string $field Nom du champ JSON
     * @param string $value Valeur JSON à chercher
     * @return self
     */
    public function orWhereJsonContains(string $field, string $value): self
    {
        $this->pushGroup('OR');
        $result = $this->whereJsonContains($field, $value);
        $this->popGroup();
        return $result;
    }

    /**
     * Ajoute une condition WHERE JSON_NOT_CONTAINS avec OR
     * 
     * @param string $field Nom du champ JSON
     * @param string $value Valeur JSON à chercher
     * @return self
     */
    public function orWhereJsonNotContains(string $field, string $value): self
    {
        $this->pushGroup('OR');
        $result = $this->whereJsonNotContains($field, $value);
        $this->popGroup();
        return $result;
    }

    /**
     * WHERE JSON_CONTAINS avec OR entre les valeurs
     * 
     * @param string $field Nom du champ JSON
     * @param array $values Liste des valeurs
     * @return self
     */
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

    /**
     * WHERE JSON_NOT_CONTAINS avec OR entre les valeurs
     * 
     * @param string $field Nom du champ JSON
     * @param array $values Liste des valeurs
     * @return self
     */
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

    /**
     * WHERE JSON_CONTAINS avec AND entre les valeurs
     * 
     * @param string $field Nom du champ JSON
     * @param array $values Liste des valeurs
     * @return self
     */
    public function whereJsonContainsAll(string $field, array $values): self
    {
        foreach ($values as $value) {
            $this->whereJsonContains($field, $value);
        }
        return $this;
    }

    /**
     * WHERE JSON_NOT_CONTAINS avec AND entre les valeurs
     * 
     * @param string $field Nom du champ JSON
     * @param array $values Liste des valeurs
     * @return self
     */
    public function whereJsonNotContainsAll(string $field, array $values): self
    {
        foreach ($values as $value) {
            $this->whereJsonNotContains($field, $value);
        }
        return $this;
    }

    // ================================================================
    // 4. CONDITIONS DE DATE/TEMPS
    // ================================================================

    /**
     * Ajoute une condition WHERE sur une date
     * 
     * @param string $field Nom du champ
     * @param string $operator Opérateur
     * @param string $date Date (format Y-m-d)
     * @return self
     */
    public function whereDate(string $field, string $operator, string $date): self
    {
        return $this->where("DATE($field)", $operator, $date);
    }

    /**
     * Ajoute une condition WHERE sur le mois
     * 
     * @param string $field Nom du champ
     * @param string $operator Opérateur
     * @param int $month Mois (1-12)
     * @return self
     */
    public function whereMonth(string $field, string $operator, int $month): self
    {
        return $this->where("MONTH($field)", $operator, $month);
    }

    /**
     * Ajoute une condition WHERE sur l'année
     * 
     * @param string $field Nom du champ
     * @param string $operator Opérateur
     * @param int $year Année
     * @return self
     */
    public function whereYear(string $field, string $operator, int $year): self
    {
        return $this->where("YEAR($field)", $operator, $year);
    }

    /**
     * Ajoute une condition WHERE sur le jour
     * 
     * @param string $field Nom du champ
     * @param string $operator Opérateur
     * @param int $day Jour (1-31)
     * @return self
     */
    public function whereDay(string $field, string $operator, int $day): self
    {
        return $this->where("DAY($field)", $operator, $day);
    }

    /**
     * Ajoute une condition WHERE sur l'heure
     * 
     * @param string $field Nom du champ
     * @param string $operator Opérateur
     * @param int $hour Heure (0-23)
     * @return self
     */
    public function whereHour(string $field, string $operator, int $hour): self
    {
        return $this->where("HOUR($field)", $operator, $hour);
    }

    /**
     * Ajoute une condition WHERE sur la minute
     * 
     * @param string $field Nom du champ
     * @param string $operator Opérateur
     * @param int $minute Minute (0-59)
     * @return self
     */
    public function whereMinute(string $field, string $operator, int $minute): self
    {
        return $this->where("MINUTE($field)", $operator, $minute);
    }

    // ================================================================
    // 5. CONDITIONS AVANCÉES (RAW, SUBQUERY)
    // ================================================================

    /**
     * Ajoute une condition WHERE avec SQL brut
     * 
     * @param string $sql SQL brut
     * @param array $params Paramètres
     * @return self
     */
    public function whereRaw(string $sql, array $params = []): self
    {
        $this->rawConditions[] = [
            'type' => 'raw',
            'sql' => $sql,
            'params' => $params,
        ];
        return $this;
    }

    /**
     * Ajoute une condition WHERE avec sous-requête
     * 
     * @param string $field Nom du champ
     * @param string $operator Opérateur
     * @param callable $callback Callback construisant la sous-requête
     * @return self
     */
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
    // 6. PROJECTION ET TRI
    // ================================================================

    /**
     * Définit les champs à sélectionner
     * 
     * @param array $fields Liste des champs
     * @return self
     */
    public function select(array $fields): self
    {
        $this->projection = $fields;
        return $this;
    }

    /**
     * Ajoute un ORDER BY
     * 
     * @param string $field Nom du champ
     * @param string $direction ASC ou DESC
     * @return self
     */
    public function orderBy(string $field, string $direction = 'ASC'): self
    {
        $this->orderBy[] = [$this->normalizeField($field), strtoupper($direction)];
        return $this;
    }

    /**
     * ORDER BY avec FIELD() pour un ordre personnalisé
     * 
     * @param string $field Nom du champ
     * @param array $values Valeurs dans l'ordre souhaité
     * @param string $direction ASC ou DESC
     * @return self
     */
    public function orderByField(string $field, array $values, string $direction = 'ASC'): self
    {
        if (empty($values)) {
            return $this;
        }

        $this->orderByField = [
            'field' => $this->normalizeField($field),
            'values' => $values,
            'direction' => strtoupper($direction),
        ];

        return $this;
    }

    /**
     * ORDER BY avec SQL brut
     * 
     * @param string $rawSql SQL brut
     * @param array $params Paramètres
     * @return self
     */
    public function orderByRaw(string $rawSql, array $params = []): self
    {
        $this->orderByRaw = ['sql' => $rawSql, 'params' => $params];
        return $this;
    }

    /**
     * Définit la limite et l'offset
     * 
     * @param int $limit Nombre maximum de résultats
     * @param int|null $offset Décalage
     * @return self
     */
    public function limit(int $limit, ?int $offset = null): self
    {
        $this->limit = max(0, $limit);
        if ($offset !== null) {
            $this->offset = max(0, $offset);
        }
        return $this;
    }

    /**
     * Définit l'offset
     * 
     * @param int $offset Décalage
     * @return self
     */
    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);
        return $this;
    }

    /**
     * Ajoute un GROUP BY
     * 
     * @param string|array $fields Champ(s) de regroupement
     * @return self
     */
    public function groupBy(string|array $fields): self
    {
        if (is_string($fields)) {
            $fields = [$fields];
        }

        foreach ($fields as $field) {
            $this->groupBy[] = $this->normalizeField($field);
        }
        return $this;
    }

    /**
     * Ajoute un HAVING
     * 
     * @param string $field Nom du champ
     * @param string $operator Opérateur
     * @param mixed $value Valeur
     * @return self
     */
    public function having(string $field, string $operator, mixed $value): self
    {
        $this->having[] = [
            'field' => $this->normalizeField($field),
            'operator' => $operator,
            'value' => $value,
        ];
        return $this;
    }

    // ================================================================
    // 7. JOINTURES
    // ================================================================

    /**
     * Ajoute une jointure
     * 
     * @param string $collection Nom de la collection
     * @param string $localField Champ local
     * @param string $operator Opérateur
     * @param string $foreignField Champ étranger
     * @param string $type Type de jointure (inner, left, right)
     * @return self
     */
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

    /**
     * Alias de join avec type 'left'
     * 
     * @param string $collection Nom de la collection
     * @param string $localField Champ local
     * @param string $operator Opérateur
     * @param string $foreignField Champ étranger
     * @return self
     */
    public function leftJoin(string $collection, string $localField, string $operator, string $foreignField): self
    {
        return $this->join($collection, $localField, $operator, $foreignField, 'left');
    }

    /**
     * Alias de join avec type 'inner'
     * 
     * @param string $collection Nom de la collection
     * @param string $localField Champ local
     * @param string $operator Opérateur
     * @param string $foreignField Champ étranger
     * @return self
     */
    public function innerJoin(string $collection, string $localField, string $operator, string $foreignField): self
    {
        return $this->join($collection, $localField, $operator, $foreignField, 'inner');
    }

    /**
     * Alias de join avec type 'right'
     * 
     * @param string $collection Nom de la collection
     * @param string $localField Champ local
     * @param string $operator Opérateur
     * @param string $foreignField Champ étranger
     * @return self
     */
    public function rightJoin(string $collection, string $localField, string $operator, string $foreignField): self
    {
        return $this->join($collection, $localField, $operator, $foreignField, 'right');
    }

    /**
     * Jointure complexe avec condition personnalisée
     * 
     * @param string $collection Nom de la collection
     * @param string $onClause Condition de jointure
     * @param string $type Type de jointure
     * @param array $params Paramètres
     * @return self
     */
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
    // 8. GETTERS
    // ================================================================

    public function getConditions(): array { return $this->conditions; }
    public function getProjection(): array { return $this->projection; }
    public function getOrderBy(): array { return $this->orderBy; }
    public function getLimit(): ?int { return $this->limit; }
    public function getOffset(): ?int { return $this->offset; }
    public function getJoins(): array { return $this->joins; }
    public function getGroups(): array { return $this->groups; }
    public function getGroupBy(): array { return $this->groupBy; }
    public function getHaving(): array { return $this->having; }
    public function getRawConditions(): array { return $this->rawConditions; }
    public function getSubConditions(): array { return $this->subConditions; }
    public function getTableName(): string { return $this->schemaManager->getTableName(); }
    public function getConnection(): Connection { return $this->connection; }
    public function getSchemaManager(): SchemaManager { return $this->schemaManager; }

    // ================================================================
    // 9. BUILD & RESET
    // ================================================================

    /**
     * Construit et retourne le QueryBuilder Doctrine
     * 
     * @return DBALQueryBuilder
     */
    public function build(): DBALQueryBuilder
    {
        $qb = $this->connection->createQueryBuilder();
        $tableName = $this->schemaManager->getTableName();

        // Projection
        if (empty($this->projection)) {
            $qb->select('*');
        } else {
            $qb->select(implode(', ', $this->projection));
        }

        $qb->from($tableName);

        $this->buildJoins($qb, $tableName);
        $this->buildWhere($qb);
        $this->buildOrderBy($qb);

        // Group By
        foreach ($this->groupBy as $field) {
            $qb->addGroupBy($field);
        }

        // Having
        foreach ($this->having as $having) {
            $param = $this->generateParamName($having['field'], $having['value']);
            $qb->setParameter($param, $having['value']);
            $qb->having("{$having['field']} {$having['operator']} :$param");
        }

        if ($this->limit !== null) {
            $qb->setMaxResults($this->limit);
        }
        if ($this->offset !== null) {
            $qb->setFirstResult($this->offset);
        }

        return $qb;
    }

    /**
     * Réinitialise complètement le QueryBuilder
     * 
     * @return self
     */
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

    /**
     * Normalise un nom de champ (id → uid)
     * 
     * @param string $field Nom du champ
     * @return string
     */
    public function normalizeField(string $field): string
    {
        return $field === 'id' ? 'uid' : $field;
    }

    // ================================================================
    // 10. MÉTHODES PRIVÉES - GESTION DES GROUPES
    // ================================================================

    private function pushGroup(string $type): void
    {
        $this->groupStack[] = $type;
    }

    private function popGroup(): void
    {
        if (count($this->groupStack) > 1) {
            array_pop($this->groupStack);
        }
    }

    private function getCurrentGroup(): string
    {
        return end($this->groupStack) ?: 'AND';
    }

    // ================================================================
    // 11. MÉTHODES PRIVÉES - AJOUT DE CONDITIONS
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

    // ================================================================
    // 12. MÉTHODES PRIVÉES - UTILITAIRES DE DÉTECTION
    // ================================================================

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

    // ================================================================
    // 13. BUILD WHERE (PRIVÉ)
    // ================================================================

    private function buildWhere(DBALQueryBuilder $qb): void
    {
        // Raw conditions
        foreach ($this->rawConditions as $raw) {
            $qb->andWhere($raw['sql']);
            foreach ($raw['params'] as $key => $value) {
                $qb->setParameter($key, $value);
            }
        }

        // Subquery conditions
        foreach ($this->subConditions as $sub) {
            $qb->andWhere("{$sub['field']} {$sub['operator']} ({$sub['sql']})");
            foreach ($sub['params'] as $key => $value) {
                $qb->setParameter($key, $value);
            }
        }

        // Conditions existantes
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

            // Groupe imbriqué
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

            // Condition simple
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

    // ================================================================
    // 14. BUILD CONDITIONS SPÉCIFIQUES (PRIVÉ)
    // ================================================================

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

    // ================================================================
    // 15. BUILD ORDER BY (PRIVÉ)
    // ================================================================

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
            $qb->addOrderBy($field, $direction);
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

    // ================================================================
    // 16. BUILD JOINS (PRIVÉ)
    // ================================================================

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