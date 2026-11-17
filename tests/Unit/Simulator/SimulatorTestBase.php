<?php

namespace DrupalUpdater\Tests\Unit\Simulator;

use DrupalUpdater\Tests\Simulator\Filesystem;
use DrupalUpdater\Tests\Simulator\Invocation;
use DrupalUpdater\Tests\Simulator\Scenario;
use DrupalUpdater\Tests\Simulator\SimulatorState;
use PHPUnit\Framework\TestCase;

/**
 * Runs simulated tools in-process, with captured outputs.
 */
abstract class SimulatorTestBase extends TestCase {

  /**
   * Temporary directory with the project files and the state.
   */
  protected string $directory;

  /**
   * Standard output of the last invocation.
   *
   * @var resource
   */
  private $stdout;

  /**
   * Error output of the last invocation.
   *
   * @var resource
   */
  private $stderr;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->directory = sys_get_temp_dir() . '/drupal-updater-unit-' . bin2hex(random_bytes(6));
    mkdir($this->directory . '/state', 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    Filesystem::remove($this->directory);
    parent::tearDown();
  }

  /**
   * Saves the scenario and creates an invocation of a tool.
   *
   * @param \DrupalUpdater\Tests\Simulator\Scenario $scenario
   *   Scenario.
   * @param string $tool
   *   Tool name.
   * @param string ...$arguments
   *   Command arguments.
   */
  protected function invocation(Scenario $scenario, string $tool, string ...$arguments): Invocation {
    $state = new SimulatorState($this->directory . '/state');
    $state->save($scenario);
    return $this->nextInvocation($state, $tool, ...$arguments);
  }

  /**
   * Creates another invocation on the same state, keeping consumed rules.
   *
   * @param \DrupalUpdater\Tests\Simulator\SimulatorState $state
   *   State of a previous invocation.
   * @param string $tool
   *   Tool name.
   * @param string ...$arguments
   *   Command arguments.
   */
  protected function nextInvocation(SimulatorState $state, string $tool, string ...$arguments): Invocation {
    $this->stdout = $this->memoryStream();
    $this->stderr = $this->memoryStream();
    return new Invocation($tool, array_values($arguments), $this->directory, $state, $this->stdout, $this->stderr);
  }

  /**
   * Opens an in-memory stream to capture an output.
   *
   * @return resource
   *   Stream.
   */
  private function memoryStream() {
    $stream = fopen('php://memory', 'w+');
    if ($stream === FALSE) {
      throw new \RuntimeException('Cannot open an in-memory stream.');
    }
    return $stream;
  }

  /**
   * Gets what the last invocation printed to the standard output.
   */
  protected function stdout(): string {
    rewind($this->stdout);
    return (string) stream_get_contents($this->stdout);
  }

  /**
   * Gets what the last invocation printed to the error output.
   */
  protected function stderr(): string {
    rewind($this->stderr);
    return (string) stream_get_contents($this->stderr);
  }

}
