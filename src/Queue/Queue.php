<?php

namespace BlueSpice\Service\ParallelRunJobs\Queue;

interface Queue {

	/**
	 * Get next instance to run
	 * @return string|null
	 */
	public function getNext(): ?string;

	/**
	 * @param string $instance
	 * @return void
	 */
	public function onFailure( string $instance ): void;

	/**
	 * @param string $instance
	 * @return void
	 */
	public function onSuccess( string $instance ): void;
}
