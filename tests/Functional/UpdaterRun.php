<?php

namespace DrupalUpdater\Tests\Functional;

use DrupalUpdater\Tests\Simulator\Scenario;
use DrupalUpdater\Tests\Simulator\SimulatorState;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

/**
 * The result of running the updater CLI: exit code, output and tool calls.
 */
final class UpdaterRun {

  public function __construct(
    private Process $process,
    private SimulatorState $state,
  ) {}

  /**
   * Gets the exit code.
   */
  public function exitCode(): ?int {
    return $this->process->getExitCode();
  }

  /**
   * Gets the standard and error output.
   */
  public function output(): string {
    return $this->process->getOutput() . $this->process->getErrorOutput();
  }

  /**
   * Gets one section of the output, e.g. section('Report').
   *
   * Sections start at a printHeader1() header, numbered ("// 4. REPORT //")
   * or not, and end at the next one.
   *
   * @param string $title
   *   Title of the section, as passed to printHeader1(), in any case.
   */
  public function section(string $title): string {
    $output = $this->output();
    $header = sprintf('#^// (?:\d+\. )?%s //$#m', preg_quote(strtoupper($title), '#'));
    $found = preg_match($header, $output, $match, PREG_OFFSET_CAPTURE);
    Assert::assertSame(1, $found, sprintf("Section \"%s\" not found in the output:\n%s", $title, $output));
    $start = $match[0][1] + strlen($match[0][0]);
    $end = preg_match('#^// .+ //$#m', $output, $next, PREG_OFFSET_CAPTURE, $start) === 1 ? $next[0][1] : strlen($output);
    return substr($output, $start, $end - $start);
  }

  /**
   * Asserts the updater exited successfully.
   */
  public function assertSuccessful(): self {
    Assert::assertSame(0, $this->exitCode(), $this->output());
    return $this;
  }

  /**
   * Asserts the updater failed.
   */
  public function assertFailed(): self {
    Assert::assertNotSame(0, $this->exitCode(), $this->output());
    return $this;
  }

  /**
   * Asserts the output contains a text.
   */
  public function assertOutputContains(string $text): self {
    Assert::assertStringContainsString($text, $this->output());
    return $this;
  }

  /**
   * Gets the arguments of every call to a tool, in order.
   *
   * @return list<list<string>>
   *   Arguments of each call.
   */
  public function calls(string $tool = Scenario::COMPOSER): array {
    return array_column($this->state->calls($tool), 'arguments');
  }

  /**
   * Gets the environment of every call to a tool, in order.
   *
   * @return list<array<string, string>>
   *   COMPOSER_* and DRUSH_* variables of each call.
   */
  public function callEnvironments(string $tool = Scenario::COMPOSER): array {
    return array_column($this->state->calls($tool), 'environment');
  }

  /**
   * Counts the calls to a tool containing all the given arguments.
   */
  public function countCalls(string $tool, string ...$tokens): int {
    return count(array_filter($this->calls($tool), fn (array $arguments) => array_diff($tokens, $arguments) === []));
  }

  /**
   * Asserts a tool was called with all the given arguments.
   */
  public function assertCalled(string $tool, string ...$tokens): self {
    Assert::assertGreaterThan(0, $this->countCalls($tool, ...$tokens), $this->callsMessage($tool, array_values($tokens)));
    return $this;
  }

  /**
   * Asserts a tool was never called with all the given arguments.
   */
  public function assertNotCalled(string $tool, string ...$tokens): self {
    Assert::assertSame(0, $this->countCalls($tool, ...$tokens), $this->callsMessage($tool, array_values($tokens)));
    return $this;
  }

  /**
   * Builds the failure message listing the calls to a tool.
   *
   * @param string $tool
   *   Tool name.
   * @param list<string> $tokens
   *   Expected arguments.
   */
  private function callsMessage(string $tool, array $tokens): string {
    $calls = array_map(fn (array $arguments) => '  ' . $tool . ' ' . implode(' ', $arguments), $this->calls($tool));
    return sprintf("Looking for %s calls with \"%s\". Calls:\n%s", $tool, implode(' ', $tokens), implode("\n", $calls));
  }

}
