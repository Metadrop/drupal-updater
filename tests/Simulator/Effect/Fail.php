<?php

namespace DrupalUpdater\Tests\Simulator\Effect;

use DrupalUpdater\Tests\Simulator\Invocation;

/**
 * Prints an error and ends the command with a non-zero exit code.
 */
final class Fail implements EffectInterface {

  public function __construct(
    private string $stderr,
    private int $exitCode,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function apply(Invocation $invocation): int {
    $invocation->err($this->stderr);
    return $this->exitCode;
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return ['type' => 'fail', 'stderr' => $this->stderr, 'exitCode' => $this->exitCode];
  }

}
