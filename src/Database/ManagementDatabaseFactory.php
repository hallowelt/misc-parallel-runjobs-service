<?php

namespace BlueSpice\Service\ParallelRunJobs\Database;

use Psr\Log\LoggerInterface;

/**
 * Creates the implementation for `database.dbtype`. The PHP extension of a database type is
 * only required if that type is used, so none of them is a hard dependency.
 */
class ManagementDatabaseFactory {

	/**
	 * Supported values of `database.dbtype` and the PHP extension they require
	 */
	public const TYPES = [
		'mysql' => [ 'class' => MysqlManagementDatabase::class, 'extension' => 'mysqli' ],
		'postgres' => [ 'class' => PostgresManagementDatabase::class, 'extension' => 'pdo_pgsql' ],
	];

	/**
	 * Default ports per database type
	 */
	public const DEFAULT_PORTS = [
		'mysql' => 3306,
		'postgres' => 5432,
	];

	/**
	 * @param array $config See `Config::getConnection`
	 * @param LoggerInterface $logger
	 * @return ManagementDatabase
	 * @throws ManagementDatabaseException
	 */
	public static function create( array $config, LoggerInterface $logger ): ManagementDatabase {
		$type = $config['dbtype'];
		if ( !isset( static::TYPES[$type] ) ) {
			throw new ManagementDatabaseException( "Unsupported database type: $type" );
		}
		$extension = static::TYPES[$type]['extension'];
		if ( !extension_loaded( $extension ) ) {
			throw new ManagementDatabaseException(
				"Database type \"$type\" requires the PHP extension \"$extension\""
			);
		}
		$class = static::TYPES[$type]['class'];
		return new $class( $config, $logger );
	}
}
