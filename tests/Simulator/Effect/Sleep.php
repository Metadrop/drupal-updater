<?php

namespace DrupalUpdater\Tests\Simulator\Effect;

use DrupalUpdater\Tests\Simulator\Invocation;

/**
 * Waits, to simulate a slow command.
 */
final class Sleep implements EffectInterface {

  public function __construct(
    private float $seconds,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function apply(Invocation $invocation): int {
    usleep((int) ($this->seconds * 1000000));
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return ['type' => 'sleep', 'seconds' => $this->seconds];
  }

}
