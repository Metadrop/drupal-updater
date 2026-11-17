<?php

namespace DrupalUpdater\Tests\Simulator;

use DrupalUpdater\Tests\Simulator\Effect\Passthrough;

/**
 * Runs the real composer-lock-diff unless a rule answers the call.
 */
final class LockDiffSimulator extends ToolSimulator {

  /**
   * {@inheritdoc}
   */
  protected function simulate(Invocation $invocation): int {
    return (new Passthrough())->apply($invocation);
  }

}
