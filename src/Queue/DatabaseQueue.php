<?php

namespace BlueSpice\Service\ParallelRunJobs\Queue;

use BlueSpice\Service\ParallelRunJobs\Config;
use BlueSpice\Service\ParallelRunJobs\Database\ManagementDatabase;
use BlueSpice\Service\ParallelRunJobs\Database\ManagementDatabaseException;
use BlueSpice\Service\ParallelRunJobs\Database\ManagementDatabaseFactory;
use Psr\Log\LoggerInterface;

class DatabaseQueue implements Queue {

	/** @var array */
	private array $pending = [];

	/** @var array<string, true> Instances currently in-flight (returned but not yet resolved) */
	private array $inFlight = [];

	/** @var int Timestamp of the last instance list refresh */
	private int $lastFetch = 0;

	/**
	 * @var ManagementDatabase|null
	 */
	protected ?ManagementDatabase $managementDb = null;

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
		$include = $this->config->getFarmConfig()['include-instances'] ?? [];
		$exclude = $this->config->getFarmConfig()['exclude-instances'] ?? [];
		$include = array_diff( $include, $exclude );

		$instances = [];
		foreach ( $this->getReadyInstancePaths() as $path ) {
			if (
				( !empty( $include ) && !in_array( $path, $include ) ) ||
				( empty( $include ) && !empty( $exclude ) && in_array( $path, $exclude ) )
			) {
				continue;
			}
			$instances[] = $path;
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
	 * @return string[]
	 */
	protected function getReadyInstancePaths(): array {
		try {
			if ( $this->managementDb === null ) {
				$this->managementDb = ManagementDatabaseFactory::create(
					$this->config->getConnection(), $this->logger
				);
			}
			return $this->managementDb->getReadyInstancePaths();
		} catch ( ManagementDatabaseException $e ) {
			$this->logger->error( 'Management database query failed: ' . $e->getMessage() );
			exit( 1 );
		}
	}
}
