<?php

namespace BlueSpice\Service\ParallelRunJobs\Queue;

use BlueSpice\Service\ParallelRunJobs\Config;
use mysqli;
use Psr\Log\LoggerInterface;

class DatabaseQueue implements Queue {

	/** @var array */
	private array $pending = [];

	/** @var array<string, true> Instances currently in-flight (returned but not yet resolved) */
	private array $inFlight = [];

	/** @var int Timestamp of the last instance list refresh */
	private int $lastFetch = 0;

	/**
	 * @var mysqli|null
	 */
	protected ?mysqli $managementDb = null;

	public function __construct(
		protected Config $config,
		protected LoggerInterface $logger
	) {
	}

	public function getNext(): ?string {
		if ( empty( $this->pending ) ) {
			// Start a new round at most once per cooldown period, otherwise small farms
			// would re-spawn runJobs for the same instances in a tight loop
			if ( ( time() - $this->lastFetch ) < $this->config->getJobConfig()['cooldown'] ) {
				return null;
			}
			$this->pending = $this->fetchAll();
			$this->lastFetch = time();
			$this->logger->info( 'Starting new round with ' . count( $this->pending ) . ' instances' );
		}
		while ( !empty( $this->pending ) ) {
			$instance = array_shift( $this->pending );
			if ( isset( $this->inFlight[$instance] ) ) {
				continue;
			}
			$this->inFlight[$instance] = true;
			return $instance;
		}
		return null;
	}

	private function fetchAll() {
		$this->assertManagementConnection();
		$table = $this->config->getConnection()['dbprefix'] . 'simple_farmer_instances';
		$include = $this->config->getFarmConfig()['include-instances'] ?? [];
		$exclude = $this->config->getFarmConfig()['exclude-instances'] ?? [];
		$include = array_diff( $include, $exclude );

		$rows = $this->managementDb->query( "SELECT sfi_path FROM $table WHERE sfi_status = 'ready'" );
		$instances = [];
		$row = $rows->fetch_assoc();

		while ( $row !== null ) {
			$path = $row['sfi_path'];

			if (
				( !empty( $include ) && !in_array( $path, $include ) ) ||
				( empty( $include ) && !empty( $exclude ) && in_array( $path, $exclude ) )
			) {
				$row = $rows->fetch_assoc();
				continue;
			}

			$instances[] = $path;
			$row = $rows->fetch_assoc();
		}

		$instances[] = 'w';
		return $instances;
	}

	public function onFailure( string $instance ): void {
		// Will be retried in the next round, re-queueing immediately would hot-loop on broken instances
		unset( $this->inFlight[$instance] );
	}

	public function onSuccess( string $instance ): void {
		unset( $this->inFlight[$instance] );
	}

	/**
	 * @return void
	 */
	protected function assertManagementConnection() {
		if ( $this->managementDb === null ) {
			$this->managementDb = new mysqli(
				$this->config->getConnection()['dbserver'],
				$this->config->getConnection()['dbuser'],
				$this->config->getConnection()['dbpassword'],
				$this->config->getConnection()['dbname'],
				$this->config->getConnection()['dbport']
			);
			$this->logger->notice( sprintf(
				'Connected to management database "%s" at %s:%s',
				$this->config->getConnection()['dbname'],
				$this->config->getConnection()['dbserver'],
				$this->config->getConnection()['dbport']
			) );
		}
		// Test connection
		$this->managementDb->query( 'SELECT 1' );
		if ( $this->managementDb->errno ) {
			$this->logger->error( 'Management database connection failed: ' . $this->managementDb->error );
			exit( 1 );
		}
	}
}
