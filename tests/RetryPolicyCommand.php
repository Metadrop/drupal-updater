<?php

namespace DrupalUpdater\Tests;

use DrupalUpdater\Config\Config;
use DrupalUpdater\UpdaterCommand;

/**
 * Exposes the Composer retry policy of the updater command.
 */
class RetryPolicyCommand extends UpdaterCommand {

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
   * Gets the retry delay after the given failed attempt.
   *
   * @param int $attempt
   *   Failed attempt number.
   *
   * @return int
   *   Delay in seconds.
   */
  public function delay(int $attempt): int {
    return $this->composerRetryDelay($attempt);
  }

  /**
   * Checks whether a failure message is considered transient.
   *
   * @param string $message
   *   Failure message.
   *
   * @return bool
   *   TRUE when the failure would be retried.
   */
  public function transient(string $message): bool {
    return $this->isTransientComposerFailure(new \RuntimeException($message));
  }

}
