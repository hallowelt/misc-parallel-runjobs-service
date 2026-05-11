<?php

namespace BlueSpice\Service\ParallelRunJobs\QueueLock;

use BlueSpice\Service\ParallelRunJobs\Config;
use Redis;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\RedisStore;

class RedisQueueLock {

	private const KEY_PREFIX = 'runjobs:lock:';

	/** @var LockFactory */
	private LockFactory $lockFactory;

	/** @var float */
	private float $lockTtl;

	/** @var array<string, LockInterface> */
	private array $locks = [];

	/**
	 * @param Redis $redis
	 * @param Config $config
	 */
	public function __construct( Redis $redis, Config $config ) {
		$this->lockTtl = (float)max( ( $config->getJobConfig()['maxtime'] ?? 30 ) * 2, 60 );

		$store = new RedisStore( $redis );
		$this->lockFactory = new LockFactory( $store );
	}

	/**
	 * @param string $instance
	 * @return bool
	 */
	public function obtainLock( string $instance ): bool {
		$lock = $this->lockFactory->createLock( self::KEY_PREFIX . $instance, $this->lockTtl, false );
		if ( !$lock->acquire( false ) ) {
			// Could not acquire lock, likely because another runner has it. Skip this instance for now.
			return false;
		}
		$this->locks[$instance] = $lock;
		return true;
	}

	/**
	 * @param string $instance
	 * @return bool
	 */
	public function release( string $instance ): bool {
		if ( !isset( $this->locks[$instance] ) ) {
			return false;
		}
		$this->locks[$instance]->release();
		unset( $this->locks[$instance] );
		return true;
	}
}
