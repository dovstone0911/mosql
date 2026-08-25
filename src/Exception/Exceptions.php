<?php

namespace Dovstone\MoSQL\Exception;

/**
 * Exception de base pour MoSQL
 */
class MoSQLException extends \Exception {}

/**
 * Lancée lorsqu'un document n'est pas trouvé
 */
class DocumentNotFoundException extends MoSQLException {}

/**
 * Lancée lorsqu'un doublon est détecté (UID déjà existant)
 */
class DuplicateException extends MoSQLException {}

/**
 * Lancée lorsqu'un argument passé est invalide
 */
class InvalidArgumentException extends MoSQLException {}

/**
 * Lancée lorsqu'une erreur de cache survient
 */
class CacheException extends MoSQLException {}

/**
 * Lancée lorsqu'une opération de base de données échoue
 */
class DatabaseException extends MoSQLException {}

/**
 * Lancée lorsqu'une erreur de schéma survient
 */
class SchemaException extends MoSQLException {}

/**
 * Lancée lorsqu'une erreur de configuration survient
 */
class ConfigurationException extends MoSQLException {}
