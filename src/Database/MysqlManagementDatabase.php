<?php

namespace BlueSpice\Service\ParallelRunJobs\Database;

use mysqli;
use mysqli_sql_exception;
use Psr\Log\LoggerInterface;

/**
 * MySQL/MariaDB, requires `ext-mysqli`
 */
class MysqlManagementDatabase implements ManagementDatabase {

	/** @var mysqli|null */
	private ?mysqli $connection = null;

	/**
	 * @param array $config See `Config::getConnection`
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		private readonly array $config,
		private readonly LoggerInterface $logger
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getReadyInstancePaths(): array {
		$table = $this->config['dbprefix'] . 'simple_farmer_instances';
		try {
			$res = $this->getConnection()->query(
				"SELECT sfi_path FROM `$table` WHERE sfi_status = 'ready'"
			);
		} catch ( mysqli_sql_exception $e ) {
			throw new ManagementDatabaseException( $e->getMessage(), 0, $e );
		}
		if ( $res === false ) {
			throw new ManagementDatabaseException( $this->getConnection()->error );
		}
		return array_column( $res->fetch_all( MYSQLI_ASSOC ), 'sfi_path' );
	}

	/**
	 * @return mysqli
	 */
	private function getConnection(): mysqli {
		if ( $this->connection === null ) {
			try {
				$this->connection = new mysqli(
					$this->config['dbserver'],
					$this->config['dbuser'],
					$this->config['dbpassword'],
					$this->config['dbname'],
					(int)$this->config['dbport']
				);
			} catch ( mysqli_sql_exception $e ) {
				throw new ManagementDatabaseException( $e->getMessage(), 0, $e );
			}
			$this->logger->notice( sprintf(
				'Connected to MySQL management database "%s" at %s:%s',
				$this->config['dbname'], $this->config['dbserver'], $this->config['dbport']
			) );
		}
		return $this->connection;
	}
}
