<?php

namespace BlueSpice\Service\ParallelRunJobs;

use InvalidArgumentException;

class Config {

	/** @var string */
	public $path;
	/** @var string */
	public $type;
	/** @var string */
	public string $queue;
	/** @var array|null */
	public ?array $connection;
	/** @var array|null */
	public ?array $redisConnection;
	/** @var array */
	public $jobConfig;
	/** @var array */
	public $farmConfig;
	/** @var array */
	public $environment;

	/**
	 * @param array $values
	 * @return static
	 */
	public static function newFromValues( mixed $values ): static {
		$environment = $values['environment'] ?? [];
		$wiki = $values['wiki'] ?? [];

		$jobs = $values['runjobs'] ?? [];
		$farm = $values['farm'] ?? [];
		$farm['maxparallel'] = (int)( $farm['maxparallel'] ?? 10 );

		static::assertRequiredValues( [ 'path' ], $wiki );
		$queue = $values['queue'] ?? 'database';
		$connection = null;
		$redisConnection = null;
		if ( $wiki['type'] === 'farm' ) {
			$dbConfig = $values['database'] ?? [];
			static::assertRequiredValues( [ 'dbname', 'dbuser', 'dbpassword' ], $dbConfig );
			$connection = [
				'dbserver' => $dbConfig['dbserver'] ?? 'localhost',
				'dbname' => $dbConfig['dbname'],
				'dbuser' => $dbConfig['dbuser'],
				'dbpassword' => $dbConfig['dbpassword'],
				'dbprefix' => $dbConfig['dbprefix'] ?? '',
				'dbport' => $dbConfig['dbport'] ?? '3306',
			];
			if ( $queue === 'redis' ) {
				$redisConfig = $values['redis'] ?? [];
				static::assertRequiredValues( [ 'host', 'port' ], $redisConfig );
				$redisConnection = [
					'host' => $redisConfig['host'],
					'port' => (int)( $redisConfig['port'] ),
					'password' => $redisConfig['password'] ?? null,
					'database' => (int)( $redisConfig['database'] ?? 0 ),
				];
			} elseif ( $queue !== 'database' ) {
				throw new InvalidArgumentException( "Unsupported queue type: $queue" );
			}
		}

		$jobs = [
			'maxtime' => (int)( $jobs['maxtime'] ?? 30 ),
			'maxjobs' => (int)( $jobs['maxjobs'] ?? 100 ),
			'cooldown' => (int)( $jobs['cooldown'] ?? 1 ),
		];
		$environment = [
			'php' => $environment['php'] ?? '/usr/bin/php',
		];

		return new static(
			$wiki['path'],
			$wiki['type'] ?? 'standalone',
			$queue,
			$connection,
			$redisConnection,
			$jobs,
			$farm,
			$environment
		);
	}

	/**
	 * @param array $fields
	 * @param array $data
	 * @return void
	 */
	private static function assertRequiredValues( array $fields, array $data ) {
		foreach ( $fields as $field ) {
			if ( !isset( $data[$field] ) ) {
				throw new InvalidArgumentException( "Missing required field: $field" );
			}
		}
	}

	/**
	 * @param string $path
	 * @param string $type
	 * @param string $queue
	 * @param array|null $connection
	 * @param array|null $redisConnection
	 * @param array $jobConfig
	 * @param array $farmConfig
	 * @param array $environment
	 */
	public function __construct(
		string $path, string $type, string $queue, ?array $connection, ?array $redisConnection,
		array $jobConfig, array $farmConfig, array $environment
	) {
		$this->path = $path;
		$this->type = $type;
		$this->queue = $queue;
		$this->connection = $connection;
		$this->redisConnection = $redisConnection;
		$this->jobConfig = $jobConfig;
		$this->farmConfig = $farmConfig;
		$this->environment = $environment;
	}

	/**
	 * @return string
	 */
	public function getPath(): string {
		return $this->path;
	}

	/**
	 * @return bool
	 */
	public function isFarmingEnvironment(): bool {
		return $this->type === 'farm';
	}

	/**
	 * @return string
	 */
	public function getType(): string {
		return $this->type;
	}

	/**
	 * @return string
	 */
	public function getQueue(): string {
		return $this->queue;
	}

	/**
	 * @return array|null
	 */
	public function getConnection(): ?array {
		return $this->connection;
	}

	/**
	 * @return array|null
	 */
	public function getRedisConnection(): ?array {
		return $this->redisConnection;
	}

	/**
	 * @return array
	 */
	public function getJobConfig(): array {
		return $this->jobConfig;
	}

	/**
	 * @return array
	 */
	public function getFarmConfig(): array {
		return $this->isFarmingEnvironment() ? $this->farmConfig : [];
	}

	public function getEnvironment(): array {
		return $this->environment;
	}

	/**
	 * @return string
	 */
	public function getPhpPath(): string {
		return $this->environment['php'];
	}

	/**
	 * @return string
	 */
	public function getRunJobsPath(): string {
		return rtrim( $this->path, '/' ) . '/maintenance/runJobs.php';
	}
}
