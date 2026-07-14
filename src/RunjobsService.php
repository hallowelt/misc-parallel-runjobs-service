<?php

namespace BlueSpice\Service\ParallelRunJobs;

use BlueSpice\Service\ParallelRunJobs\Runner\Parallel;
use BlueSpice\Service\ParallelRunJobs\Runner\Single;
use Psr\Log\LoggerInterface;

class RunjobsService {

	/** @var Config */
	private Config $config;
	/** @var LoggerInterface */
	private LoggerInterface $logger;

	/**
	 * @param Config $config
	 * @param LoggerInterface $logger
	 */
	public function __construct( Config $config, LoggerInterface $logger ) {
		$this->config = $config;
		$this->logger = $logger;
	}

	/**
	 * @return mixed
	 * @throws \RedisException
	 */
	public function run() {
		$runner = $this->config->isFarmingEnvironment() ?
			new Parallel( $this->config, $this->logger ) :
			new Single( $this->config, $this->logger );

		$runner->start();
	}
}
