<?php

namespace DrupalUpdater\Tests;

use DrupalUpdater\Config\Config;
use DrupalUpdater\UpdaterCommand;
use Symfony\Component\Process\Process;

/**
 * Exposes the command runner without replacing its behavior.
 */
class TimeoutCommand extends UpdaterCommand {

  /**
   * Creates the command with the given configuration.
   *
   * @param \DrupalUpdater\Config\Config $config
   *   Updater configuration.
   */
  public function __construct(Config $config) {
    parent::__construct();
    $this->setConfiguration($config);
  }

  /**
   * Runs a shell command through the updater command runner.
   *
   * @param string $command
   *   Shell command.
   *
   * @return \Symfony\Component\Process\Process
   *   The finished process.
   */
  public function executeShell(string $command): Process {
    return $this->runCommand($command);
  }

}
