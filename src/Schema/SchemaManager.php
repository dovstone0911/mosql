<?php

namespace Dovstone\MoSQL\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Dovstone\MoSQL\Exception\SchemaException;
use Dovstone\MoSQL\Schema\TypeGuesser;

/**
 * Gestionnaire de schéma avec création automatique
 */
class SchemaManager
{
    private Connection $connection;
    private string $tableName;
    private string $prefix;
    private bool $autoCreate;
    private array $schema = [];

    /**
     * Colonnes "cœur" gérées par le framework lui-même (pas par l'utilisateur).
     * Elles sont volontairement exclues de adaptSchema() car elles ont un
     * typage et des valeurs par défaut spécifiques (ex: CURRENT_TIMESTAMP),
     * donc on ne peut pas les déduire via TypeGuesser comme un champ métier.
     */
    private const CORE_COLUMNS = ['id', 'uid', 'created_at', 'updated_at', 'coll_name'];

    public function __construct(Connection $connection, string $collection, array $options = [])
    {
        $this->connection = $connection;
        $this->prefix = $options['table_prefix'] ?? '';
        $this->autoCreate = $options['auto_create_schema'] ?? true;
        $this->tableName = preg_replace('/[^a-zA-Z0-9_]/', '_', $this->prefix . $collection);
    }

    public function ensureTableExists(): void
    {
        try {
            $sm = $this->connection->createSchemaManager();
            if (!$sm->tablesExist([$this->tableName])) {
                $this->createTable();
            }
            $this->loadSchema();

            // La table existait peut-être déjà avant l'introduction de ce
            // framework (ou d'une version antérieure), sans les colonnes
            // "cœur" attendues. adaptSchema() les exclut volontairement,
            // donc un simple insert ne les créera jamais : on les répare ici.
            if ($this->autoCreate) {
                $this->ensureCoreColumns();
            }
        } catch (\Exception $e) {
            throw new SchemaException("Failed to ensure table exists: " . $e->getMessage(), 0, $e);
        }
    }

    private function createTable(): void
    {
        $sm = $this->connection->createSchemaManager();
        $schema = $sm->createSchema();
        $table = $schema->createTable($this->tableName);

        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);

        $table->addColumn('uid', 'string', ['length' => 10, 'notnull' => true]);
        $table->addUniqueIndex(['uid']);

        // 🔥 Ajouter coll_name si la table n'est pas '*'
        if ($this->tableName !== '*') {
            $table->addColumn('coll_name', 'string', ['length' => 100, 'notnull' => false]);
        }

        $cleanTableName = preg_replace('/[^a-zA-Z0-9_]/', '_', $this->tableName);
        $indexName = 'idx_' . $cleanTableName . '_uid';
        if (strlen($indexName) > 64) {
            $indexName = 'idx_' . substr(md5($this->tableName), 0, 20) . '_uid';
        }
        $table->addIndex(['uid'], $indexName);

        $table->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP']);
        $table->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP']);

