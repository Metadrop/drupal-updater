<?php

namespace DrupalUpdater\Tests\Simulator;

/**
 * Entry point of the simulated executables in tests/bin.
 */
final class Simulator {

  /**
   * Runs a simulated tool and records the call.
   *
   * @param string $tool
   *   Tool name, one of the Scenario constants.
   * @param list<string> $arguments
   *   Command arguments, without the tool name.
   *
   * @return int
   *   Exit code.
   */
  public static function run(string $tool, array $arguments): int {
    $state = SimulatorState::fromEnvironment();
    $state->recordCall($tool, $arguments, self::relevantEnvironment());
    $invocation = new Invocation($tool, $arguments, (string) getcwd(), $state, STDOUT, STDERR);
    return self::simulatorFor($tool)->handle($invocation);
  }

  /**
   * Gets the simulator of a tool.
   */
  private static function simulatorFor(string $tool): ToolSimulator {
    return match ($tool) {
      Scenario::COMPOSER => new ComposerSimulator(),
      Scenario::DRUSH => new DrushSimulator(),
      Scenario::LOCK_DIFF => new LockDiffSimulator(),
      default => throw new \InvalidArgumentException(sprintf('No simulator for "%s".', $tool)),
    };
  }

  /**
   * Gets the environment variables a test may want to check, e.g. timeouts.
   *
   * @return array<string, string>
   *   Variables whose name starts with COMPOSER_ or DRUSH_.
   */
  private static function relevantEnvironment(): array {
    return array_filter(
      getenv(),
      fn (string $name) => str_starts_with($name, 'COMPOSER_') || str_starts_with($name, 'DRUSH_'),
      ARRAY_FILTER_USE_KEY,
    );
  }

}
