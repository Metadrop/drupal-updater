<?php

namespace DrupalUpdater\Tests\Simulator;

use DrupalUpdater\Tests\Simulator\Effect\Passthrough;

/**
 * Answers a call to a tool: scripted rule, real tool or simulated default.
 */
abstract class ToolSimulator {

  /**
   * Exit code of a command the simulator does not support.
   */
  public const UNSUPPORTED = 90;

  /**
   * Answers the call.
   *
   * @return int
   *   Exit code.
   */
  public function handle(Invocation $invocation): int {
    $state = $invocation->state();
    $rule = $state->takeRule($invocation->tool(), $invocation->arguments());
    if ($rule !== NULL) {
      return $rule->apply($invocation);
    }
    if ($state->scenario()->ruleSet($invocation->tool())->isRealByDefault()) {
      return (new Passthrough())->apply($invocation);
    }
    return $this->simulate($invocation);
  }

  /**
   * Answers a call no rule matches.
   *
   * @return int
   *   Exit code.
   */
  abstract protected function simulate(Invocation $invocation): int;

  /**
   * Fails loudly on a command the simulator does not know.
   *
   * @return int
   *   Exit code.
   */
  protected function unsupported(Invocation $invocation): int {
    $message = sprintf(
      '%s does not simulate "%s". Declare a rule for it in the test scenario or extend %s.',
      $invocation->tool(),
      implode(' ', $invocation->arguments()),
      static::class,
    );
    // Also recorded: a pipeline (composer ... | awk) would hide the exit code.
    $invocation->state()->recordUnsupported($message);
    $invocation->err('[simulator] ' . $message . "\n");
    return self::UNSUPPORTED;
  }

}
