<?php

namespace Dovstone\MoSQL\Document;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Dovstone\MoSQL\Exception\DatabaseException;
use Dovstone\MoSQL\Schema\SchemaManager;
use Dovstone\MoSQL\Uid\UidGenerator;

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

    public function update(array $data, array $conditions): array
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
                // if (!in_array($field, ['id', 'uid'])) {
                $field = $this->resetUId($field);
                $qb->set($field, ":$field");
                $qb->setParameter($field, $value, $this->getParameterType($value));
                // }
            }

            $qb->set('updated_at', ':updated_at')
                ->setParameter('updated_at', date('Y-m-d H:i:s'), ParameterType::STRING);

            foreach ($conditions as $field => $value) {
                // if (!in_array($field, ['id', 'uid'])) {
                // 🔥 Convertir les tableaux en JSON pour les conditions
                $conditionValue = is_array($value) ? json_encode($value) : $value;
                $field = $this->resetUId($field);
                $qb->andWhere("$field = :cond_$field")
                    ->setParameter("cond_$field", $conditionValue, $this->getParameterType($conditionValue));
                // }
            }

            $qb->executeStatement();
            return $data;
        } catch (\Exception $e) {
            throw new DatabaseException($e->getMessage(), 0, $e);
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
                if (is_array($value)) {
                    $placeholders = [];
                    foreach ($value as $i => $v) {
                        $paramName = "{$field}_{$i}";
                        $placeholders[] = ":$paramName";
                        $qb->setParameter($paramName, $v, $this->getParameterType($v));
                    }
                    $qb->andWhere("$field IN (" . implode(', ', $placeholders) . ")");
                } else {
                    $paramName = "cond_{$field}";
                    $qb->andWhere("$field = :$paramName")
                        ->setParameter($paramName, $value, $this->getParameterType($value));
                }
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
                $conditionValue = is_array($value) ? json_encode($value) : $value;
                $field = $this->resetUId($field);
                $qb->andWhere("$field = :$field")
                    ->setParameter($field, $conditionValue, $this->getParameterType($conditionValue));
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
                $field = $this->resetUId($field);
                if (is_array($value)) {
                    $qb->andWhere("$field IN (:" . $field . ")")
                        ->setParameter($field, $value, Connection::PARAM_STR_ARRAY);
                } else {
                    $qb->andWhere("$field = :$field")
                        ->setParameter($field, $value, $this->getParameterType($value));
                }
            }

            if ($orderBy) {
                foreach ($orderBy as $field => $direction) {
                    $field = $this->resetUId($field);
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
                $field = $this->resetUId($field);
                if (is_array($value)) {
                    $qb->andWhere("$field IN (:" . $field . ")")
                        ->setParameter($field, $value, Connection::PARAM_STR_ARRAY);
                } else {
                    $conditionValue = is_array($value) ? json_encode($value) : $value;
                    $qb->andWhere("$field = :$field")
                        ->setParameter($field, $conditionValue, $this->getParameterType($conditionValue));
                }
            }

            $result = $qb->fetchAssociative();
            return (int)($result['count'] ?? 0);
        } catch (\Exception $e) {
            throw new DatabaseException("Count failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Hydrate un document : convertit les types SQL en types PHP
     */
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
                'integer' => (int)$value,
                'float' => (float)$value,
                'boolean' => (bool)$value,
                'datetime' => $value,
                default => $this->decodeJson($value),
            };
        }

        // Transformation uid → id
        if (isset($result['uid'])) {
            $result['id'] = $result['uid'];
            unset($result['uid']);
        }

        return $result;
    }

    /**
     * Décode une chaîne JSON en tableau PHP
     */
    private function decodeJson(string $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return $value;
    }

    /**
     * Hydrate plusieurs documents
     */
    public function hydrateAll(array $rows): array
    {
        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * Normalise un document pour l'insertion/update
     * 🔥 Convertit TOUS les tableaux en JSON
     */
    public function normalize(array $document): array
    {
        $schema = $this->schemaManager->getSchema();
        $data = [];

        // 🔥 Ajouter automatiquement coll_name si la colonne existe
        if (isset($schema['coll_name']) && isset($document['coll_name'])) {
            $coll_name = preg_replace('/[^a-zA-Z0-9_]/', '_', $document['coll_name']);
            $data['coll_name'] = $coll_name;
        }

        foreach ($document as $field => $value) {
            // Champs protégés (on les passe tels quels)
            if (in_array($field, ['uid', 'created_at', 'updated_at', 'coll_name'])) {
                $data[$field] = $value;
                continue;
            }

            // Si le champ existe dans le schéma
            if (isset($schema[$field])) {
                $type = $schema[$field]['type'];

                // 🔥 Si le type est JSON, on encode en JSON
                if ($type === 'json') {
                    $data[$field] = is_string($value) ? $value : json_encode($value);
                } else {
                    $data[$field] = $this->castToSqlType($value, $type);
                }
            } else {
                // Si le champ n'existe pas encore dans le schéma
                // Si c'est un tableau, on l'encode en JSON
                if (is_array($value)) {
                    $data[$field] = json_encode($value);
                } else {
                    $data[$field] = $value;
                }
            }
        }

        return $data;
    }

    /**
     * Caste une valeur au type SQL approprié
     */
    private function castToSqlType($value, string $sqlType)
    {
        if (is_array($value)) {
            return json_encode($value);
        }
        return match ($sqlType) {
            'json' => is_string($value) ? $value : json_encode($value),
            'integer' => (int)$value,
            'float' => (float)$value,
            'boolean' => (int)(bool)$value,
            'datetime' => is_string($value) ? $value : date('Y-m-d H:i:s', $value),
            default => (string)$value,
        };
    }

    /**
     * Détermine le type de paramètre pour setParameter()
     */
    private function getParameterType($value): int
    {
        return match (gettype($value)) {
            'integer' => ParameterType::INTEGER,
            'boolean' => ParameterType::BOOLEAN,
            'NULL' => ParameterType::NULL,
            'array', 'object' => ParameterType::STRING,
            default => ParameterType::STRING,
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

    public function increment(array $data, array $conditions): int
    {
        if (empty($conditions)) {
            throw new \InvalidArgumentException("Conditions cannot be empty for increment");
        }

        try {
            // 1. Récupérer les valeurs actuelles
            $qb = $this->connection->createQueryBuilder();
            $qb->select('*')
                ->from($this->schemaManager->getTableName());

            foreach ($conditions as $field => $value) {
                $field = $this->resetUId($field);
                $field = $field == 'id' ? 'uid' : $field;
                $qb->andWhere("$field = :cond_$field")
                    ->setParameter("cond_$field", $value);
            }

            $current = $qb->fetchAssociative();

            // 2. Si document non trouvé, le créer
            if (!$current) {
                // Construire le nouveau document
                $newDocument = $conditions;
                foreach ($data as $field => $value) {
                    if (!in_array($field, ['id', 'uid', 'created_at', 'updated_at'])) {
                        $newDocument[$field] = $value;
                    }
                }

                // Générer un UID si nécessaire
                if (!isset($newDocument['uid'])) {
                    $newDocument['uid'] = (new UidGenerator())->generate();
                }

                // Insérer le nouveau document
                $this->insert($newDocument);

                // Récupérer le nombre de lignes affectées (1)
                return 1;
            }

            // 3. Calculer les nouvelles valeurs
            $updateData = [];
            foreach ($data as $field => $value) {
                if (!in_array($field, ['id', 'uid', 'created_at', 'updated_at'])) {
                    $currentValue = (int)($current[$field] ?? 0);
                    $updateData[$field] = $currentValue + $value;
                }
            }

            // 4. Mettre à jour
            $qb = $this->connection->createQueryBuilder();
            $qb->update($this->schemaManager->getTableName());

            foreach ($updateData as $field => $value) {
                $field = $this->resetUId($field);
                $qb->set($field, ":$field");
                $qb->setParameter($field, $value);
            }

            $qb->set('updated_at', ':updated_at')
                ->setParameter('updated_at', date('Y-m-d H:i:s'));

            foreach ($conditions as $field => $value) {
                $field = $this->resetUId($field);
                $qb->andWhere("$field = :cond_$field")
                    ->setParameter("cond_$field", $value);
            }

            return $qb->executeStatement();
        } catch (\Exception $e) {
            throw new DatabaseException($e->getMessage(), 0, $e);
        }
    }

    public function decrement(array $data, array $conditions): int
    {
        if (empty($conditions)) {
            throw new \InvalidArgumentException("Conditions cannot be empty for decrement");
        }

        try {
            // 1. Récupérer les valeurs actuelles
            $qb = $this->connection->createQueryBuilder();
            $qb->select('*')
                ->from($this->schemaManager->getTableName());

            foreach ($conditions as $field => $value) {
                $field = $this->resetUId($field);
                $qb->andWhere("$field = :cond_$field")
                    ->setParameter("cond_$field", $value);
            }

            $current = $qb->fetchAssociative();

            // 2. Si document non trouvé, le créer
            if (!$current) {
                // Construire le nouveau document
                $newDocument = $conditions;
                foreach ($data as $field => $value) {
                    if (!in_array($field, ['id', 'uid', 'created_at', 'updated_at'])) {
                        $newDocument[$field] = $value;
                    }
                }

                // Générer un UID si nécessaire
                if (!isset($newDocument['uid'])) {
                    $newDocument['uid'] = (new UidGenerator())->generate();
                }

                // Insérer le nouveau document
                $this->insert($newDocument);

                // Récupérer le nombre de lignes affectées (1)
                return 1;
            }

            // 3. Calculer les nouvelles valeurs
            $updateData = [];
            foreach ($data as $field => $value) {
                if (!in_array($field, ['id', 'uid', 'created_at', 'updated_at'])) {
                    $currentValue = (int)($current[$field] ?? 0);
                    $updateData[$field] = $currentValue - $value;
                }
            }

            // 4. Mettre à jour
            $qb = $this->connection->createQueryBuilder();
            $qb->update($this->schemaManager->getTableName());

            foreach ($updateData as $field => $value) {
                $field = $this->resetUId($field);
                $qb->set($field, ":$field");
                $qb->setParameter($field, $value);
            }

            $qb->set('updated_at', ':updated_at')
                ->setParameter('updated_at', date('Y-m-d H:i:s'));

            foreach ($conditions as $field => $value) {
                $field = $this->resetUId($field);
                $qb->andWhere("$field = :cond_$field")
                    ->setParameter("cond_$field", $value);
            }

            return $qb->executeStatement();
        } catch (\Exception $e) {
            throw new DatabaseException($e->getMessage(), 0, $e);
        }
    }

    public function resetUId($field)
    {
        if ($field == 'id') {
            $field = 'uid';
        }
        return $field;
    }
}
