<?php

namespace Dovstone\MoSQL;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Dovstone\MoSQL\Cache\ArrayCache;
use Dovstone\MoSQL\Cache\CacheManager;
use Dovstone\MoSQL\Document\DocumentManager;
use Dovstone\MoSQL\Exception\DatabaseException;
use Dovstone\MoSQL\Exception\DocumentNotFoundException;
use Dovstone\MoSQL\Exception\DuplicateException;
use Dovstone\MoSQL\Exception\InvalidArgumentException;
use Dovstone\MoSQL\Query\QueryBuilder;
use Dovstone\MoSQL\Schema\SchemaManager;
use Dovstone\MoSQL\Cache\SqlFileCacheManager;
use Dovstone\MoSQL\Uid\UidGenerator;

/**
 * MoSQL - Bibliothèque ORM légère avec support JSON, UID, Cache et Requêtes avancées
 * 
 * @package Dovstone\MoSQL
 * @author Dovstone
 * 
 * @property QueryBuilder $queryBuilder
 * 
 * @example
 * // Initialisation
 * $users = new MoSQL('users', [
 *     'driver' => 'pdo_mysql',
 *     'host' => 'localhost',
 *     'dbname' => 'my_database',
 *     'user' => 'root',
 *     'password' => ''
 * ]);
 * 
 * // Requête simple
 * $activeUsers = $users->where('status', '=', 'active')->find();
 */
class MoSQL
{
    // ================================================================
    // PROPRIÉTÉS
    // ================================================================

    private Connection $connection;
    private string $collection;
    public QueryBuilder $queryBuilder;
    private SchemaManager $schemaManager;
    private DocumentManager $documentManager;
    private CacheManager $cacheManager;
    private SqlFileCacheManager $sqlFileCacheManager;
    private UidGenerator $uidGenerator;
    private array $options;
    private array $uidCache = [];

    // 🔥 Connexion partagée (Singleton)
    private static ?Connection $sharedConnection = null;
    private static array $sharedConfig = [];

    // ================================================================
    // CONSTRUCTEUR & CONNEXION
    // ================================================================

    /**
     * Constructeur de MoSQL
     * 
     * @param string|null $collection Nom de la collection (table)
     * @param array $dbParams Paramètres de connexion Doctrine
     * @param array $options Options de configuration
     * 
     * @example
     * $db = new MoSQL('users', [
     *     'driver' => 'pdo_mysql',
     *     'host' => 'localhost',
     *     'dbname' => 'my_db',
     *     'user' => 'root',
     *     'password' => ''
     * ]);
     */
    public function __construct(?string $collection, array $dbParams = [], array $options = [])
    {
        // 🔥 Initialiser la connexion partagée UNE SEULE FOIS
        if (self::$sharedConnection === null) {
            self::$sharedConnection = DriverManager::getConnection($dbParams);
            self::$sharedConfig = $dbParams;
        }

        $this->connection = self::$sharedConnection;

        // 🔥 Sans collection (mode brut)
        if ($collection === null) {
            $this->collection = '';
            $this->options = $options;
            $this->cacheManager = new CacheManager(
                new ArrayCache(),
                $options['cache_enabled'] ?? false,
                $options['cache_ttl'] ?? 3600
            );
            $this->sqlFileCacheManager = new SqlFileCacheManager();
            return;
        }

        // 🔥 Avec collection
        $this->collection = $collection;
        $this->options = array_merge([
            'uid_length' => 8,
            'table_prefix' => '',
            'auto_create_schema' => true,
            'cache_enabled' => false,
            'cache_ttl' => 3600,
        ], $options);

        $this->schemaManager = new SchemaManager($this->connection, $collection, $this->options);
        $this->documentManager = new DocumentManager($this->connection, $this->schemaManager, $collection);
        $this->queryBuilder = new QueryBuilder($this->connection, $this->schemaManager);
        $this->uidGenerator = new UidGenerator($this->options['uid_length']);
        $this->cacheManager = new CacheManager(
            new ArrayCache(),
            $this->options['cache_enabled'],
            $this->options['cache_ttl']
        );
        $this->sqlFileCacheManager = new SqlFileCacheManager();
        $this->schemaManager->ensureTableExists();
    }

    /**
     * Réinitialise la connexion partagée
     * 
     * @return void
     */
    public static function resetConnection(): void
    {
        if (self::$sharedConnection && self::$sharedConnection->isConnected()) {
            self::$sharedConnection->close();
        }
        self::$sharedConnection = null;
    }

    /**
     * Clone l'instance
     * 
     * @return void
     */
    public function __clone()
    {
        $this->queryBuilder = clone $this->queryBuilder;
    }

    // ================================================================
    // 1. VÉRIFICATION DE CHAMP
    // ================================================================

    /**
     * Vérifie si un champ existe dans la table
     * 
     * @param string $field Nom du champ
     * @return bool
     */
    private function fieldExists(string $field): bool
    {
        if (empty($this->schemaManager->getSchema())) {
            $this->schemaManager->loadSchema();
        }

        $schema = $this->schemaManager->getSchema();
        $fields = array_keys($schema);

        if (in_array($field, ['uid', 'id'])) {
            return true;
        }

        return in_array($field, $fields);
    }

    /**
     * Vérifie si un champ est de type JSON
     * 
     * @param string $field Nom du champ
     * @return bool
     */
    private function isJsonField(string $field): bool
    {
        $schema = $this->schemaManager->getSchema();
        return isset($schema[$field]) && ($schema[$field]['type'] ?? '') === 'json';
    }

    // ================================================================
    // 2. MÉTHODES DE PASSERELLE VERS QUERYBUILDER
    // ================================================================

    /**
     * Passe une méthode au QueryBuilder avec vérification de champ
     * 
     * @param string $method Nom de la méthode
     * @param array $args Arguments
     * @return self
     */
    private function proxy(string $method, array $args): self
    {
        // Si la méthode attend un champ en premier argument
        if (!empty($args) && is_string($args[0]) && !in_array($method, ['select', 'groupBy', 'orderBy', 'limit', 'offset', 'having', 'whereRaw', 'whereSub', 'join', 'leftJoin', 'innerJoin', 'rightJoin', 'joinComplex'])) {
            $field = $args[0];
            // Pour les champs JSON avec chemin (ex: 'roles.admin')
            $baseField = explode('.', $field)[0];
            if (!$this->fieldExists($baseField) && !in_array($baseField, ['uid', 'id'])) {
                return $this;
            }
        }

        $this->queryBuilder->$method(...$args);
        return $this;
    }

    /**
     * Passe une méthode au QueryBuilder sans vérification de champ
     * 
     * @param string $method Nom de la méthode
     * @param array $args Arguments
     * @return self
     */
    private function proxyDirect(string $method, array $args): self
    {
        $this->queryBuilder->$method(...$args);
        return $this;
    }

    // ================================================================
    // 3. CONDITIONS DE BASE (PASSERELLES)
    // ================================================================

    /**
     * Ajoute une condition WHERE
     * 
     * @param string $field Nom du champ
     * @param string $operator Opérateur
     * @param mixed $value Valeur
     * @return self
     * 
     * @example
     * $users->where('status', '=', 'active');
     */
    public function where(string $field, string $operator, mixed $value): self
    {
        return $this->proxy('where', [$field, $operator, $value]);
    }

    /**
     * Ajoute une condition WHERE avec OR
     * 
     * @param string $field Nom du champ
     * @param string $operator Opérateur
     * @param mixed $value Valeur
     * @return self
     * 
     * @example
     * $users->where('status', '=', 'active')->orWhere('status', '=', 'pending');
     */
    public function orWhere(string $field, string $operator, mixed $value): self
    {
        return $this->proxy('orWhere', [$field, $operator, $value]);
    }

