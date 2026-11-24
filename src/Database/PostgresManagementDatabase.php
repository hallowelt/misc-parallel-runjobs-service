<?php

namespace BlueSpice\Service\ParallelRunJobs\Database;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;

/**
 * PostgreSQL, requires `ext-pdo_pgsql`
 */
class PostgresManagementDatabase implements ManagementDatabase {

	/** @var PDO|null */
	private ?PDO $connection = null;

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
		// Same as MediaWiki's `$wgDBmwschema`
		$table = $this->quoteIdentifier( $this->config['dbschema'] ) . '.'
			. $this->quoteIdentifier( $this->config['dbprefix'] . 'simple_farmer_instances' );
		try {
			$res = $this->getConnection()->query(
				"SELECT sfi_path FROM $table WHERE sfi_status = 'ready'"
			);
			return $res->fetchAll( PDO::FETCH_COLUMN, 0 );
		} catch ( PDOException $e ) {
			throw new ManagementDatabaseException( $e->getMessage(), 0, $e );
		}
	}

	/**
	 * @return PDO
	 */
	private function getConnection(): PDO {
		if ( $this->connection === null ) {
			$dsn = sprintf(
				'pgsql:host=%s;port=%d;dbname=%s',
				$this->config['dbserver'], (int)$this->config['dbport'], $this->config['dbname']
			);
			try {
				$this->connection = new PDO(
					$dsn, $this->config['dbuser'], $this->config['dbpassword'],
					[ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]
				);
			} catch ( PDOException $e ) {
				throw new ManagementDatabaseException( $e->getMessage(), 0, $e );
			}
			$this->logger->notice( sprintf(
				'Connected to PostgreSQL management database "%s" at %s:%s',
				$this->config['dbname'], $this->config['dbserver'], $this->config['dbport']
			) );
		}
		return $this->connection;
	}

	/**
	 * @param string $identifier
	 * @return string
	 */
	private function quoteIdentifier( string $identifier ): string {
		return '"' . str_replace( '"', '""', $identifier ) . '"';
	}
}
