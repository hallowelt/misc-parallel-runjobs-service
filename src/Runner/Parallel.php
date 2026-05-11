<?php

namespace BlueSpice\Service\ParallelRunJobs\Runner;

use BlueSpice\Service\ParallelRunJobs\Config;
use BlueSpice\Service\ParallelRunJobs\Queue\DatabaseQueue;
use BlueSpice\Service\ParallelRunJobs\Queue\Queue;
use BlueSpice\Service\ParallelRunJobs\Queue\RedisQueue;
use Symfony\Component\Console\Output\OutputInterface;

class Parallel extends Single {

	/** @var Queue|null */
	private ?Queue $queue;

	/** @var string */
	private string $runnerId;

	/**
	 * @var array
	 */
	private array $slots;

	/**
	 * @param Config $config
	 * @param OutputInterface $output
	 * @throws \RedisException
	 */
	public function __construct( Config $config, OutputInterface $output ) {
		parent::__construct( $config, $output );
		$this->runnerId = uniqid( 'runner_' );
		$this->queue = null;
		switch ( $this->config->getQueue() ) {
			case 'database':
				$this->queue = new DatabaseQueue( $config, $output );
				break;
			case 'redis':
				$this->queue = new RedisQueue( $config, $output );
				break;
			default:
				$this->output->writeln( '<error>Invalid queue specified</error>' );
				exit( 1 );
		}
	}

	/**
	 * @param int $slot
	 * @param string $instance
	 * @return void
	 */
	private function assignToSlot( int $slot, string $instance ): void {
		$process = $this->getProcess( [ '--sfr=' . $instance ] );
		$this->output->writeln( "<info>Starting for \"$instance\"</info>" );
		$process->start();
		$this->slots[$slot] = [
			'instance' => $instance,
			'process' => $process,
			'startedAt' => time(),
		];
	}

	/**
	 * @return int|null
	 */
	private function getFreeSlot(): ?int {
		foreach ( $this->slots as $index => $slot ) {
			if ( $slot === null ) {
				return $index;
			}
			$maxRuntime = $this->config->getJobConfig()['maxtime'] * 2;
			if ( $slot['process']->isRunning() ) {
				// Prevent stuck slots, kill after a long time (2x max runtime)
				if ( ( time() - $slot['startedAt'] ) > $maxRuntime ) {
					$slot['process']->stop( 0 );
					$this->output->writeln( "<error>Timed out for \"{$slot['instance']}\" after {$maxRuntime}s, killing</error>" );
					$this->queue->onFailure( $slot['instance'] );
					$this->slots[$index] = null;
					return $index;
				}
				continue;
			}
			$this->output->writeln( "<info>Finished for \"{$slot['instance']}\"</info>" );
			$this->output->write( $slot['process']->getOutput() );
			if ( $slot['process']->getExitCode() !== 0 ) {
				$this->queue->onFailure( $slot['instance'] );
				$this->output->writeln( "<error>Process failed\n" . $slot['process']->getErrorOutput() . "</error>" );
			} else {
				$this->queue->onSuccess( $slot['instance'] );
			}
			$this->slots[$index] = null;
			return $index;
		}
		return null;
	}

	/**
	 * @return void
	 */
	public function start() {
		$maxParallel = $this->config->getFarmConfig()['maxparallel'];
		$this->output->writeln( "<info>Running in parallel, $maxParallel at a time</info>" );

		$this->slots = array_fill( 0, $maxParallel, null );

		while ( true ) {
			$freeSlot = $this->getFreeSlot();
			if ( $freeSlot !== null ) {
				$next = $this->queue->getNext();
				if ( $next ) {
					$this->assignToSlot( $freeSlot, $next );
				} else {
					sleep( $this->config->getJobConfig()['cooldown'] );
				}
			}
		}
	}
}
