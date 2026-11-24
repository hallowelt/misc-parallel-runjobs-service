<?php

namespace BlueSpice\Service\ParallelRunJobs\Database;

/**
 * Read access to the management database of a wiki farm (the database of the root wiki)
 */
interface ManagementDatabase {

	/**
	 * Paths of all farm instances in status "ready"
	 *
	 * @return string[]
	 * @throws ManagementDatabaseException
	 */
	public function getReadyInstancePaths(): array;
}
