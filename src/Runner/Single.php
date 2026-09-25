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
		while ( true ) {
			$this->logger->debug( 'Starting run' );

			$process = $this->getProcess();
			$process->run( function ( $type, $buffer ) {
				$this->logger->debug( $buffer );
			} );
			if ( $process->getExitCode() !== 0 ) {
				$this->logger->error( 'Process failed: ' . $process->getErrorOutput() );
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
