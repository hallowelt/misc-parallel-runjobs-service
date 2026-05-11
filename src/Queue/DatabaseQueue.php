<?php

namespace BlueSpice\Service\ParallelRunJobs\Queue;

use BlueSpice\Service\ParallelRunJobs\Config;
use mysqli;
use Symfony\Component\Console\Output\OutputInterface;

class DatabaseQueue implements Queue {

	/** @var array */
	private array $pending = [];

	/**
	 * @var mysqli|null
	 */
	protected ?mysqli $managementDb = null;

	public function __construct(
		protected Config $config,
		protected OutputInterface $output
	) {
	}

	public function getNext(): ?string {
		if ( empty( $this->pending ) ) {
			$this->pending = $this->fetchAll();
		}
		if ( empty( $this->pending ) ) {
			return null;
		}
		return array_shift( $this->pending );
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
		$this->pending[] = $instance;
	}

	public function onSuccess( string $instance ): void {
		// NOOP
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
		}
		// Test connection
		$this->managementDb->query( 'SELECT 1' );
		if ( $this->managementDb->errno ) {
			$this->output->writeln( '<error>Management database connection failed</error>' );
			exit( 1 );
		}
	}
}