    /**
     * Ajoute un groupe de conditions avec AND
     * 
     * @param callable $callback Fonction qui reçoit un QueryBuilder
     * @return self
     * 
     * @example
     * $users->where('status', '=', 'active')->andWhere(function($q) {
     *     $q->where('age', '>', 18);
     * });
     */
    public function andWhere(callable $callback): self
    {
        $this->queryBuilder->andWhere($callback);
        return $this;
    }

    /**
     * Ajoute une condition WHERE IN
     * 
     * @param string $field Nom du champ
     * @param array $values Liste des valeurs
     * @return self
     * 
     * @example
     * $users->whereIn('status', ['active', 'pending']);
     */
    public function whereIn(string $field, array $values): self
    {
        return $this->proxy('whereIn', [$field, $values]);
    }

    /**
     * Ajoute une condition WHERE NOT IN
     * 
     * @param string $field Nom du champ
     * @param array $values Liste des valeurs
     * @return self
     * 
     * @example
     * $users->whereNotIn('status', ['deleted', 'banned']);
     */
    public function whereNotIn(string $field, array $values): self
    {
        return $this->proxy('whereNotIn', [$field, $values]);
    }

    /**
     * Ajoute une condition WHERE LIKE
     * 
     * @param string $field Nom du champ
     * @param string $pattern Motif de recherche
     * @return self
     * 
     * @example
     * $users->whereLike('name', 'John%');
     */
    public function whereLike(string $field, string $pattern): self
    {
        return $this->proxy('whereLike', [$field, $pattern]);
    }

    /**
     * Ajoute une condition WHERE BETWEEN
     * 
     * @param string $field Nom du champ
     * @param mixed $min Valeur minimale
     * @param mixed $max Valeur maximale
     * @return self
     * 
     * @example
     * $users->whereBetween('age', 18, 65);
     */
    public function whereBetween(string $field, $min, $max): self
    {
        return $this->proxy('whereBetween', [$field, $min, $max]);
    }

    /**
     * Ajoute une condition WHERE IS NULL
     * 
     * @param string $field Nom du champ
     * @return self
     * 
     * @example
     * $users->whereNull('deleted_at');
     */
    public function whereNull(string $field): self
    {
        return $this->proxy('whereNull', [$field]);
    }

    /**
     * Ajoute une condition WHERE IS NOT NULL
     * 
     * @param string $field Nom du champ
     * @return self
     * 
     * @example
     * $users->whereNotNull('email');
     */
    public function whereNotNull(string $field): self
    {
        return $this->proxy('whereNotNull', [$field]);
    }

    /**
     * Ajoute une condition WHERE pour un champ non vide
     * 
     * @param string $field Nom du champ
     * @return self
     * 
     * @example
     * $users->whereNotEmpty('email')->find();
     */
    public function whereNotEmpty(string $field): self
    {
        if (!$this->fieldExists($field)) {
            return $this;
        }
        $this->queryBuilder->where($field, 'IS NOT NULL', null);
        $this->queryBuilder->where($field, '!=', '');
        return $this;
    }

    /**
     * Ajoute une condition WHERE pour un champ vide
     * 
     * @param string $field Nom du champ
     * @return self
     * 
     * @example
     * $users->whereEmpty('email')->find();
     */
    public function whereEmpty(string $field): self
    {
        if (!$this->fieldExists($field)) {
            return $this;
        }
        $this->queryBuilder->where($field, 'IS NULL', null);
        $this->queryBuilder->orWhere($field, '=', '');
        return $this;
    }

    // ================================================================
    // 4. CONDITIONS JSON (PASSERELLES)
    // ================================================================

    /**
     * Ajoute une condition WHERE JSON_CONTAINS
     * 
     * @param string $field Nom du champ JSON
     * @param string $value Valeur JSON à chercher
     * @return self
     * 
     * @example
     * $users->whereJsonContains('roles', '"admin"');
     */
    public function whereJsonContains(string $field, string $value): self
    {
        return $this->proxy('whereJsonContains', [$field, $value]);
    }

    /**
     * Ajoute une condition WHERE JSON_NOT_CONTAINS
     * 
     * @param string $field Nom du champ JSON
     * @param string $value Valeur JSON à chercher
     * @return self
     * 
     * @example
     * $users->whereJsonNotContains('roles', '"banned"');
     */
    public function whereJsonNotContains(string $field, string $value): self
    {
        return $this->proxy('whereJsonNotContains', [$field, $value]);
    }

    /**
     * Ajoute une condition WHERE JSON_CONTAINS avec OR
     * 
     * @param string $field Nom du champ JSON
     * @param string $value Valeur JSON à chercher
     * @return self
     * 
     * @example
     * $users->whereJsonContains('roles', '"admin"')->orWhereJsonContains('roles', '"editor"');
     */
    public function orWhereJsonContains(string $field, string $value): self
    {
        return $this->proxy('orWhereJsonContains', [$field, $value]);
    }

    /**
     * Ajoute une condition WHERE JSON_NOT_CONTAINS avec OR
     * 
     * @param string $field Nom du champ JSON
     * @param string $value Valeur JSON à chercher
     * @return self
     * 
     * @example
     * $users->whereJsonNotContains('roles', '"admin"')->orWhereJsonNotContains('roles', '"editor"');
     */
    public function orWhereJsonNotContains(string $field, string $value): self
    {
        return $this->proxy('orWhereJsonNotContains', [$field, $value]);
    }

    /**
     * WHERE JSON_CONTAINS avec OR entre les valeurs
     * 
     * @param string $field Nom du champ JSON
     * @param array $values Liste des valeurs (OR)
     * @return self
     * 
     * @example
     * $users->whereJsonContainsAny('roles', ['admin', 'editor', 'contributor']);
     */
    public function whereJsonContainsAny(string $field, array $values): self
    {
        if (empty($values) || !$this->fieldExists($field)) {
            return $this;
        }
        $this->queryBuilder->whereJsonContainsAny($field, $values);
        return $this;
    }

    /**
     * WHERE JSON_NOT_CONTAINS avec OR entre les valeurs
     * 
     * @param string $field Nom du champ JSON
     * @param array $values Liste des valeurs (OR)
     * @return self
     * 
     * @example
     * $users->whereJsonNotContainsAny('roles', ['admin', 'editor']);
     */
    public function whereJsonNotContainsAny(string $field, array $values): self
    {
        if (empty($values) || !$this->fieldExists($field)) {
            return $this;
        }
        $this->queryBuilder->whereJsonNotContainsAny($field, $values);
        return $this;
    }

    /**
     * WHERE JSON_CONTAINS avec AND entre les valeurs
     * 
     * @param string $field Nom du champ JSON
     * @param array $values Liste des valeurs (AND)
     * @return self
     * 
     * @example
     * $users->whereJsonContainsAll('roles', ['admin', 'editor']);
     */
    public function whereJsonContainsAll(string $field, array $values): self
    {
        if (empty($values) || !$this->fieldExists($field)) {
            return $this;
        }
        $this->queryBuilder->whereJsonContainsAll($field, $values);
        return $this;
    }

    /**
     * WHERE JSON_NOT_CONTAINS avec AND entre les valeurs
     * 
     * @param string $field Nom du champ JSON
     * @param array $values Liste des valeurs (AND)
     * @return self
     * 
     * @example
     * $users->whereJsonNotContainsAll('roles', ['admin', 'editor']);
     */
    public function whereJsonNotContainsAll(string $field, array $values): self
    {
        if (empty($values) || !$this->fieldExists($field)) {
            return $this;
        }
        $this->queryBuilder->whereJsonNotContainsAll($field, $values);
        return $this;
    }

    // ================================================================
    // 5. CONDITIONS DE DATE/TEMPS (PASSERELLES)
    // ================================================================

