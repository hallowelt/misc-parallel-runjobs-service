<?php

namespace BlueSpice\Service\ParallelRunJobs;

use mysqli;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class RunjobsService {

	/** @var Config */
	private $config;

	/** @var OutputInterface */
	private $output;

	/** @var null */
	private $managementDb = null;

	/**
	 * @param Config $config
	 * @param OutputInterface $output
	 */
	public function __construct( Config $config, OutputInterface $output ) {
		$this->config = $config;
		$this->output = $output;
	}

	public function run() {
		$this->output->writeln( "<info>Starting run</info>" );
		$this->output->writeln( "<info>Cooldown set to " . $this->config->getJobConfig()['cooldown'] . " seconds</info>" );
		while ( true ) {
			if ( $this->config->isFarmingEnvironment() ) {
				$this->assertManagementConnection();
				$this->runInParallel();
			} else {
				$process = $this->getProcess();
				$process->run( function ( $type, $buffer ) {
					$this->output->write( $buffer );
				} );
				if ( $process->getExitCode() !== 0 ) {
					$this->output->writeln( "<error>Process failed" . $process->getErrorOutput() . "</error>" );
				}
			}
			sleep( $this->config->getJobConfig()['cooldown'] );
		}
	}

	/**
	 * @param array $args
	 * @return Process
	 */
	private function getProcess( array $args = [] ): Process {
		return new Process( array_merge( [
			$this->config->getPhpPath(),
			$this->config->getRunJobsPath(),
			'--maxtime=' . $this->config->getJobConfig()['maxtime'],
			'--maxjobs=' . $this->config->getJobConfig()['maxjobs']
		], $args ) );
	}

	/**
	 * @return void
	 */
	private function runInParallel() {
		$maxParallel = $this->config->getFarmConfig()['maxparallel'];
		$this->output->writeln( "<info>Running in parallel, $maxParallel at a time</info>" );

		$instances = $this->getInstances();
		$pending = array_keys( $instances );
		$execCount = 0;
		$processes = [];

		foreach ( $pending as $instance ) {
			if ( $instance === 'w' ) {
				$processes[$instance] = $this->getProcess();
			} else {
				$processes[$instance] = $this->getProcess( [ '--sfr=' . $instance ] );
			}
		}
		$currentlyRunning = 0;
		while ( true ) {
			$finished = true;
			foreach ( $processes as $instance => $process ) {
				if ( !$process->isStarted() ) {
					// If process not started...
					if ( $currentlyRunning < $maxParallel ) {
						// ... and can start, start it
						$this->output->writeln( "<info>Starting for \"$instances[$instance]\"</info>" );
						$process->start();
						$currentlyRunning++;
					}
					$finished = false;
					continue;
				}
				if ( $process->isRunning() ) {
					$finished = false;
				} else {
					// Finished
					$this->output->writeln( "<info>Finished for \"$instances[$instance]\"</info>" );
					$this->output->write( $process->getOutput() );
					if ( $process->getExitCode() !== 0 ) {
						$this->output->writeln( "<error>Process failed\n" . $process->getErrorOutput() . "</error>" );
					}
					$currentlyRunning--;
					$execCount++;
					unset( $processes[$instance] );
				}
			}
			if ( $finished ) {
				$this->output->writeln( "<info>Finished a run for $execCount instances</info>" );
				return;
			}
			usleep( 500000 );
		}
	}

	/**
	 * @return array
	 */
	private function getInstances(): array {
		$table = $this->config->getDbConnection()['dbprefix'] . 'simple_farmer_instances';
		$include = $this->config->getFarmConfig()['include-instances'] ?? [];
		$exclude = $this->config->getFarmConfig()['exclude-instances'] ?? [];
		$include = array_diff( $include, $exclude );

		$rows = $this->managementDb->query( "SELECT sfi_path, sfi_display_name FROM $table WHERE sfi_status = 'ready'" );
		$instances = [];
		while ( $row = $rows->fetch_assoc() ) {
			$path = $row['sfi_path'];
			$display = $row['sfi_display_name'];
			if ( !empty( $include ) ) {
				if ( !in_array( $path, $include ) ) {
					continue;
				}
			} elseif ( !empty( $exclude ) && in_array( $path, $exclude ) ) {
				continue;
			}
			$instances[$path] = "$display ($path)";
		}

		$instances['w'] = "Root instance";
		return $instances;
	}

	/**
	 * @return void
	 */
	private function assertManagementConnection() {
		if ( $this->managementDb === null ) {
			$this->managementDb = new mysqli(
				$this->config->getDbConnection()['dbserver'],
				$this->config->getDbConnection()['dbuser'],
				$this->config->getDbConnection()['dbpassword'],
				$this->config->getDbConnection()['dbname'],
				$this->config->getDbConnection()['dbport']
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
