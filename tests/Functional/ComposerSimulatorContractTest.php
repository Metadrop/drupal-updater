<?php

namespace DrupalUpdater\Tests\Functional;

use DrupalUpdater\Tests\Simulator\Package;
use Symfony\Component\Process\Process;

/**
 * Checks the Composer simulator answers like the real Composer.
 *
 * The same project is installed by the real Composer, and every command line
 * the updater runs is executed against both, pipes included. When the updater
 * starts running a new Composer command, add it here.
 */
final class ComposerSimulatorContractTest extends UpdaterTestCase {

  /**
   * Command lines the updater runs, as UpdaterCommand builds them.
   */
  private const COMMANDS = [
    'composer show --locked --outdated --name-only  2>/dev/null',
    'composer show --locked --outdated --name-only --no-dev 2>/dev/null',
    'composer show --locked --direct --name-only  2>/dev/null',
    'composer show --locked --direct --name-only --no-dev 2>/dev/null',
    'composer show --locked --outdated --format json 2>/dev/null',
    'composer show --locked --outdated --direct 2>/dev/null',
    'composer show --locked --outdated 2>/dev/null',
    "composer why example/dependency --locked 2>/dev/null",
    "timeout 10 composer why example/dependency --locked -r 2>/dev/null",
    "composer why example/library --locked | awk '{print $1}'",
    "composer show drupal/example | grep ^type | awk '{print $3}'",
    "composer show example/library | grep ^type | awk '{print $3}'",
    'composer audit --locked --format plain 2>/dev/null',
    'composer install --no-interaction --no-progress --no-scripts',
    'composer audit --locked  --format plain 2>&1 | grep ^Package | cut -f2 -d: | sed "s/^ *//" | sort -u',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->scenario()
      ->package(Package::named('example/library', '1.0.0')->withLatest('1.1.0')->requires('example/dependency'))
      ->package(Package::named('example/dependency', '1.0.0')->withLatest('1.3.0')->asTransitive())
      ->package(Package::named('drupal/example', '1.0.0')->withLatest('1.2.0')->asDrupalModule())
      ->package(Package::named('example/major', '1.0.0')->withLatest('2.0.0'))
      ->package(Package::named('example/current', '1.0.0'))
      ->package(Package::named('example/devtool', '1.0.0')->withLatest('1.1.0')->asDev());
    $this->installWithRealComposer();
    $this->state()->save($this->scenario());
  }

  /**
   * Tests the simulator prints what Composer prints for each command.
   */
  public function testCommandOutputsMatchRealComposer(): void {
    $mismatches = [];
    foreach (self::COMMANDS as $command) {
      $real = $this->shell($command, $this->composerEnvironment());
      $simulated = $this->shell($command, $this->simulatedEnvironment());
      if ($real !== $simulated) {
        $mismatches[] = sprintf("%s\n--- real\n%s\n--- simulated\n%s", $command, $real, $simulated);
      }
    }
    self::assertSame([], $mismatches, implode("\n\n", $mismatches));
  }

  /**
   * Tests an update with dependencies locks the same versions.
   */
  public function testUpdateWithDependenciesLocksTheSameVersions(): void {
    $command = 'composer update example/library --with-dependencies --no-scripts';
    $this->shell($command, $this->composerEnvironment());
    $real = $this->lockedVersions();
    $this->project()->git('checkout', 'composer.lock');

    $this->shell($command, $this->simulatedEnvironment());

    self::assertSame($real, $this->lockedVersions());
    self::assertSame('1.3.0', $real['example/dependency']);
  }

  /**
   * Runs a command line in the project and returns its exit code and output.
   *
   * @param string $command
   *   Shell command line.
   * @param array<string, string> $environment
   *   Environment variables.
   */
  private function shell(string $command, array $environment): string {
    $process = Process::fromShellCommandline($command, $this->project()->path(), $environment);
    $process->setTimeout(60);
    $process->run();
    return sprintf("[exit %d]\n%s", $process->getExitCode(), $this->normalizeJson($process->getOutput()));
  }

  /**
   * Sorts JSON keys, whose order changes between Composer versions.
   *
   * The updater decodes JSON outputs, so only their content must match.
   */
  private function normalizeJson(string $output): string {
    $data = json_decode($output, TRUE);
    if (!is_array($data)) {
      return $output;
    }
    $sort = function (array &$value) use (&$sort): void {
      ksort($value);
      foreach ($value as &$child) {
        if (is_array($child)) {
          $sort($child);
        }
      }
    };
    $sort($data);
    return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  /**
   * Gets the locked versions keyed by package name.
   *
   * @return array<string, string>
   *   Versions.
   */
  private function lockedVersions(): array {
    $versions = [];
    foreach (array_keys($this->scenario()->packages()) as $name) {
      $versions[$name] = (string) $this->project()->lockedVersion($name);
    }
    return $versions;
  }

}