    /**
     * Ajoute une condition WHERE sur une date
     * 
     * @param string $field Nom du champ
     * @param string $operator Opérateur
     * @param string $date Date (format Y-m-d)
     * @return self
     * 
     * @example
     * $users->whereDate('created_at', '=', '2024-01-01');
     */
    public function whereDate(string $field, string $operator, string $date): self
    {
        return $this->proxy('whereDate', [$field, $operator, $date]);
    }

    /**
     * Ajoute une condition WHERE sur le mois
     * 
     * @param string $field Nom du champ
     * @param string $operator Opérateur
     * @param int $month Mois (1-12)
     * @return self
     * 
     * @example
     * $users->whereMonth('created_at', '=', 1);
     */
    public function whereMonth(string $field, string $operator, int $month): self
    {
        return $this->proxy('whereMonth', [$field, $operator, $month]);
    }

    /**
     * Ajoute une condition WHERE sur l'année
     * 
     * @param string $field Nom du champ
     * @param string $operator Opérateur
     * @param int $year Année
     * @return self
     * 
     * @example
     * $users->whereYear('created_at', '=', 2024);
     */
    public function whereYear(string $field, string $operator, int $year): self
    {
        return $this->proxy('whereYear', [$field, $operator, $year]);
    }

    /**
     * Ajoute une condition WHERE sur le jour
     * 
     * @param string $field Nom du champ
     * @param string $operator Opérateur
     * @param int $day Jour (1-31)
     * @return self
     * 
     * @example
     * $users->whereDay('created_at', '=', 15);
     */
    public function whereDay(string $field, string $operator, int $day): self
    {
        return $this->proxy('whereDay', [$field, $operator, $day]);
    }

    /**
     * Ajoute une condition WHERE sur l'heure
     * 
     * @param string $field Nom du champ
     * @param string $operator Opérateur
     * @param int $hour Heure (0-23)
     * @return self
     * 
     * @example
     * $users->whereHour('created_at', '>', 12);
     */
    public function whereHour(string $field, string $operator, int $hour): self
    {
        return $this->proxy('whereHour', [$field, $operator, $hour]);
    }

    /**
     * Ajoute une condition WHERE sur la minute
     * 
     * @param string $field Nom du champ
     * @param string $operator Opérateur
     * @param int $minute Minute (0-59)
     * @return self
     * 
     * @example
     * $users->whereMinute('created_at', '=', 30);
     */
    public function whereMinute(string $field, string $operator, int $minute): self
    {
        return $this->proxy('whereMinute', [$field, $operator, $minute]);
    }

    // ================================================================
    // 6. CONDITIONS AVANCÉES (PASSERELLES)
    // ================================================================

    /**
     * Ajoute une condition WHERE avec SQL brut
     * 
     * @param string $sql SQL brut
     * @param array $params Paramètres
     * @return self
     * 
     * @example
     * $users->whereRaw('YEAR(created_at) = ?', [2024]);
     */
    public function whereRaw(string $sql, array $params = []): self
    {
        $this->queryBuilder->whereRaw($sql, $params);
        return $this;
    }

    /**
     * Ajoute une condition WHERE avec sous-requête
     * 
     * @param string $field Nom du champ
     * @param string $operator Opérateur
     * @param callable $callback Callback
     * @return self
     * 
     * @example
     * $users->whereSub('id', 'IN', function($q) {
     *     $q->select(['user_id'])->from('orders')->where('status', '=', 'paid');
     * });
     */
    public function whereSub(string $field, string $operator, callable $callback): self
    {
        $this->queryBuilder->whereSub($field, $operator, $callback);
        return $this;
    }

    // ================================================================
    // 7. SOFT DELETE
    // ================================================================

    /**
     * Inclut les documents supprimés
     * 
     * @param string $field Nom du champ de suppression
     * @return self
     * 
     * @example
     * $users->withTrashed()->find();
     */
    public function withTrashed(string $field = 'deleted_at'): self
    {
        return $this;
    }

    /**
     * Filtre uniquement les documents supprimés
     * 
     * @param string $field Nom du champ de suppression
     * @return self
     * 
     * @example
     * $users->onlyTrashed()->find();
     */
    public function onlyTrashed(string $field = 'deleted_at'): self
    {
        return $this->proxy('whereNotNull', [$field]);
    }

    // ================================================================
    // 8. PROJECTION ET TRI (PASSERELLES)
    // ================================================================

    /**
     * Sélectionne les champs à retourner
     * 
     * @param array $fields Liste des champs
     * @return self
     * 
     * @example
     * $users->select(['name', 'email'])->find();
     */
    public function select(array $fields): self
    {
        return $this->proxyDirect('select', [$fields]);
    }

    /**
     * Trie les résultats
     * 
     * @param string|array $field Nom du champ ou tableau de champs
     * @param string $direction ASC ou DESC
     * @return self
     * 
     * @example
     * $users->orderBy('name', 'ASC');
     * $users->orderBy(['name' => 'ASC', 'created_at' => 'DESC']);
     */
    public function orderBy(string|array $field, string $direction = 'ASC'): self
    {
        if (is_array($field)) {
            foreach ($field as $key => $value) {
                if (is_numeric($key) && is_array($value) && isset($value[0])) {
                    $this->queryBuilder->orderBy($value[0], $value[1] ?? 'ASC');
                } else {
                    if ($key == '_field') {
                        $this->queryBuilder->orderByField('uid', $value);
                    } else {
                        if ($this->fieldExists($key)) {
                            $this->queryBuilder->orderBy($key, $value);
                        }
                    }
                }
            }
            return $this;
        }

        if (!$this->fieldExists($field) && !in_array($field, ['_field'])) {
            $schema = $this->schemaManager->getSchema();
            $fields = array_keys($schema);
            $defaultFields = ['created_at', 'updated_at', 'id', 'uid'];
            foreach ($defaultFields as $default) {
                if (in_array($default, $fields)) {
                    $field = $default;
                    break;
                }
            }
            if (!in_array($field, $fields)) {
                $field = $fields[0] ?? 'id';
            }
        }

        $this->queryBuilder->orderBy($field, $direction);
        return $this;
    }

    /**
     * Ordonne les résultats par ordre de valeurs dans un tableau
     * 
     * @param string $field Nom du champ
     * @param array $orderValues Valeurs dans l'ordre souhaité
     * @return self
     * 
     * @example
     * $users->orderByField('uid', ['A7kR9qW2', 'B8sT4vX3']);
     */
    public function orderByField(string $field, array $orderValues): self
    {
        if (empty($orderValues)) {
            return $this;
        }
        return $this->proxyDirect('orderByField', [$field, $orderValues]);
    }

    /**
     * Limite le nombre de résultats
     * 
     * @param int $limit Nombre maximum de résultats
     * @param int|null $offset Décalage
     * @return self
     * 
     * @example
     * $users->limit(10)->find();
     */
    public function limit(int $limit, ?int $offset = null): self
    {
        return $this->proxyDirect('limit', [$limit, $offset]);
    }

    /**
     * Définit le décalage
     * 
     * @param int $offset Décalage
     * @return self
     * 
     * @example
     * $users->limit(10)->offset(20)->find();
     */
    public function offset(int $offset): self
    {
        return $this->proxyDirect('offset', [$offset]);
    }

    /**
     * Ajoute un GROUP BY
     * 
     * @param string|array $fields Champ(s) de regroupement
     * @return self
     * 
     * @example
     * $users->groupBy('status')->select(['status', 'COUNT(*) as count'])->find();
     */
    public function groupBy(string|array $fields): self
    {
        return $this->proxyDirect('groupBy', [$fields]);
    }

    /**
     * Ajoute un HAVING
     * 
     * @param string $field Nom du champ
     * @param string $operator Opérateur
     * @param mixed $value Valeur
     * @return self
     * 
     * @example
     * $users->groupBy('status')->having('COUNT(*)', '>', 10)->find();
     */
    public function having(string $field, string $operator, mixed $value): self
    {
        return $this->proxy('having', [$field, $operator, $value]);
    }

