<?php

namespace BlueSpice\Service\ParallelRunJobs\Queue;

use BlueSpice\Service\ParallelRunJobs\Config;
use Psr\Log\LoggerInterface;
use Redis;

/**
 * Periodic maintenance of the MediaWiki `JobQueueRedis` queues, which MediaWiki leaves to a
 * background service in "daemonized" mode (the only supported one):
 * - release delayed jobs (`z-delayed`) that are due into `l-unclaimed`
 * - recycle claimed jobs (`z-claimed`) whose claim expired (failed or crashed runs) into `l-unclaimed`,
 *   or move them to `z-abandoned` if they have been tried `maxtries` times already
 * - delete abandoned jobs (`z-abandoned`) older than `pruneage`
 *
 * Every queue is processed atomically by a Lua script, so multiple runners can do this concurrently.
 */
class RedisJobQueueMaintenance {

	/**
	 * Matches `z-claimed`, `z-delayed` and `z-abandoned` of all queues
	 * ({wiki_id}:jobqueue:{type}:{prop}, see `JobQueueRedis::getQueueKey`)
	 */
	private const KEY_PATTERN = '*:jobqueue:*:z-*';

	/** Max number of jobs per queue and step in one run, to not block Redis for too long */
	private const BATCH_SIZE = 1000;

	private const SCRIPT = <<<LUA
local kUnclaimed, kClaimed, kAbandoned, kDelayed, kAttempts, kData = unpack(KEYS)
local now, claimTTL, maxTries, pruneAge, limit =
	tonumber(ARGV[1]), tonumber(ARGV[2]), tonumber(ARGV[3]), tonumber(ARGV[4]), tonumber(ARGV[5])
local released, recycled, abandoned, pruned = 0, 0, 0, 0
-- Release delayed jobs that are due
for _, id in ipairs(redis.call('zRangeByScore', kDelayed, 0, now, 'LIMIT', 0, limit)) do
	redis.call('zRem', kDelayed, id)
	redis.call('lPush', kUnclaimed, id)
	released = released + 1
end
-- Recycle or abandon jobs with expired claims
if claimTTL > 0 then
	for _, id in ipairs(redis.call('zRangeByScore', kClaimed, 0, now - claimTTL, 'LIMIT', 0, limit)) do
		redis.call('zRem', kClaimed, id)
		if tonumber(redis.call('hGet', kAttempts, id) or 0) < maxTries then
			redis.call('lPush', kUnclaimed, id)
			recycled = recycled + 1
		else
			redis.call('zAdd', kAbandoned, now, id)
			abandoned = abandoned + 1
		end
	end
end
-- Delete old abandoned jobs
if pruneAge > 0 then
	for _, id in ipairs(redis.call('zRangeByScore', kAbandoned, 0, now - pruneAge, 'LIMIT', 0, limit)) do
		redis.call('zRem', kAbandoned, id)
		redis.call('hDel', kAttempts, id)
		redis.call('hDel', kData, id)
		pruned = pruned + 1
	end
end
return { released, recycled, abandoned, pruned }
LUA;

	/** @var int */
	private int $lastRun = 0;

	/**
	 * @param Redis $redis
	 * @param Config $config
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		private Redis $redis,
		private Config $config,
		private LoggerInterface $logger
	) {
		$maintenanceConfig = $this->config->getRedisMaintenanceConfig();
		if ( $maintenanceConfig['interval'] <= 0 ) {
			$this->logger->warning(
				'Job queue maintenance is disabled, delayed jobs and expired claims will not be processed'
			);
			return;
		}
		$this->logger->notice( sprintf(
			'Job queue maintenance every %ds (claimttl: %ds, maxtries: %d, pruneage: %ds)',
			$maintenanceConfig['interval'],
			$maintenanceConfig['claimttl'],
			$maintenanceConfig['maxtries'],
			$maintenanceConfig['pruneage']
		) );
	}

	/**
	 * Run the maintenance, if `interval` seconds passed since the last run
	 *
	 * @return void
	 * @throws \RedisException
	 */
	public function runIfDue(): void {
		$interval = $this->config->getRedisMaintenanceConfig()['interval'];
		if ( $interval <= 0 || time() - $this->lastRun < $interval ) {
			return;
		}
		$this->lastRun = time();
		$this->run();
	}

	/**
	 * @return void
	 * @throws \RedisException
	 */
	public function run(): void {
		$maintenanceConfig = $this->config->getRedisMaintenanceConfig();
		$args = [
			time(),
			$maintenanceConfig['claimttl'],
			$maintenanceConfig['maxtries'],
			$maintenanceConfig['pruneage'],
			self::BATCH_SIZE
		];

		$totals = [ 0, 0, 0, 0 ];
		$queues = $this->getQueues();
		foreach ( $queues as $queue ) {
			$keys = [];
			foreach ( [ 'l-unclaimed', 'z-claimed', 'z-abandoned', 'z-delayed', 'h-attempts', 'h-data' ] as $prop ) {
				$keys[] = "$queue:$prop";
			}
			$result = $this->redis->eval( self::SCRIPT, array_merge( $keys, $args ), count( $keys ) );
			if ( !is_array( $result ) ) {
				$this->logger->error( "Job queue maintenance failed for \"$queue\": " . $this->redis->getLastError() );
				$this->redis->clearLastError();
				continue;
			}
			[ $released, $recycled, $abandoned, $pruned ] = array_map( 'intval', $result );
			if ( $abandoned > 0 ) {
				$this->logger->warning(
					"Abandoned $abandoned job(s) of \"$queue\" after {$maintenanceConfig['maxtries']} attempts"
				);
			}
			if ( $released + $recycled + $pruned > 0 ) {
				$this->logger->info(
					"Job queue maintenance of \"$queue\": released $released delayed, recycled $recycled, " .
						"pruned $pruned abandoned job(s)"
				);
			}
			foreach ( [ $released, $recycled, $abandoned, $pruned ] as $i => $count ) {
				$totals[$i] += $count;
			}
		}

		$this->logger->debug( sprintf(
			'Job queue maintenance of %d queue(s): released %d delayed, recycled %d, abandoned %d, pruned %d job(s)',
			count( $queues ), ...$totals
		) );
	}

	/**
	 * @return string[] Key prefixes of the queues ({wiki_id}:jobqueue:{type})
	 * @throws \RedisException
	 */
	private function getQueues(): array {
		$queues = [];
		$cursor = null;
		while ( $cursor !== 0 ) {
			$result = $this->redis->scan( $cursor, self::KEY_PATTERN, 100 );
			if ( $result === false ) {
				break;
			}
			foreach ( $result as $key ) {
				$queues[substr( $key, 0, strrpos( $key, ':' ) )] = true;
			}
		}
		return array_keys( $queues );
	}
}