        $sm->createTable($table);
    }

    /**
     * Auto-répare une table déjà existante à laquelle il manquerait des
     * colonnes "cœur" (created_at, updated_at, coll_name). N'agit que si
     * une colonne manque réellement, donc coût quasi nul sur une table déjà
     * complète (juste une comparaison en mémoire sur $this->schema, déjà
     * chargé par loadSchema()).
     *
     * Note : 'id' et 'uid' ne sont volontairement PAS auto-réparées ici,
     * car ce sont la clé primaire et une contrainte d'unicité — les ajouter
     * après coup sur une table qui contient déjà des lignes est risqué
     * (valeurs à backfiller, contrainte UNIQUE à satisfaire) et doit être
     * fait via une vraie migration, pas silencieusement au runtime.
     */
    private function ensureCoreColumns(): void
    {
        $missing = [];

        if (!isset($this->schema['created_at'])) {
            $missing['created_at'] = ['type' => Types::DATETIME_MUTABLE, 'options' => ['notnull' => false]];
        }

        if (!isset($this->schema['updated_at'])) {
            $missing['updated_at'] = ['type' => Types::DATETIME_MUTABLE, 'options' => ['notnull' => false]];
        }

        if ($this->tableName !== '*' && !isset($this->schema['coll_name'])) {
            $missing['coll_name'] = ['type' => Types::STRING, 'options' => ['length' => 100, 'notnull' => false]];
        }

        if (empty($missing)) {
            return;
        }

        foreach ($missing as $name => $config) {
            $this->addCoreColumn($name, $config['type'], $config['options']);
        }

        $this->loadSchema();
    }

    /**
     * Ajoute une colonne "cœur" via ALTER TABLE, avec un DEFAULT
     * CURRENT_TIMESTAMP pour les colonnes datetime (created_at/updated_at).
     * Tolère une erreur "colonne déjà existante" pour rester safe en cas de
     * requêtes concurrentes qui tenteraient la même réparation en parallèle.
     */
    private function addCoreColumn(string $name, string $dbalTypeName, array $options = []): void
    {
        try {
            $type = Type::getType($dbalTypeName);
            $platform = $this->connection->getDatabasePlatform();
            $sqlDeclaration = $type->getSQLDeclaration($options, $platform);

            $default = $dbalTypeName === Types::DATETIME_MUTABLE ? ' DEFAULT CURRENT_TIMESTAMP' : '';

            $this->connection->executeStatement(sprintf(
                'ALTER TABLE %s ADD COLUMN %s %s%s',
                $this->connection->quoteIdentifier($this->tableName),
                $this->connection->quoteIdentifier($name),
                $sqlDeclaration,
                $default
            ));
        } catch (\Exception $e) {
            // Colonne déjà ajoutée entre-temps (course concurrente) : on
            // ignore, loadSchema() rechargera l'état réel juste après.
            if (!$this->isDuplicateColumnError($e)) {
                throw $e;
            }
        }
    }

    private function isDuplicateColumnError(\Exception $e): bool
    {
        $message = $e->getMessage();
        return stripos($message, 'duplicate column') !== false
            || stripos($message, 'already exists') !== false
            || str_contains($message, '1060'); // MySQL: Duplicate column name
    }

    public function adaptSchema(array $document): void
    {
        if (!$this->autoCreate) {
            return;
        }

        try {
            $sm = $this->connection->createSchemaManager();
            // On liste les colonnes existantes via listTableColumns (plus rapide que createSchema)
            $existingColumns = [];
            if ($sm->tablesExist([$this->tableName])) {
                foreach ($sm->listTableColumns($this->tableName) as $col) {
                    $existingColumns[$col->getName()] = true;
                }
            }

            $columnsToAdd = [];
            foreach ($document as $field => $value) {
                if (in_array($field, self::CORE_COLUMNS)) {
                    continue;
                }
                if (!isset($existingColumns[$field])) {
                    $type = TypeGuesser::guess($value); // retourne string: 'json', 'string'...
                    $length = TypeGuesser::guessLength($value);
                    $columnsToAdd[$field] = ['type' => $type, 'length' => $length];
                }
            }

            if (!empty($columnsToAdd)) {
                $this->addColumns($columnsToAdd);
            }
        } catch (\Exception $e) {
            throw new SchemaException("Failed to adapt schema: " . $e->getMessage(), 0, $e);
        }
    }

    private function addColumns(array $columns): void
    {
        try {
            $map = [
                'string'   => Types::STRING,
                'integer'  => Types::INTEGER,
                'float'    => Types::FLOAT,
                'boolean'  => Types::BOOLEAN,
                'json'     => Types::JSON,
                'text'     => Types::TEXT,
                'datetime' => Types::DATETIME_MUTABLE,
            ];

            foreach ($columns as $name => $config) {
                $typeName = $config['type'] ?? 'string';

                if ($typeName instanceof Type) {
                    $typeName = $typeName->getName();
                }
                $typeName = strtolower((string) $typeName);
                $dbalTypeName = $map[$typeName] ?? Types::STRING;

                $type = Type::getType($dbalTypeName);
                $platform = $this->connection->getDatabasePlatform();

                $options = ['notnull' => false];
                if (!empty($config['length'])) {
                    $options['length'] = (int) $config['length'];
                }

                $sqlDeclaration = $type->getSQLDeclaration($options, $platform);

                $this->connection->executeStatement(sprintf(
                    'ALTER TABLE %s ADD COLUMN %s %s',
                    $this->connection->quoteIdentifier($this->tableName),
                    $this->connection->quoteIdentifier($name),
                    $sqlDeclaration
                ));
            }

            $this->loadSchema();
        } catch (\Exception $e) {
            throw new SchemaException("Failed to add columns: " . $e->getMessage(), 0, $e);
        }
    }

    private function loadSchema(): void
    {
        try {
            $sm = $this->connection->createSchemaManager();
            if (!$sm->tablesExist([$this->tableName])) {
                $this->schema = [];
                return;
            }

            $this->schema = [];
            foreach ($sm->listTableColumns($this->tableName) as $column) {
                $this->schema[$column->getName()] = [
                    'type' => $column->getType()->getName(),
                    'length' => $column->getLength(),
                    'notnull' => $column->getNotnull(),
                ];
            }
        } catch (\Exception $e) {
            throw new SchemaException("Failed to load schema: " . $e->getMessage(), 0, $e);
        }
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function getSchema(): array
    {
        return $this->schema;
    }

    public function hasColumn(string $field): bool
    {
        return isset($this->schema[$field]);
    }

    public function getColumnType(string $field): ?string
    {
        return $this->schema[$field]['type'] ?? null;
    }

    public function dropTable(): void
    {
        try {
            $sm = $this->connection->createSchemaManager();
            if ($sm->tablesExist([$this->tableName])) {
                $sm->dropTable($this->tableName);
            }
            $this->schema = [];
        } catch (\Exception $e) {
            throw new SchemaException("Failed to drop table: " . $e->getMessage(), 0, $e);
        }
    }

    public function truncate(): void
    {
        try {
            $platform = $this->connection->getDatabasePlatform()->getName();
            if ($platform === 'sqlite') {
                $this->connection->executeStatement("DELETE FROM {$this->tableName}");
            } else {
                $this->connection->executeStatement("TRUNCATE TABLE {$this->tableName}");
            }
        } catch (\Exception $e) {
            throw new SchemaException("Failed to truncate table: " . $e->getMessage(), 0, $e);
        }
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }
}