    // ================================================================
    // 9. JOINTURES (PASSERELLES)
    // ================================================================

    /**
     * Ajoute une jointure
     * 
     * @param string $collection Nom de la collection
     * @param string $localField Champ local
     * @param string $operator Opérateur
     * @param string $foreignField Champ étranger
     * @param string $type Type de jointure
     * @return self
     * 
     * @example
     * $users->join('orders', 'uid', '=', 'user_uid')->find();
     */
    public function join(string $collection, string $localField, string $operator, string $foreignField, string $type = 'inner'): self
    {
        $this->queryBuilder->join($collection, $localField, $operator, $foreignField, $type);
        return $this;
    }

    /**
     * Ajoute une LEFT JOIN
     * 
     * @param string $collection Nom de la collection
     * @param string $localField Champ local
     * @param string $operator Opérateur
     * @param string $foreignField Champ étranger
     * @return self
     * 
     * @example
     * $users->leftJoin('orders', 'uid', '=', 'user_uid')->find();
     */
    public function leftJoin(string $collection, string $localField, string $operator, string $foreignField): self
    {
        $this->queryBuilder->leftJoin($collection, $localField, $operator, $foreignField);
        return $this;
    }

    /**
     * Ajoute une INNER JOIN
     * 
     * @param string $collection Nom de la collection
     * @param string $localField Champ local
     * @param string $operator Opérateur
     * @param string $foreignField Champ étranger
     * @return self
     * 
     * @example
     * $users->innerJoin('orders', 'uid', '=', 'user_uid')->find();
     */
    public function innerJoin(string $collection, string $localField, string $operator, string $foreignField): self
    {
        $this->queryBuilder->innerJoin($collection, $localField, $operator, $foreignField);
        return $this;
    }

    /**
     * Jointure complexe avec condition personnalisée
     * 
     * @param string $collection Nom de la collection
     * @param string $onClause Condition de jointure
     * @param string $type Type de jointure
     * @param array $params Paramètres
     * @return self
     * 
     * @example
     * $users->joinComplex('orders', 'users.id = orders.user_id AND orders.status = :status', 'inner', ['status' => 'paid']);
     */
    public function joinComplex(string $collection, string $onClause, string $type = 'inner', array $params = []): self
    {
        $this->queryBuilder->joinComplex($collection, $onClause, $type, $params);
        return $this;
    }

    // ================================================================
    // 10. APPLY CRITERIA
    // ================================================================

    /**
     * Applique des critères de recherche dans différents formats
     * 
     * @param array|callable $criteria Critères de recherche
     * @return self
     * 
     * @example
     * // Format 1: [['field', 'operator', 'value']]
     * $users->applyCriteria([['status', '=', 'active']]);
     * 
     * // Format 2: ['field' => ['operator', 'value']]
     * $users->applyCriteria(['age' => ['>', 18]]);
     * 
     * // Format 3: ['field' => 'value']
     * $users->applyCriteria(['status' => 'active']);
     * 
     * // Format 6: Callback
     * $users->applyCriteria(function($q) {
     *     $q->where('status', '=', 'active')
     *       ->orWhere('status', '=', 'pending');
     * });
     */
    public function applyCriteria(array|callable $criteria): self
    {
        if (is_callable($criteria)) {
            $criteria($this);
            return $this;
        }

        foreach ($criteria as $key => $value) {
            // Format 1: [['field', 'operator', 'value']]
            if (is_array($value) && isset($value[0]) && isset($value[1]) && isset($value[2])) {
                $field = $this->queryBuilder->normalizeField($value[0]);
                $operator = strtolower($value[1]);

                if (in_array($operator, ['in', 'not in'])) {
                    $this->whereIn($field, $value[2]);
                } elseif ($operator === 'between') {
                    $this->whereBetween($field, $value[2][0], $value[2][1]);
                } elseif ($operator === 'like') {
                    $this->whereLike($field, $value[2]);
                } elseif ($operator === 'null' || $operator === 'is null') {
                    $this->whereNull($field);
                } elseif ($operator === 'not null' || $operator === 'is not null') {
                    $this->whereNotNull($field);
                } else {
                    $this->where($field, $value[1], $value[2]);
                }
                continue;
            }

            // Format 2: ['field' => ['operator', 'value']]
            if (is_array($value) && isset($value[0])) {
                $field = $this->queryBuilder->normalizeField($key);
                $operator = strtolower($value[0]);

                if (in_array($operator, ['in', 'not in'])) {
                    $this->whereIn($field, $value[1]);
                } elseif ($operator === 'between') {
                    $this->whereBetween($field, $value[1][0], $value[1][1]);
                } elseif ($operator === 'like') {
                    $this->whereLike($field, $value[1]);
                } elseif ($operator === 'null' || $operator === 'is null') {
                    $this->whereNull($field);
                } elseif ($operator === 'not null' || $operator === 'is not null') {
                    $this->whereNotNull($field);
                } else {
                    $this->where($field, $value[0], $value[1]);
                }
                continue;
            }

            // Format 3: ['field' => 'value']
            $this->where($key, '=', $value);
        }

        return $this;
    }

    /**
     * Alias de applyCriteria()
     * 
     * @param array|callable $criteria Critères
     * @return self
     * 
     * @example
     * $users->whereBy(['status' => 'active'])->find();
     */
    public function whereBy(array|callable $criteria): self
    {
        return $this->applyCriteria($criteria);
    }

    // ================================================================
    // 11. EXÉCUTION
    // ================================================================

    /**
     * Exécute la requête et retourne tous les résultats
     * 
     * @return array Liste des résultats
     * 
     * @example
     * $users = $users->where('age', '>', 18)->fetchAll();
     */
    public function fetchAll(): array
    {
        $startTime = microtime(true);
        $cacheKey = $this->buildCacheKey();

        $cached = $this->sqlFileCacheManager->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $qb = $this->queryBuilder->build();
        $results = $qb->fetchAllAssociative();
        $results = $this->documentManager->hydrateAll($results);

        $executionTime = microtime(true) - $startTime;
        $this->sqlFileCacheManager->set($cacheKey, $results);
        $this->setCache($executionTime);

        return $results;
    }

    /**
     * Exécute la requête et retourne un seul résultat
     * 
     * @return array|null Le résultat ou null
     * 
     * @example
     * $user = $users->where('email', '=', 'alice@example.com')->fetchOne();
     */
    public function fetchOne(): ?array
    {
        $this->limit(1);
        $results = $this->fetchAll();
        return $results[0] ?? null;
    }

    /**
     * Alias de fetchOne()
     * 
     * @return array|null
     * 
     * @example
     * $user = $users->where('email', '=', 'alice@example.com')->findOne();
     */
    public function findOne(): ?array
    {
        return $this->fetchOne();
    }

    /**
     * Compte le nombre de résultats
     * 
     * @return int Nombre de résultats
     * 
     * @example
     * $count = $users->where('status', '=', 'active')->count();
     */
    public function count(): int
    {
        $qb = $this->queryBuilder->build();
        $qb->select('COUNT(*) as count');
        $result = $qb->fetchAssociative();
        return (int)($result['count'] ?? 0);
    }

    // ================================================================
    // 12. AGGREGATIONS
    // ================================================================

    /**
     * Calcule la somme d'un champ
     * 
     * @param string $field Nom du champ
     * @param array|callable|null $criteria Critères
     * @return float
     * 
     * @example
     * $total = $users->sum('age', ['status' => 'active']);
     */
    public function sum(string $field, array|callable|null $criteria = null): float
    {
        if ($criteria) {
            $this->reset()->applyCriteria($criteria);
        }

        $qb = $this->queryBuilder->build();
        $qb->select("SUM(`$field`) as total");
        $result = $qb->fetchAssociative();
        return (float)($result['total'] ?? 0);
    }

