<?php

namespace BlueSpice\Service\ParallelRunJobs;

use BlueSpice\Service\ParallelRunJobs\Runner\Parallel;
use BlueSpice\Service\ParallelRunJobs\Runner\Single;
use Symfony\Component\Console\Output\OutputInterface;

class RunjobsService {

	/** @var Config */
	private Config $config;
	/** @var OutputInterface */
	private OutputInterface $output;

	/**
	 * @param Config $config
	 * @param OutputInterface $output
	 */
	public function __construct( Config $config, OutputInterface $output ) {
		$this->config = $config;
		$this->output = $output;
	}

	/**
	 * @return mixed
	 * @throws \RedisException
	 */
	public function run() {
		$runner = $this->config->isFarmingEnvironment() ?
			new Parallel( $this->config, $this->output ) :
			new Single( $this->config, $this->output );

		$runner->start();
	}
}
