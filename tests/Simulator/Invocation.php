<?php

namespace DrupalUpdater\Tests\Simulator;

/**
 * One call to a simulated tool: its arguments, directory and outputs.
 */
final class Invocation {

  /**
   * Creates the invocation.
   *
   * @param string $tool
   *   Tool name.
   * @param list<string> $arguments
   *   Command arguments, without the tool name.
   * @param string $cwd
   *   Working directory, the project root.
   * @param \DrupalUpdater\Tests\Simulator\SimulatorState $state
   *   Simulator state.
   * @param resource $stdout
   *   Standard output stream.
   * @param resource $stderr
   *   Error output stream.
   */
  public function __construct(
    private string $tool,
    private array $arguments,
    private string $cwd,
    private SimulatorState $state,
    private $stdout,
    private $stderr,
  ) {}

  /**
   * Gets the tool name.
   */
  public function tool(): string {
    return $this->tool;
  }

  /**
   * Gets the command arguments, without the tool name.
   *
   * @return list<string>
   *   Arguments.
   */
  public function arguments(): array {
    return $this->arguments;
  }

  /**
   * Whether the command has any of the given options.
   */
  public function hasOption(string ...$options): bool {
    return array_intersect($options, $this->arguments) !== [];
  }

  /**
   * Gets the value of an option given as "--name value" or "--name=value".
   */
  public function option(string $name): ?string {
    foreach ($this->arguments as $index => $argument) {
      if ($argument === $name) {
        return $this->arguments[$index + 1] ?? NULL;
      }
      if (str_starts_with($argument, $name . '=')) {
        return substr($argument, strlen($name) + 1);
      }
    }
    return NULL;
  }

  /**
   * Gets the positional arguments after the subcommand.
   *
   * @param list<string> $options_with_value
   *   Options whose next argument is their value, not a positional one.
   *
   * @return list<string>
   *   Positional arguments.
   */
  public function positionals(array $options_with_value = []): array {
    $positionals = [];
    $skip_next = FALSE;
    foreach (array_slice($this->arguments, 1) as $argument) {
      if ($skip_next) {
        $skip_next = FALSE;
        continue;
      }
      if (str_starts_with($argument, '-')) {
        $skip_next = in_array($argument, $options_with_value, TRUE);
        continue;
      }
      $positionals[] = $argument;
    }
    return $positionals;
  }

  /**
   * Gets the working directory.
   */
  public function cwd(): string {
    return $this->cwd;
  }

  /**
   * Resolves a path relative to the working directory.
   */
  public function path(string $relative): string {
    return $this->cwd . '/' . $relative;
  }

  /**
   * Gets the simulator state.
   */
  public function state(): SimulatorState {
    return $this->state;
  }

  /**
   * Writes to the standard output.
   */
  public function out(string $text): void {
    fwrite($this->stdout, $text);
  }

  /**
   * Writes to the error output.
   */
  public function err(string $text): void {
    fwrite($this->stderr, $text);
  }

}