    /**
     * Calcule la moyenne d'un champ
     * 
     * @param string $field Nom du champ
     * @param array|callable|null $criteria Critères
     * @return float
     * 
     * @example
     * $avgAge = $users->avg('age', ['status' => 'active']);
     */
    public function avg(string $field, array|callable|null $criteria = null): float
    {
        if ($criteria) {
            $this->reset()->applyCriteria($criteria);
        }

        $qb = $this->queryBuilder->build();
        $qb->select("AVG(`$field`) as average");
        $result = $qb->fetchAssociative();
        return (float)($result['average'] ?? 0);
    }

    /**
     * Calcule le minimum d'un champ
     * 
     * @param string $field Nom du champ
     * @param array|callable|null $criteria Critères
     * @return mixed
     * 
     * @example
     * $minAge = $users->min('age', ['status' => 'active']);
     */
    public function min(string $field, array|callable|null $criteria = null): mixed
    {
        if ($criteria) {
            $this->reset()->applyCriteria($criteria);
        }

        $qb = $this->queryBuilder->build();
        $qb->select("MIN(`$field`) as minimum");
        $result = $qb->fetchAssociative();
        return $result['minimum'] ?? null;
    }

    /**
     * Calcule le maximum d'un champ
     * 
     * @param string $field Nom du champ
     * @param array|callable|null $criteria Critères
     * @return mixed
     * 
     * @example
     * $maxAge = $users->max('age', ['status' => 'active']);
     */
    public function max(string $field, array|callable|null $criteria = null): mixed
    {
        if ($criteria) {
            $this->reset()->applyCriteria($criteria);
        }

        $qb = $this->queryBuilder->build();
        $qb->select("MAX(`$field`) as maximum");
        $result = $qb->fetchAssociative();
        return $result['maximum'] ?? null;
    }

    // ================================================================
    // 13. FIND - RECHERCHE
    // ================================================================

    /**
     * Trouve un document par son UID
     * 
     * @param string|null $uid UID du document
     * @return array|null Le document ou null
     * 
     * @example
     * $user = $users->find('A7kR9qW2');
     */
    public function find(string|null $uid): ?array
    {
        if ($this->collection === '*') {
            return $this->findInAllCollections($uid);
        }

        $cached = $this->cacheManager->get("document_uid_{$uid}");
        if ($cached !== null) {
            return $cached;
        }

        $this->reset();
        $result = $this->where('uid', '=', $uid)->fetchOne();
        if ($result) {
            $this->cacheManager->set("document_uid_{$uid}", $result);
        }
        return $result;
    }

