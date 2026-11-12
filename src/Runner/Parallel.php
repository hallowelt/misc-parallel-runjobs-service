<?php

namespace BlueSpice\Service\ParallelRunJobs\Runner;

use BlueSpice\Service\ParallelRunJobs\Config;
use BlueSpice\Service\ParallelRunJobs\Queue\DatabaseQueue;
use BlueSpice\Service\ParallelRunJobs\Queue\Queue;
use BlueSpice\Service\ParallelRunJobs\Queue\RedisQueue;
use Psr\Log\LoggerInterface;

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
	 * @param LoggerInterface $logger
	 * @throws \RedisException
	 */
	public function __construct( Config $config, LoggerInterface $logger ) {
		parent::__construct( $config, $logger );
		$this->runnerId = uniqid( 'runner_' );
		$this->queue = null;
		switch ( $this->config->getQueue() ) {
			case 'database':
				$this->queue = new DatabaseQueue( $config, $logger );
				break;
			case 'redis':
				if ( !extension_loaded( 'redis' ) ) {
					$this->logger->error( 'Queue "redis" requires the PHP extension "redis", which is not loaded' );
					exit( 1 );
				}
				$this->queue = new RedisQueue( $config, $logger );
				break;
			default:
				$this->logger->error( 'Invalid queue specified' );
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
		$this->logger->info( "Starting for \"$instance\"" );
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
					$this->logger->error( "Timed out for \"{$slot['instance']}\" after {$maxRuntime}s, killing" );
					$this->queue->onFailure( $slot['instance'] );
					$this->slots[$index] = null;
					return $index;
				}
				continue;
			}
			$duration = time() - $slot['startedAt'];
			$processOutput = $slot['process']->getOutput();
			if ( $processOutput ) {
				$this->logger->debug( $processOutput );
			}
			$exitCode = $slot['process']->getExitCode();
			if ( $exitCode !== 0 ) {
				$this->queue->onFailure( $slot['instance'] );
				$this->logger->error(
					"Process for \"{$slot['instance']}\" failed after {$duration}s with exit code $exitCode\n" .
					$slot['process']->getErrorOutput()
				);
			} else {
				$this->queue->onSuccess( $slot['instance'] );
				$this->logger->info( "Finished for \"{$slot['instance']}\" in {$duration}s" );
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
		$this->logger->notice(
			"Ready, running farm instances from \"{$this->config->getQueue()}\" queue, $maxParallel in parallel"
		);

		$this->slots = array_fill( 0, $maxParallel, null );

		while ( true ) {
			$freeSlot = $this->getFreeSlot();
			if ( $freeSlot === null ) {
				// All slots busy, avoid busy-waiting
				usleep( 250000 );
				continue;
			}
			$next = $this->queue->getNext();
			if ( $next ) {
				$this->assignToSlot( $freeSlot, $next );
			} else {
				sleep( $this->config->getJobConfig()['cooldown'] );
			}
		}
	}
}
