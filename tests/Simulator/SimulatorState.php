<?php

namespace DrupalUpdater\Tests\Simulator;

/**
 * The scenario and the call log, shared by the test and the simulated tools.
 *
 * It lives in a directory outside the project, which the tests pass to the
 * simulated tools in the DRUPAL_UPDATER_SIMULATOR environment variable.
 */
final class SimulatorState {

  public const ENVIRONMENT_VARIABLE = 'DRUPAL_UPDATER_SIMULATOR';

  /**
   * Scenario loaded from the directory.
   */
  private ?Scenario $scenario = NULL;

  public function __construct(
    private string $directory,
  ) {}

  /**
   * Opens the state the running test set up.
   */
  public static function fromEnvironment(): self {
    $directory = getenv(self::ENVIRONMENT_VARIABLE);
    if ($directory === FALSE || !is_dir($directory)) {
      throw new \RuntimeException(sprintf('%s does not point to a simulator state directory.', self::ENVIRONMENT_VARIABLE));
    }
    return new self($directory);
  }

  /**
   * Gets the directory of the state.
   */
  public function directory(): string {
    return $this->directory;
  }

  /**
   * Stores the scenario and clears the previous calls.
   */
  public function save(Scenario $scenario): void {
    file_put_contents($this->file('scenario.json'), json_encode($scenario->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    foreach (['calls.jsonl', 'consumed.json', 'unsupported.log'] as $name) {
      if (file_exists($this->file($name))) {
        unlink($this->file($name));
      }
    }
    $this->scenario = $scenario;
  }

  /**
   * Gets the scenario.
   */
  public function scenario(): Scenario {
    if ($this->scenario === NULL) {
      $this->scenario = Scenario::fromArray(json_decode((string) file_get_contents($this->file('scenario.json')), TRUE));
    }
    return $this->scenario;
  }

  /**
   * Gets the declared packages, keyed by name.
   *
   * @return array<string, \DrupalUpdater\Tests\Simulator\Package>
   *   Packages.
   */
  public function packages(): array {
    return $this->scenario()->packages();
  }

  /**
   * Gets the path of a real tool, NULL when unknown.
   */
  public function realBinary(string $tool): ?string {
    return $this->scenario()->realBinary($tool);
  }

  /**
   * Finds the rule answering a command, consuming it when it is a once rule.
   *
   * @param string $tool
   *   Tool name.
   * @param list<string> $arguments
   *   Command arguments.
   */
  public function takeRule(string $tool, array $arguments): ?Rule {
    $consumed = $this->consumed();
    foreach ($this->scenario()->ruleSet($tool)->rules() as $index => $rule) {
      $key = $tool . ':' . $index;
      if (in_array($key, $consumed, TRUE) || !$rule->matches($arguments)) {
        continue;
      }
      if ($rule->isOnce()) {
        $consumed[] = $key;
        file_put_contents($this->file('consumed.json'), json_encode($consumed));
      }
      return $rule;
    }
    return NULL;
  }

  /**
   * Records a call to a tool.
   *
   * @param string $tool
   *   Tool name.
   * @param list<string> $arguments
   *   Command arguments.
   * @param array<string, string> $environment
   *   Environment variables relevant to the tool.
   */
  public function recordCall(string $tool, array $arguments, array $environment): void {
    $call = ['tool' => $tool, 'arguments' => $arguments, 'environment' => $environment];
    file_put_contents($this->file('calls.jsonl'), json_encode($call, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
  }

  /**
   * Gets the recorded calls of a tool, in order.
   *
   * @return list<array{tool: string, arguments: list<string>, environment: array<string, string>}>
   *   Calls.
   */
  public function calls(string $tool): array {
    if (!file_exists($this->file('calls.jsonl'))) {
      return [];
    }
    $lines = array_filter(explode("\n", (string) file_get_contents($this->file('calls.jsonl'))));
    $calls = array_map(fn (string $line) => json_decode($line, TRUE), $lines);
    return array_values(array_filter($calls, fn (array $call) => $call['tool'] === $tool));
  }

  /**
   * Records a command no simulator or rule could answer.
   */
  public function recordUnsupported(string $message): void {
    file_put_contents($this->file('unsupported.log'), $message . "\n", FILE_APPEND);
  }

  /**
   * Gets the commands no simulator or rule could answer.
   *
   * @return list<string>
   *   Messages, in order.
   */
  public function unsupportedCalls(): array {
    if (!file_exists($this->file('unsupported.log'))) {
      return [];
    }
    return array_values(array_filter(explode("\n", (string) file_get_contents($this->file('unsupported.log')))));
  }

  /**
   * Gets the keys of the once rules already used.
   *
   * @return list<string>
   *   Keys, tool:index.
   */
  private function consumed(): array {
    if (!file_exists($this->file('consumed.json'))) {
      return [];
    }
    return json_decode((string) file_get_contents($this->file('consumed.json')), TRUE);
  }

  /**
   * Gets the path of a state file.
   */
  private function file(string $name): string {
    return $this->directory . '/' . $name;
  }

}
