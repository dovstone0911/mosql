<?php

namespace Dovstone\MoSQL\Exception;

/**
 * Exception de base pour MoSQL
 */
class MyNoSQLException extends \Exception {}

/**
 * Lancée lorsqu'un document n'est pas trouvé
 */
class DocumentNotFoundException extends MyNoSQLException {}

/**
 * Lancée lorsqu'un doublon est détecté (UID déjà existant)
 */
class DuplicateException extends MyNoSQLException {}

/**
 * Lancée lorsqu'un argument passé est invalide
 */
class InvalidArgumentException extends MyNoSQLException {}

/**
 * Lancée lorsqu'une erreur de cache survient
 */
class CacheException extends MyNoSQLException {}

/**
 * Lancée lorsqu'une opération de base de données échoue
 */
class DatabaseException extends MyNoSQLException {}

/**
 * Lancée lorsqu'une erreur de schéma survient
 */
class SchemaException extends MyNoSQLException {}

/**
 * Lancée lorsqu'une erreur de configuration survient
 */
class ConfigurationException extends MyNoSQLException {}