    /**
     * Cherche un document dans toutes les collections
     * 
     * @param string $uid UID du document
     * @return array|null Le document avec sa collection ou null
     */
    private function findInAllCollections(string $uid): ?array
    {
        $sm = $this->connection->createSchemaManager();
        $tables = $sm->listTables();

        foreach ($tables as $table) {
            $tableName = $table->getName();

            if (!$table->hasColumn('uid')) {
                continue;
            }

            try {
                $result = $this->connection->createQueryBuilder()
                    ->select('*')
                    ->from($tableName)
                    ->where('uid = :uid')
                    ->setParameter('uid', $uid)
                    ->executeQuery()
                    ->fetchAssociative();

                if ($result) {
                    $result['_collection'] = $tableName;
                    return $this->documentManager->hydrate($result);
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * Trouve un document dans plusieurs collections
     * 
     * @param string $uid UID du document
     * @param array $collections Liste des collections
     * @return array|null Le document ou null
     */
    public function findInCollections(string $uid, array $collections): ?array
    {
        foreach ($collections as $collection) {
            $db = new MoSQL($collection, $this->connection->getParams());
            $result = $db->find($uid);
            if ($result) {
                $result['_collection'] = $collection;
                return $result;
            }
        }
        return null;
    }

    /**
     * Trouve un document par UID ou lance une exception
     * 
     * @param string $uid UID du document
     * @return array Le document
     * @throws DocumentNotFoundException
     */
    public function findOrFail(string $uid): array
    {
        $result = $this->find($uid);
        if ($result === null) {
            throw new DocumentNotFoundException("Document with UID '{$uid}' not found");
        }
        return $result;
    }

    /**
     * Trouve un document par son ID
     * 
     * @param int $id ID du document
     * @return array|null Le document ou null
     * 
     * @example
     * $user = $users->findById(1);
     */
    public function findById(int $id): ?array
    {
        $cached = $this->cacheManager->get("document_id_{$id}");
        if ($cached !== null) {
            return $cached;
        }

        $this->reset();
        $result = $this->where('id', '=', $id)->fetchOne();
        if ($result) {
            $this->cacheManager->set("document_id_{$id}", $result);
        }
        return $result;
    }

    /**
     * Trouve un document par ID ou UID
     * 
     * @param string|int $identifier UID ou ID
     * @return array|null Le document ou null
     */
    public function findByIdentifier(string|int $identifier): ?array
    {
        if (is_numeric($identifier)) {
            return $this->findById((int)$identifier);
        }
        return $this->find((string)$identifier);
    }

    /**
     * Trouve tous les documents
     * 
     * @return array Liste de tous les documents
     * 
     * @example
     * $allUsers = $users->findAll();
     */
    public function findAll(): array
    {
        $this->reset();
        return $this->fetchAll();
    }

    /**
     * Trouve le premier document
     * 
     * @return array|null Le premier document ou null
     * 
     * @example
     * $firstUser = $users->first();
     */
    public function first(): ?array
    {
        $this->reset();
        $this->limit(1);
        $results = $this->fetchAll();
        return $results[0] ?? null;
    }

    /**
     * Trouve le dernier document
     * 
     * @return array|null Le dernier document ou null
     * 
     * @example
     * $lastUser = $users->last();
     */
    public function last(): ?array
    {
        $this->reset();
        $this->orderBy('id', 'DESC')->limit(1);
        $results = $this->fetchAll();
        return $results[0] ?? null;
    }

    /**
     * Trouve par critères
     * 
     * @param array|callable $criteria Critères
     * @param array|null $orderBy Tri
     * @param int|null $limit Limite
     * @param int|null $offset Offset
     * @return array Résultats
     */
    public function findBy(array|callable $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        $this->reset();
        $this->applyCriteria($criteria);

        if ($orderBy) {
            foreach ($orderBy as $field => $direction) {
                $this->orderBy($field, $direction);
            }
        }

        if ($limit !== null && $limit > 0) {
            $this->limit($limit);
        }

        $this->offset($offset ?? 0);

        return $this->fetchAll();
    }

    /**
     * Trouve un seul document par critères
     * 
     * @param array|callable $criteria Critères
     * @return array|null Le document ou null
     */
    public function findOneBy(array|callable $criteria): ?array
    {
        $this->reset();
        $this->applyCriteria($criteria);
        return $this->fetchOne();
    }

    /**
     * Récupère les UIDs des documents correspondant aux critères
     * 
     * @param array|callable $criteria Critères
     * @param array|null $orderBy Tri
     * @param int|null $limit Limite
     * @param int|null $offset Offset
     * @return array Liste des UIDs
     */
    public function findIDs(array|callable $criteria = [], ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        $this->reset();
        $this->applyCriteria($criteria);

        if ($orderBy) {
            foreach ($orderBy as $field => $direction) {
                $this->orderBy($field, $direction);
            }
        }

        if ($limit !== null && $limit > 0) {
            $this->limit($limit);
        }

        $this->offset($offset ?? 0);

        $results = $this->select(['uid'])->fetchAll();
        return array_column($results, 'uid');
    }

    /**
     * Récupère des UIDs aléatoires
     * 
     * @param array|callable $criteria Critères
     * @param int $limit Nombre d'UIDs
     * @param array|null $orderBy Tri
     * @param int|null $offset Offset
     * @return array Liste des UIDs aléatoires
     */
    public function findIDsRandom(array|callable $criteria = [], int $limit = 10, ?array $orderBy = null, ?int $offset = null): array
    {
        $this->reset();
        $this->applyCriteria($criteria);

        if ($orderBy) {
            foreach ($orderBy as $field => $direction) {
                $this->orderBy($field, $direction);
            }
        }

        if ($limit !== null && $limit > 0) {
            $this->limit($limit);
        }

        $this->offset($offset ?? 0);

        $results = $this->select(['uid'])->fetchAll();
        $uids = array_column($results, 'uid');

        shuffle($uids);

        if ($limit !== null && count($uids) > $limit) {
            $uids = array_slice($uids, 0, $limit);
        }

        return $uids;
    }

    /**
     * Récupère les UIDs depuis la requête en cours
     * 
     * @return array Liste des UIDs
     */
    public function findIDsFromQuery(): array
    {
        $results = $this->select(['uid'])->fetchAll();
        return array_column($results, 'uid');
    }

    /**
     * Compte les documents correspondant aux critères
     * 
     * @param array|callable $criteria Critères
     * @return int Nombre de documents
     */
    public function countBy(array|callable $criteria): int
    {
        $this->reset();
        $this->applyCriteria($criteria);
        return $this->count();
    }

    /**
     * Vérifie si un document existe
     * 
     * @param array|callable $criteria Critères
     * @return bool
     */
    public function exists(array|callable $criteria): bool
    {
        return $this->countBy($criteria) > 0;
    }

    /**
     * Vérifie si un document n'existe pas
     * 
     * @param array|callable $criteria Critères
     * @return bool
     */
    public function missing(array|callable $criteria): bool
    {
        return !$this->exists($criteria);
    }

    // ================================================================
    // 14. CRUD - INSERT
    // ================================================================

    /**
     * Insère un nouveau document
     * 
     * @param array $document Données du document
     * @return string UID généré
     * @throws DuplicateException
     * 
     * @example
     * $uid = $users->insert(['name' => 'Alice', 'email' => 'alice@example.com']);
     */
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
            throw new DatabaseException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Insère plusieurs documents
     * 
     * @param array $documents Liste des documents
     * @return array Liste des UIDs générés
     */
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

    // ================================================================
    // 15. CRUD - UPDATE
    // ================================================================

    /**
     * Met à jour les documents correspondant aux conditions
     * 
     * @param array $data Données à mettre à jour
     * @return int Nombre de lignes affectées
     * 
     * @example
     * $users->where('age', '<', 18)->update(['status' => 'minor']);
     */
    public function update(array $data): int
    {
        $conditions = [];
        $hasJsonCondition = false;

        foreach ($this->queryBuilder->getConditions() as $cond) {
            if ($cond['operator'] === 'JSON_CONTAINS' || $cond['operator'] === 'JSON_EXTRACT') {
                $hasJsonCondition = true;
            } else {
                $conditions[$cond['field']] = $cond['value'];
            }
        }

        if ($hasJsonCondition) {
            $results = $this->select(['id'])->fetchAll();
            $ids = array_column($results, 'id');

            if (empty($ids)) {
                return 0;
            }

            $this->reset();
            $this->whereIn('id', $ids);
            $conditions = [];
            foreach ($this->queryBuilder->getConditions() as $cond) {
                if ($cond['operator'] !== 'JSON_CONTAINS' && $cond['operator'] !== 'JSON_EXTRACT') {
                    $conditions[$cond['field']] = $cond['value'];
                }
            }
        }

        if (empty($conditions)) {
            throw new InvalidArgumentException("No conditions provided for update operation");
        }

        try {
            $result = $this->documentManager->update($data, $conditions);
            $this->cacheManager->clear();
            return $result;
        } catch (\Exception $e) {
            throw new DatabaseException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Met à jour un document par son UID
     * 
     * @param string $uid UID du document
     * @param array $data Données à mettre à jour
     * @return int Nombre de lignes affectées
     */
    public function updateByUid(string $uid, array $data): int
    {
        $this->reset();
        $this->where('uid', '=', $uid);
        $data['uid'] = $uid;
        return $this->update($data);
    }

    /**
     * Met à jour un document par son ID
     * 
     * @param int $id ID du document
     * @param array $data Données à mettre à jour
     * @return int Nombre de lignes affectées
     */
    public function updateById(int $id, array $data): int
    {
        $this->reset();
        $this->where('id', '=', $id);
        return $this->update($data);
    }

    /**
     * Met à jour ou insère un document
     * 
     * @param array $data Données du document
     * @return string UID du document
     */
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

    /**
     * Met à jour ou insère plusieurs documents
     * 
     * @param array $documents Liste des documents
     * @return array Liste des UIDs
     */
    public function upsertMany(array $documents): array
    {
        $uids = [];
        foreach ($documents as $doc) {
            $uids[] = $this->upsert($doc);
        }
        return $uids;
    }

    /**
     * Incrémente des colonnes spécifiques
     * 
     * @param array $fields Liste des champs à incrémenter
     * @param array|callable $criteria Critères
     * @param int $step Pas d'incrémentation
     * @return int Nombre de lignes affectées
     */
    public function increment(array $fields, array|callable $criteria, int $step = 1): int
    {
        $this->reset();
        $this->applyCriteria($criteria);

        $conditions = [];
        foreach ($this->queryBuilder->getConditions() as $cond) {
            if ($cond['operator'] !== 'JSON_CONTAINS' && $cond['operator'] !== 'JSON_EXTRACT') {
                $conditions[$cond['field']] = $cond['value'];
            }
        }

        if (empty($conditions)) {
            throw new InvalidArgumentException("No conditions provided for increment operation");
        }

        $data = [];
        foreach ($fields as $field) {
            $data[$field] = $step;
        }

        try {
            $result = $this->documentManager->increment($data, $conditions);
            $this->cacheManager->clear();
            return $result;
        } catch (\Exception $e) {
            throw new DatabaseException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Décrémente des colonnes spécifiques
     * 
     * @param array $fields Liste des champs à décrémenter
     * @param array|callable $criteria Critères
     * @param int $step Pas de décrémentation
     * @return int Nombre de lignes affectées
     */
    public function decrement(array $fields, array|callable $criteria, int $step = 1): int
    {
        $this->reset();
        $this->applyCriteria($criteria);

        $conditions = [];
        foreach ($this->queryBuilder->getConditions() as $cond) {
            if ($cond['operator'] !== 'JSON_CONTAINS' && $cond['operator'] !== 'JSON_EXTRACT') {
                $conditions[$cond['field']] = $cond['value'];
            }
        }

        if (empty($conditions)) {
            throw new InvalidArgumentException("No conditions provided for decrement operation");
        }

        $data = [];
        foreach ($fields as $field) {
            $data[$field] = $step;
        }

        try {
            $result = $this->documentManager->decrement($data, $conditions);
            $this->cacheManager->clear();
            return $result;
        } catch (\Exception $e) {
            throw new DatabaseException($e->getMessage(), 0, $e);
        }
    }

    // ================================================================
    // 16. CRUD - DELETE
    // ================================================================

    /**
     * Supprime les documents correspondant aux conditions
     * 
     * @return int Nombre de lignes supprimées
     * 
     * @example
     * $users->where('age', '<', 18)->delete();
     */
    public function delete(): int
    {
        $conditions = [];
        $hasJsonCondition = false;

        foreach ($this->queryBuilder->getConditions() as $cond) {
            if ($cond['operator'] === 'JSON_CONTAINS' || $cond['operator'] === 'JSON_EXTRACT') {
                $hasJsonCondition = true;
            } else {
                $conditions[$cond['field']] = $cond['value'];
            }
        }

        if ($hasJsonCondition) {
            $results = $this->select(['id'])->fetchAll();
            $ids = array_column($results, 'id');

            if (empty($ids)) {
                return 0;
            }

            $this->reset();
            $this->whereIn('id', $ids);
            $conditions = [];
            foreach ($this->queryBuilder->getConditions() as $cond) {
                if ($cond['operator'] !== 'JSON_CONTAINS' && $cond['operator'] !== 'JSON_EXTRACT') {
                    $conditions[$cond['field']] = $cond['value'];
                }
            }
        }

        if (empty($conditions)) {
            throw new InvalidArgumentException("No conditions provided for delete operation");
        }

        try {
            $result = $this->documentManager->delete($conditions);
            $this->cacheManager->clear();
            return $result;
        } catch (\Exception $e) {
            throw new DatabaseException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Supprime un document par son UID
     * 
     * @param string $uid UID du document
     * @return int Nombre de lignes supprimées
     */
    public function deleteByUid(string $uid): int
    {
        $this->reset();
        $this->where('uid', '=', $uid);
        return $this->delete();
    }

    /**
     * Supprime un document par son ID
     * 
     * @param int $id ID du document
     * @return int Nombre de lignes supprimées
     */
    public function deleteById(int $id): int
    {
        $this->reset();
        $this->where('id', '=', $id);
        return $this->delete();
    }

    /**
     * Supprime tous les documents
     * 
     * @return int Nombre de lignes supprimées
     */
    public function deleteAll(): int
    {
        $this->reset();
        return $this->documentManager->delete([]);
    }

    /**
     * Vide la table
     * 
     * @return void
     */
    public function truncate(): void
    {
        $this->documentManager->truncate();
        $this->cacheManager->clear();
    }

    // ================================================================
    // 17. TRANSACTIONS
    // ================================================================

    /**
     * Démarre une transaction
     * 
     * @return self
     */
    public function beginTransaction(): self
    {
        $this->connection->beginTransaction();
        return $this;
    }

    /**
     * Valide une transaction
     * 
     * @return self
     */
    public function commit(): self
    {
        $this->connection->commit();
        return $this;
    }

    /**
     * Annule une transaction
     * 
     * @return self
     */
    public function rollBack(): self
    {
        $this->connection->rollBack();
        return $this;
    }

    /**
     * Exécute une transaction avec callback
     * 
     * @param callable $callback Fonction à exécuter
     * @return mixed Résultat du callback
     * @throws \Exception
     */
    public function transaction(callable $callback): mixed
    {
        $this->connection->beginTransaction();
        try {
            $result = $callback($this);
            $this->connection->commit();
            return $result;
        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    // ================================================================
    // 18. CHUNK / BATCH
    // ================================================================

    /**
     * Parcourt les résultats par lots
     * 
     * @param int $size Taille du lot
     * @param callable $callback Fonction exécutée sur chaque lot
     * @return self
     * 
     * @example
     * $users->chunk(100, function($chunk) {
     *     foreach ($chunk as $user) {
     *         // Traiter l'utilisateur
     *     }
     * });
     */
    public function chunk(int $size, callable $callback): self
    {
        $page = 0;
        $queryBuilder = clone $this->queryBuilder;

        do {
            $offset = $page * $size;
            $this->queryBuilder = clone $queryBuilder;
            $this->limit($size, $offset);
            $results = $this->fetchAll();

            if (empty($results)) {
                break;
            }

            $callback($results);
            $page++;
        } while (count($results) === $size);

        return $this;
    }

    // ================================================================
    // 19. CACHE
    // ================================================================

    /**
     * Active ou désactive le cache
     * 
     * @param bool $enabled Activer ou désactiver
     * @param int|null $ttl Durée de vie en secondes
     * @return self
     */
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

    /**
     * Invalide le cache d'un document
     * 
     * @param string|int $identifier UID ou ID du document
     * @return self
     */
    public function invalidate(string|int $identifier): self
    {
        $this->cacheManager->invalidateDocument($identifier);
        return $this;
    }

    /**
     * Invalide tout le cache
     * 
     * @return self
     */
    public function invalidateAll(): self
    {
        $this->cacheManager->clear();
        return $this;
    }

    // ================================================================
    // 20. CONVERSION UID ↔ ID
    // ================================================================

    /**
     * Trouve l'ID auto-incrémenté à partir d'un UID
     * 
     * @param string $uid UID du document
     * @return int|null ID ou null
     */
    public function findIdByUid(string $uid): ?int
    {
        if (isset($this->uidCache[$uid])) {
            return $this->uidCache[$uid];
        }

        $this->reset();
        $result = $this->where('uid', '=', $uid)->select(['id'])->fetchOne();
        if ($result) {
            $this->uidCache[$uid] = (int)$result['id'];
            return (int)$result['id'];
        }
        return null;
    }

    /**
     * Trouve l'UID à partir d'un ID
     * 
     * @param int $id ID du document
     * @return string|null UID ou null
     */
    public function findUidById(int $id): ?string
    {
        $this->reset();
        $result = $this->where('id', '=', $id)->select(['uid'])->fetchOne();
        return $result['uid'] ?? null;
    }

    // ================================================================
    // 21. UTILITAIRES
    // ================================================================

    /**
     * Réinitialise toutes les conditions
     * 
     * @return self
     */
    public function reset(): self
    {
        $this->queryBuilder->reset();
        return $this;
    }

    /**
     * Retourne la connexion Doctrine
     * 
     * @return Connection
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Retourne le schéma de la table
     * 
     * @return array
     */
    public function getSchema(): array
    {
        return $this->schemaManager->getSchema();
    }

    /**
     * Retourne le nom de la table
     * 
     * @return string
     */
    public function getTableName(): string
    {
        return $this->schemaManager->getTableName();
    }

    /**
     * Pagination simple
     * 
     * @param int $perPage Nombre d'éléments par page
     * @param int $page Numéro de la page
     * @return array Données paginées
     */
    public function paginate(int $perPage = 10, int $page = 1): array
    {
        $offset = ($page - 1) * $perPage;
        $this->limit($perPage, $offset);

        $items = $this->fetchAll();
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

    /**
     * Retourne les valeurs distinctes d'un champ
     * 
     * @param string $field Nom du champ
     * @return array Valeurs distinctes
     */
    public function distinct(string $field): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select("DISTINCT `$field`")
            ->from($this->getTableName());
        $results = $qb->fetchAllAssociative();
        return array_column($results, $field);
    }

    /**
     * Récupère une colonne
     * 
     * @param string $value Colonne des valeurs
     * @param string|null $key Colonne des clés
     * @return array
     */
    public function pluck(string $value, ?string $key = null): array
    {
        $results = $this->fetchAll();
        if ($key === null) {
            return array_column($results, $value);
        }

        $plucked = [];
        foreach ($results as $row) {
            $plucked[$row[$key] ?? ''] = $row[$value] ?? null;
        }
        return $plucked;
    }

    /**
     * Retourne des paires clé-valeur
     * 
     * @param string $keyField Champ clé
     * @param string $valueField Champ valeur
     * @return array
     */
    public function pairs(string $keyField, string $valueField): array
    {
        return $this->pluck($valueField, $keyField);
    }

    /**
     * Supprime la table
     * 
     * @return void
     */
    public function drop(): void
    {
        $this->schemaManager->dropTable();
        $this->cacheManager->clear();
    }

    /**
     * Convertit les résultats en tableau
     * 
     * @return array
     */
    public function toArray(): array
    {
        return $this->fetchAll();
    }

    /**
     * Convertit les résultats en JSON
     * 
     * @param int $flags Options JSON
     * @return string
     */
    public function toJson(int $flags = JSON_PRETTY_PRINT): string
    {
        return json_encode($this->fetchAll(), $flags);
    }

    /**
     * Debug : dump and die les résultats
     * 
     * @return void
     */
    public function dd(): void
    {
        dd($this->fetchAll());
    }

    /**
     * Exécute une requête SQL brute
     * 
     * @param string $sql Requête SQL
     * @param array $params Paramètres
     * @return array|int Résultats ou nombre de lignes affectées
     */
    public function rawQuery(string $sql, array $params = []): array|int
    {
        if (preg_match('/(FROM|JOIN|UPDATE|INTO)\s+`?_`?/i', $sql)) {
            return str_starts_with(strtoupper(trim($sql)), 'SELECT') ? [] : 0;
        }

        $sql = preg_replace_callback('/(FROM|JOIN|UPDATE|INTO|TABLE)\s+([a-zA-Z0-9_-]+)/i', function ($matches) {
            return $matches[1] . ' ' . str_replace('-', '_', $matches[2]);
        }, $sql);

        if (str_starts_with(strtoupper(trim($sql)), 'SELECT')) {
            return $this->connection->executeQuery($sql, $params)->fetchAllAssociative();
        }
        return $this->connection->executeStatement($sql, $params);
    }

    /**
     * Clone la requête
     * 
     * @return self
     */
    public function clone(): self
    {
        return clone $this;
    }

    /**
     * Retourne les informations de la requête en cours
     * 
     * @return array Informations de la requête
     */
    public function getData(): array
    {
        $qb = $this->queryBuilder;

        return [
            'collection' => $this->collection,
            'conditions' => $qb->getConditions(),
            'projection' => $qb->getProjection(),
            'order_by' => $qb->getOrderBy(),
            'limit' => $qb->getLimit(),
            'offset' => $qb->getOffset(),
            'joins' => $qb->getJoins(),
            'groups' => $qb->getGroups(),
            'group_by' => $qb->getGroupBy(),
            'having' => $qb->getHaving(),
            'sql' => $qb->build()->getSQL(),
            'params' => $qb->build()->getParameters()
        ];
    }

    // ================================================================
    // 22. CONDITIONNELLE
    // ================================================================

    /**
     * Exécute un callback si la condition est vraie
     * 
     * @param mixed $condition Condition à évaluer
     * @param callable $callback Callback exécuté si true
     * @param callable|null $default Callback exécuté si false
     * @return self
     */
    public function when(mixed $condition, callable $callback, ?callable $default = null): self
    {
        if ($condition) {
            $callback($this);
        } elseif ($default) {
            $default($this);
        }
        return $this;
    }

    /**
     * Exécute un callback si la condition est fausse
     * 
     * @param mixed $condition Condition à évaluer
     * @param callable $callback Callback exécuté si false
     * @param callable|null $default Callback exécuté si true
     * @return self
     */
    public function unless(mixed $condition, callable $callback, ?callable $default = null): self
    {
        return $this->when(!$condition, $callback, $default);
    }

    // ================================================================
    // 23. MÉTHODES MAGIQUES
    // ================================================================

    /**
     * Méthodes magiques pour findBy* et findOneBy*
     * 
     * @param string $method Nom de la méthode
     * @param array $arguments Arguments
     * @return mixed
     * @throws \BadMethodCallException
     * 
     * @example
     * $users = $users->findByAge(18);
     * $users = $users->findByAgeGreaterThan(18);
     * $user = $users->findOneByEmail('alice@example.com');
     */
    public function __call(string $method, array $arguments)
    {
        // findBy*
        if (str_starts_with($method, 'findBy') && strlen($method) > 6) {
            $field = substr($method, 6);
            $operator = '=';
            $operators = ['GreaterThan', 'LessThan', 'GreaterThanOrEqual', 'LessThanOrEqual', 'Like', 'In'];

            foreach ($operators as $op) {
                if (str_ends_with($field, $op)) {
                    $field = substr($field, 0, -strlen($op));
                    $operator = match ($op) {
                        'GreaterThan' => '>',
                        'LessThan' => '<',
                        'GreaterThanOrEqual' => '>=',
                        'LessThanOrEqual' => '<=',
                        'Like' => 'LIKE',
                        'In' => 'IN',
                        default => '=',
                    };
                    break;
                }
            }

            $this->reset();

            if ($operator === 'IN') {
                $this->whereIn($field, $arguments[0]);
            } else {
                $this->where($field, $operator, $arguments[0]);
            }

            if (isset($arguments[1]) && is_array($arguments[1])) {
                foreach ($arguments[1] as $f => $d) {
                    $this->orderBy($f, $d);
                }
            }

            if (isset($arguments[2])) {
                $this->limit($arguments[2]);
            }

            return $this->fetchAll();
        }

        // findOneBy*
        if (str_starts_with($method, 'findOneBy') && strlen($method) > 9) {
            $field = substr($method, 9);
            $operator = '=';
            $operators = ['GreaterThan', 'LessThan', 'GreaterThanOrEqual', 'LessThanOrEqual', 'Like'];

            foreach ($operators as $op) {
                if (str_ends_with($field, $op)) {
                    $field = substr($field, 0, -strlen($op));
                    $operator = match ($op) {
                        'GreaterThan' => '>',
                        'LessThan' => '<',
                        'GreaterThanOrEqual' => '>=',
                        'LessThanOrEqual' => '<=',
                        'Like' => 'LIKE',
                        default => '=',
                    };
                    break;
                }
            }

            $this->reset();
            $this->where($field, $operator, $arguments[0]);
            return $this->fetchOne();
        }

        throw new \BadMethodCallException("Method '{$method}' does not exist");
    }

    // ================================================================
    // 24. MÉTHODES PRIVÉES
    // ================================================================

    /**
     * Construit la clé de cache pour la requête courante
     * 
     * @return string Clé de cache
     */
    private function buildCacheKey(): string
    {
        $qb = $this->queryBuilder;
        $parts = [
            'collection' => $this->collection,
            'conditions' => $qb->getConditions(),
            'projection' => $qb->getProjection(),
            'order_by' => $qb->getOrderBy(),
            'limit' => $qb->getLimit(),
            'offset' => $qb->getOffset(),
            'joins' => $qb->getJoins(),
            'groups' => $qb->getGroups(),
            'group_by' => $qb->getGroupBy(),
            'having' => $qb->getHaving(),
        ];
        return 'sql_' . md5(json_encode($parts));
    }

    /**
     * Sauvegarde la requête dans le cache
     * 
     * @param float|null $executionTime Temps d'exécution
     * @return void
     */
    private function setCache($executionTime = null): void
    {
        $dirname = dirname(__DIR__, 4);
        $time = (new \DateTime())->format('ymd-His');
        $DS = DIRECTORY_SEPARATOR;
        $data = $this->getData();
        $collection = $data['collection'];

        if (!empty($collection) && $collection != '*') {
            $sql = $data['sql'];
            $uniq = ($data['collection'] ?? 'unknown') . '__' . $time . '__' . substr(md5(json_encode($sql . $time)), 0, 4);
            $dir = $dirname . $DS . 'logs' . $DS . 'sql';

            if (isset($_GET['_ajaxify']) && file_exists($dir)) {
                unset($_GET['_ajaxify']);
                array_map('unlink', glob("$dir/*.*"));
                rmdir($dir);
            }

            if (!file_exists($dir)) {
                mkdir($dir, 0777, true);
            }

            $executionTimeMs = $executionTime !== null ? round($executionTime * 1000, 2) : null;

            $data['execution_time_ms'] = $executionTimeMs;
            $data['execution_time'] = $executionTimeMs !== null ? $executionTimeMs . ' ms' : 'N/A';
            $data['executed_at'] = date('Y-m-d H:i:s');

            $filename = $dir . $DS . trim(preg_replace('/[\\\\\/:\*\?"<>\|]/', '_', $uniq), "_") . ".json";
            file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT));
        }
    }
}
