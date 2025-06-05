<?php

namespace DrupalUpdater\PostUpdate;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Interface for post-update processors.
 */
interface PostUpdateInterface {

  /**
   * Execute post-update processing for a package.
   *
   * @param string $package
   *   The package name being updated.
   * @param array $composerLockDiff
   *   The composer lock diff data.
   * @param OutputInterface $output
   *   The output interface for logging.
   */
  public function execute(string $package, array $composerLockDiff, OutputInterface $output);

}
