<?php

namespace Dovstone\MoSQL\Query;

use Doctrine\DBAL\Connection;

/**
 * Exécuteur de requêtes SQL brutes avec vérification automatique des tables et colonnes
 */
class RawQueryExecutor
{
    private Connection $connection;
    private array $processedTables = [];

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Exécute une requête SQL brute avec vérification automatique des tables
     * 
     * @param string $sql Requête SQL
     * @param array $params Paramètres
     * @return array|int Résultats ou nombre de lignes affectées
     */
    public function rawQuery(string $sql, array $params = []): array|int
    {
        // 1. Vérifier les tables virtuelles protégées
        if (preg_match('/(FROM|JOIN|UPDATE|INTO)\s+`?_`?/i', $sql)) {
            return str_starts_with(strtoupper(trim($sql)), 'SELECT') ? [] : 0;
        }

        // 2. Extraire toutes les tables de la requête
        $tables = $this->extractTablesFromQuery($sql);

        // 3. Vérifier et créer les tables manquantes
        foreach ($tables as $table) {
            $this->ensureTableExists($table);
        }

        // 4. Nettoyer le SQL
        $sql = $this->sanitizeSql($sql);

        // 5. Exécuter la requête
        try {
            if (str_starts_with(strtoupper(trim($sql)), 'SELECT')) {
                return $this->connection->executeQuery($sql, $params)->fetchAllAssociative();
            }
            return $this->connection->executeStatement($sql, $params);
        } catch (\Doctrine\DBAL\Exception\InvalidFieldNameException $e) {
            // Si une colonne est manquante, on tente de l'ajouter
            if (preg_match("/Unknown column '([^']+)'/", $e->getMessage(), $matches)) {
                $missingColumn = $matches[1];
                $this->addMissingColumnToTables($tables, $missingColumn);

                // Réessayer la requête
                if (str_starts_with(strtoupper(trim($sql)), 'SELECT')) {
                    return $this->connection->executeQuery($sql, $params)->fetchAllAssociative();
                }
                return $this->connection->executeStatement($sql, $params);
            }
            throw $e;
        }
    }

    /**
     * Extrait toutes les tables impliquées dans la requête
     * 
     * @param string $sql
     * @return array
     */
    private function extractTablesFromQuery(string $sql): array
    {
        $tables = [];

        $patterns = [
            '/FROM\s+([a-zA-Z0-9_-]+)/i',
            '/JOIN\s+([a-zA-Z0-9_-]+)/i',
            '/UPDATE\s+([a-zA-Z0-9_-]+)/i',
            '/INTO\s+([a-zA-Z0-9_-]+)/i',
            '/TABLE\s+([a-zA-Z0-9_-]+)/i',
            '/INSERT\s+INTO\s+([a-zA-Z0-9_-]+)/i',
            '/DELETE\s+FROM\s+([a-zA-Z0-9_-]+)/i',
            '/TRUNCATE\s+TABLE\s+([a-zA-Z0-9_-]+)/i',
            '/ALTER\s+TABLE\s+([a-zA-Z0-9_-]+)/i',
            '/DROP\s+TABLE\s+([a-zA-Z0-9_-]+)/i',
            '/CREATE\s+TABLE\s+([a-zA-Z0-9_-]+)/i',
            '/REPLACE\s+INTO\s+([a-zA-Z0-9_-]+)/i',
            '/FROM\s+([a-zA-Z0-9_-]+)\s+AS\s+/i',
            '/JOIN\s+([a-zA-Z0-9_-]+)\s+AS\s+/i',
        ];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $sql, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $table) {
                    $table = trim($table);
                    $table = preg_replace('/[^a-zA-Z0-9_]/', '_', $table);
                    if (!empty($table) && $table !== '_' && !in_array($table, $tables)) {
                        $tables[] = $table;
                    }
                }
            }
        }

        return $tables;
    }

    /**
     * Vérifie qu'une table existe, la crée si nécessaire
     * 
     * @param string $tableName
     */
    private function ensureTableExists(string $tableName): void
    {
        if (in_array($tableName, $this->processedTables)) {
            return;
        }
        $this->processedTables[] = $tableName;

        try {
            $sm = $this->connection->createSchemaManager();

            if (!$sm->tablesExist([$tableName])) {
                $schema = $sm->createSchema();
                $table = $schema->createTable($tableName);

                $table->addColumn('id', 'integer', ['autoincrement' => true]);
                $table->setPrimaryKey(['id']);

                $table->addColumn('uid', 'string', ['length' => 10, 'notnull' => true]);
                $table->addUniqueIndex(['uid']);

                if ($tableName !== '*') {
                    $table->addColumn('coll_name', 'string', ['length' => 100, 'notnull' => false]);
                }

                $table->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP']);
                $table->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP']);

                $sm->createTable($table);
            }
        } catch (\Exception $e) {
            // Ignorer les erreurs
        }
    }

    /**
     * Ajoute une colonne manquante à toutes les tables
     * 
     * @param array $tables
     * @param string $columnName
     */
    private function addMissingColumnToTables(array $tables, string $columnName): void
    {
        foreach ($tables as $table) {
            try {
                $sm = $this->connection->createSchemaManager();
                if (!$sm->tablesExist([$table])) {
                    continue;
                }

                $columns = $sm->listTableColumns($table);
                if (isset($columns[$columnName])) {
                    continue;
                }

                $platform = $this->connection->getDatabasePlatform();
                $this->connection->executeStatement(sprintf(
                    'ALTER TABLE %s ADD COLUMN %s VARCHAR(255) DEFAULT NULL',
                    $this->connection->quoteIdentifier($table),
                    $this->connection->quoteIdentifier($columnName)
                ));
            } catch (\Exception $e) {
                // Ignorer les erreurs
            }
        }
    }

    /**
     * Sanitize la requête SQL
     * 
     * @param string $sql
     * @return string
     */
    private function sanitizeSql(string $sql): string
    {
        $sql = preg_replace_callback('/(FROM|JOIN|UPDATE|INTO|TABLE)\s+([a-zA-Z0-9_-]+)/i', function ($matches) {
            return $matches[1] . ' ' . str_replace('-', '_', $matches[2]);
        }, $sql);

        return preg_replace('/\s+/', ' ', $sql);
    }

    /**
     * Récupère les colonnes d'une table
     * 
     * @param string $tableName
     * @return array
     */
    public function getTableColumns(string $tableName): array
    {
        try {
            $sm = $this->connection->createSchemaManager();
            if (!$sm->tablesExist([$tableName])) {
                return [];
            }
            $columns = $sm->listTableColumns($tableName);
            return array_keys($columns);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Vérifie si une colonne existe
     * 
     * @param string $tableName
     * @param string $columnName
     * @return bool
     */
    public function columnExists(string $tableName, string $columnName): bool
    {
        return in_array($columnName, $this->getTableColumns($tableName));
    }
}
