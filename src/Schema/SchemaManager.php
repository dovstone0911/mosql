<?php

namespace Dovstone\MoSQL\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Dovstone\MoSQL\Exception\SchemaException;

/**
 * Détection automatique des types SQL
 */
class TypeGuesser
{
    public static function guess($value): string
    {
        if ($value === null) {
            return 'string';
        }

        $type = gettype($value);

        return match ($type) {
            'integer' => 'integer',
            'double' => 'float',
            'boolean' => 'boolean',
            'array', 'object' => 'json',
            'string' => self::guessStringType($value),
            default => 'string',
        };
    }

    private static function guessStringType(string $value): string
    {
        // Vérifier si c'est une date
        if (strtotime($value) !== false) {
            return 'datetime';
        }

        // Vérifier la longueur
        if (strlen($value) > 255) {
            return 'text';
        }

        return 'string';
    }

    public static function guessLength($value): ?int
    {
        if (!is_string($value)) {
            return null;
        }

        $len = strlen($value);
        if ($len > 255) {
            return null; // TEXT
        }
        if ($len > 100) {
            return 255;
        }
        if ($len > 50) {
            return 100;
        }
        return 50;
    }
}

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

    public function __construct(Connection $connection, string $collection, array $options = [])
    {
        $this->connection = $connection;
        $this->prefix = $options['table_prefix'] ?? '';
        $this->autoCreate = $options['auto_create_schema'] ?? true;
        $this->tableName = $this->prefix . $collection;
    }

    public function ensureTableExists(): void
    {
        try {
            $sm = $this->connection->createSchemaManager();
            if (!$sm->tablesExist([$this->tableName])) {
                $this->createTable();
            }
            $this->loadSchema();
        } catch (\Exception $e) {
            throw new SchemaException("Failed to ensure table exists: " . $e->getMessage(), 0, $e);
        }
    }

    private function createTable(): void
    {
        $sm = $this->connection->createSchemaManager();
        $schema = $sm->createSchema();
        $table = $schema->createTable($this->tableName);

        // Clé primaire auto-incrémentée
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);

        // UID unique (clé publique)
        $table->addColumn('uid', 'string', ['length' => 10, 'notnull' => true]);
        $table->addUniqueIndex(['uid']);
        $table->addIndex(['uid'], 'idx_' . $this->tableName . '_uid');

        // Timestamps
        $table->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP']);
        $table->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP']);

        $sm->createTable($table);
    }

    public function adaptSchema(array $document): void
    {
        if (!$this->autoCreate) {
            return;
        }

        try {
            $sm = $this->connection->createSchemaManager();
            $schema = $sm->createSchema();
            $table = $schema->getTable($this->tableName);

            $columnsToAdd = [];

            foreach ($document as $field => $value) {
                if (in_array($field, ['id', 'uid', 'created_at', 'updated_at'])) {
                    continue;
                }

                if (!$table->hasColumn($field)) {
                    $type = TypeGuesser::guess($value);
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
        $sm = $this->connection->createSchemaManager();
        $schema = $sm->createSchema();
        $table = $schema->getTable($this->tableName);

        foreach ($columns as $name => $config) {
            $type = Type::getType($config['type']);
            $options = ['notnull' => false];

            if ($config['length'] ?? null) {
                $options['length'] = $config['length'];
            }

            $table->addColumn($name, $type, $options);
        }

        $sm->alterTable($table);
        $this->loadSchema();
    }

    private function loadSchema(): void
    {
        $sm = $this->connection->createSchemaManager();
        $schema = $sm->createSchema();
        $table = $schema->getTable($this->tableName);

        $this->schema = [];
        foreach ($table->getColumns() as $column) {
            $this->schema[$column->getName()] = [
                'type' => $column->getType()->getName(),
                'length' => $column->getLength(),
                'notnull' => $column->getNotnull(),
            ];
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
            $this->connection->executeStatement("TRUNCATE TABLE {$this->tableName}");
        } catch (\Exception $e) {
            throw new SchemaException("Failed to truncate table: " . $e->getMessage(), 0, $e);
        }
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }
}
