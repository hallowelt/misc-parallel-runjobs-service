<?php

namespace BlueSpice\Service\ParallelRunJobs\Runner;

use BlueSpice\Service\ParallelRunJobs\Config;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Simple runner, execute runJobs over and over again with cooldown in between, without parallelization
 */
class Single {

	/**
	 * @param Config $config
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		protected Config $config,
		protected LoggerInterface $logger
	) {
	}

	/**
	 * @return void
	 */
	public function start() {
		$this->logger->notice( 'Ready, running runJobs.php for standalone wiki' );
		while ( true ) {
			$this->logger->info( 'Starting run' );

			$startedAt = microtime( true );
			$process = $this->getProcess();
			$process->run( function ( $type, $buffer ) {
				$this->logger->debug( $buffer );
			} );
			$duration = round( microtime( true ) - $startedAt, 1 );
			if ( $process->getExitCode() !== 0 ) {
				$this->logger->error(
					"Process failed after {$duration}s with exit code {$process->getExitCode()}: " .
					$process->getErrorOutput()
				);
			} else {
				$this->logger->info( "Finished run in {$duration}s" );
			}

			$cooldown = $this->config->getJobConfig()['cooldown'];
			$this->logger->debug( "Cooldown for $cooldown seconds" );
			sleep( $cooldown );
		}
	}

	/**
	 * @param array $args
	 * @return Process
	 */
	protected function getProcess( array $args = [] ): Process {
		return new Process( array_merge( [
			$this->config->getPhpPath(),
			$this->config->getRunJobsPath(),
			'--maxtime=' . $this->config->getJobConfig()['maxtime'],
			'--maxjobs=' . $this->config->getJobConfig()['maxjobs']
		], $args ) );
	}
}
