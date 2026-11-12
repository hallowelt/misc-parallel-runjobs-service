<?php

namespace BlueSpice\Service\ParallelRunJobs\Queue;

use BlueSpice\Service\ParallelRunJobs\Config;
use BlueSpice\Service\ParallelRunJobs\QueueLock\RedisQueueLock;
use Psr\Log\LoggerInterface;
use Redis;
use RedisException;
use RuntimeException;

class RedisQueue implements Queue {

	private const UNCLAIMED_KEY_PATTERN = '*:jobqueue:*:l-unclaimed';
	private const SKIP_COOLDOWN = 10;

	/** @var RedisQueueLock */
	private RedisQueueLock $queueLock;

	/** @var Redis|null */
	private ?Redis $redis = null;

	/** @var array<string, true> Instances currently in-flight (returned but not yet resolved) */
	private array $inFlight = [];

	/** @var array<string, int> Instance => timestamp until which it should be skipped */
	private array $skippedUntil = [];

	/** @var array<string, array<string, string|false>> Instance => ( queue key => oldest job ID ) at run start */
	private array $oldestJobsAtStart = [];

	/**
	 * @param Config $config
	 * @param LoggerInterface $logger
	 * @throws RedisException
	 */
	public function __construct(
		protected Config $config,
		protected LoggerInterface $logger
	) {
		$this->assertRedisConnection();
		$this->queueLock = new RedisQueueLock( $this->redis, $config );
	}

	/**
	 * Scan Redis for keys matching {wiki_id}:jobqueue:{type}:l-unclaimed,
	 * extract unique wiki IDs, and return the first eligible instance
	 * that is not already in-flight.
	 *
	 * @return string|null
	 * @throws RedisException
	 */
	public function getNext(): ?string {
		$include = $this->config->getFarmConfig()['include-instances'] ?? [];
		$exclude = $this->config->getFarmConfig()['exclude-instances'] ?? [];
		$include = array_diff( $include, $exclude );

		$seen = [];
		$cursor = null;
		while ( $cursor !== 0 ) {
			$result = $this->redis->scan( $cursor, self::UNCLAIMED_KEY_PATTERN, 100 );
			if ( $result === false ) {
				break;
			}
			foreach ( $result as $key ) {
				$instance = strstr( $key, ':', true );
				if ( $instance === false || isset( $seen[$instance] ) ) {
					continue;
				}
				$seen[$instance] = true;

				if ( isset( $this->inFlight[$instance] ) ) {
					continue;
				}
				if ( isset( $this->skippedUntil[$instance] ) ) {
					if ( time() < $this->skippedUntil[$instance] ) {
						continue;
					}
					unset( $this->skippedUntil[$instance] );
				}
				if ( !empty( $include ) ) {
					if ( !in_array( $instance, $include ) ) {
						continue;
					}
				} elseif ( !empty( $exclude ) && in_array( $instance, $exclude ) ) {
					continue;
				}
				if ( !$this->queueLock->obtainLock( $instance ) ) {
					// Another (or hopefully not, this) runner is running is, cool it down for some time
					$this->logger->debug( "Skipping \"$instance\", locked by another runner" );
					$this->skippedUntil[$instance] = time() + self::SKIP_COOLDOWN;
					continue;
				}
				// Obtained lock, mark as in-flight and return instance
				$this->inFlight[$instance] = true;
				$this->oldestJobsAtStart[$instance] = $this->getOldestJobs( $instance );
				return $instance;
			}
		}

		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function onFailure( string $instance ): void {
		$this->queueLock->release( $instance );
		unset( $this->inFlight[$instance], $this->oldestJobsAtStart[$instance] );
		$this->pause( $instance, 'run failed' );
	}

	/**
	 * @inheritDoc
	 */
	public function onSuccess( string $instance ): void {
		$this->queueLock->release( $instance );
		unset( $this->inFlight[$instance] );
		$before = $this->oldestJobsAtStart[$instance] ?? [];
		unset( $this->oldestJobsAtStart[$instance] );
		// Jobs are popped from the end of `l-unclaimed`. If the oldest job of no queue has changed, the run did not
		// pop anything (e.g. job types unknown to the wiki), and would be started again and again without the pause
		if ( $before && $this->getOldestJobs( $instance ) === $before ) {
			$this->pause( $instance, 'no jobs were taken from the queue' );
		}
	}

	/**
	 * @param string $instance
	 * @param string $reason
	 * @return void
	 */
	private function pause( string $instance, string $reason ): void {
		$backoff = $this->config->getFarmConfig()['backoff'];
		if ( $backoff <= 0 ) {
			return;
		}
		$this->skippedUntil[$instance] = time() + $backoff;
		$this->logger->warning( "Pausing \"$instance\" for {$backoff}s, $reason" );
	}

	/**
	 * @param string $instance Key prefix ({wiki_id})
	 * @return array<string, string|false> Queue key => ID of the oldest unclaimed job
	 * @throws RedisException
	 */
	private function getOldestJobs( string $instance ): array {
		$pattern = addcslashes( $instance, '*?[]\\' ) . ':jobqueue:*:l-unclaimed';
		$keys = [];
		$cursor = null;
		while ( $cursor !== 0 ) {
			$result = $this->redis->scan( $cursor, $pattern, 100 );
			if ( $result === false ) {
				break;
			}
			array_push( $keys, ...$result );
		}
		$oldest = [];
		foreach ( array_unique( $keys ) as $key ) {
			$oldest[$key] = $this->redis->lIndex( $key, -1 );
		}
		ksort( $oldest );
		return $oldest;
	}

	/**
	 * @return void
	 * @throws RedisException
	 */
	private function assertRedisConnection(): void {
		if ( $this->redis !== null ) {
			return;
		}
		$connection = $this->config->getRedisConnection();
		if ( !$connection ) {
			throw new RuntimeException( 'Redis connection not configured' );
		}
		$this->redis = new Redis();
		try {
			$this->redis->connect( $connection['host'], $connection['port'] );
		} catch ( RedisException $e ) {
			throw new RuntimeException( 'Failed to connect to Redis: ' . $e->getMessage() );
		}
		if ( !empty( $connection['password'] ) ) {
			$this->redis->auth( $connection['password'] );
		}
		if ( ( $connection['database'] ?? 0 ) > 0 ) {
			$this->redis->select( $connection['database'] );
		}
		$this->logger->notice(
			"Connected to Redis at {$connection['host']}:{$connection['port']}, database {$connection['database']}"
		);
	}
}
