<?php

namespace DrupalUpdater\Tests\Simulator\Effect;

use DrupalUpdater\Tests\Simulator\Invocation;

/**
 * Prints to the standard and error outputs.
 */
final class Output implements EffectInterface {

  public function __construct(
    private string $stdout,
    private string $stderr = '',
  ) {}

  /**
   * {@inheritdoc}
   */
  public function apply(Invocation $invocation): int {
    $invocation->out($this->stdout);
    $invocation->err($this->stderr);
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return ['type' => 'output', 'stdout' => $this->stdout, 'stderr' => $this->stderr];
  }

}
