<?php

namespace BlueSpice\Service\ParallelRunJobs;

use Monolog\Handler\FilterHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class RunjobsCommand extends Command {

	/** @var string */
	protected static $defaultName = 'runjobs';

	protected function configure() {
		$this
			->setDescription( 'Execute runjobs service parallelly for multiple instances.' )
			->setHelp( 'This command executes the runjobs service for multiple wiki farm instances as well as non-farm instances.' )
			->addOption( 'config', 'c', InputOption::VALUE_OPTIONAL, 'Path to the configuration file (YAML). If not provided, the default path will be used.' )
			->addOption( 'wiki-type', null, InputOption::VALUE_OPTIONAL, 'Type of wiki (standalone or farm).' )
			->addOption( 'wiki-path', null, InputOption::VALUE_OPTIONAL, 'Absolute path of the wiki.' )
			->addOption( 'wiki-reference', null, InputOption::VALUE_OPTIONAL, 'Reference file for the wiki (e.g., LocalSettings.php).' )
			->addOption( 'runjobs-percentage', null, InputOption::VALUE_OPTIONAL, 'Maximum percentage of total jobs (per wiki, per cycle).' )
			->addOption( 'runjobs-maxtime', null, InputOption::VALUE_OPTIONAL, 'Maximum life time of a runJobs.php (per wiki, per cycle) in seconds.' )
			->addOption( 'runjobs-cooldown', null, InputOption::VALUE_OPTIONAL, 'Wait time after each cycle in seconds.' )
			->addOption( 'runjobs-maxforkprocesses', null, InputOption::VALUE_OPTIONAL, 'Maximum number of sub processes that can be spawned for parallel processing' )
			->addOption( 'exclude-instances', null, InputOption::VALUE_OPTIONAL, 'Contains list of comma separated farm instances name for which the runjobs should not be run' )
			->addOption( 'include-instances', null, InputOption::VALUE_OPTIONAL, 'Contains list of comma separated farm instances name for which  the runjobs should be run, if left empty, all the farm instances will be considered except the ones in "exclude-instances" list' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		if ( !$input->hasOption( 'config' ) ) {
			$output->writeln( '<error>No configuration file provided</error>' );
			return Command::INVALID;
		}
		$configFilePath = $input->getOption( 'config' );
		$config = $this->loadConfig( $configFilePath );

		$level = Level::fromName( $config->getLogLevel() );
		$logger = new Logger( 'parallel-runjobs' );
		$stdoutHandler = new FilterHandler(
			new StreamHandler( 'php://stdout', $level ),
			Level::Debug, Level::Notice
		);
		$stderrHandler = new FilterHandler(
			new StreamHandler( 'php://stderr', $level ),
			Level::Warning, Level::Emergency
		);
		$logger->pushHandler( $stdoutHandler );
		$logger->pushHandler( $stderrHandler );

		$logger->notice( sprintf(
			'Starting runjobs service (type: %s, wiki path: %s, queue: %s, log level: %s, php: %s, ' .
				'maxjobs: %d, maxtime: %ds, cooldown: %ds)',
			$config->getType(),
			$config->getPath(),
			$config->isFarmingEnvironment() ? $config->getQueue() : 'n/a',
			$config->getLogLevel(),
			$config->getPhpPath(),
			$config->getJobConfig()['maxjobs'],
			$config->getJobConfig()['maxtime'],
			$config->getJobConfig()['cooldown']
		) );
		$this->registerShutdownSignals( $logger );

		$runjobsService = new RunjobsService( $config, $logger );
		$runjobsService->run();

		return Command::SUCCESS;
	}

	/**
	 * Log a message when the service is stopped (e.g. by the init script), if pcntl is available
	 *
	 * @param Logger $logger
	 * @return void
	 */
	private function registerShutdownSignals( Logger $logger ): void {
		if ( !function_exists( 'pcntl_signal' ) ) {
			return;
		}
		pcntl_async_signals( true );
		foreach ( [ SIGTERM => 'SIGTERM', SIGINT => 'SIGINT' ] as $signal => $name ) {
			pcntl_signal( $signal, static function () use ( $logger, $name ) {
				$logger->notice( "Received $name, shutting down" );
				exit( 0 );
			} );
		}
	}

	/**
	 * @param string $configFilePath
	 * @return Config
	 */
	protected function loadConfig( string $configFilePath ): Config {
		if ( !file_exists( $configFilePath ) ) {
			throw new RuntimeException( "Configuration file not found at: $configFilePath" );
		}
		try {
			$values = Yaml::parseFile( $configFilePath );
		} catch ( Throwable $ex ) {
			throw new RuntimeException( "Error parsing configuration file: " . $ex->getMessage() );
		}

		return Config::newFromValues( $values );
	}
}
