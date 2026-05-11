<?php

namespace BlueSpice\Service\ParallelRunJobs\Runner;

use BlueSpice\Service\ParallelRunJobs\Config;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Simple runner, execute runJobs over and over again with cooldown in between, without parallelization
 */
class Single {

	/**
	 * @param Config $config
	 * @param OutputInterface $output
	 */
	public function __construct(
		protected Config $config,
		protected OutputInterface $output
	) {
	}

	/**
	 * @return void
	 */
	public function start() {
		while ( true ) {
			$this->output->writeln( "<info>Starting run</info>" );
			$this->output->clear();

			$process = $this->getProcess();
			$process->run( function ( $type, $buffer ) {
				$this->output->write( $buffer );
			} );
			if ( $process->getExitCode() !== 0 ) {
				$this->output->writeln( "<error>Process failed" . $process->getErrorOutput() . "</error>" );
			}

			$this->output->writeln( "<info>Cooldown for " . $this->config->getJobConfig()['cooldown'] . " seconds</info>" );
			sleep( $this->config->getJobConfig()['cooldown'] );
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
