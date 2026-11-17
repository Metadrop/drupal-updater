<?php

namespace DrupalUpdater\Tests\Simulator\Effect;

use DrupalUpdater\Tests\Simulator\Invocation;

/**
 * One step of what a scripted rule does when it matches a command.
 */
interface EffectInterface {

  /**
   * Applies the effect.
   *
   * @param \DrupalUpdater\Tests\Simulator\Invocation $invocation
   *   The simulated command being run.
   *
   * @return int
   *   Exit code; anything but 0 stops the remaining effects of the rule.
   */
  public function apply(Invocation $invocation): int;

  /**
   * Gets the array form, stored in the scenario file.
   *
   * @return array<string, mixed>
   *   Effect data, with its 'type'.
   */
  public function toArray(): array;

}
