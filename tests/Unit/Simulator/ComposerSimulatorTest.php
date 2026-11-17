<?php

namespace DrupalUpdater\Tests\Unit\Simulator;

use DrupalUpdater\Tests\Simulator\ComposerSimulator;
use DrupalUpdater\Tests\Simulator\DrushSimulator;
use DrupalUpdater\Tests\Simulator\Filesystem;
use DrupalUpdater\Tests\Simulator\Package;
use DrupalUpdater\Tests\Simulator\Scenario;
use DrupalUpdater\Tests\Simulator\ToolSimulator;

/**
 * Covers the simulated defaults that the contract test cannot reach.
 *
 * The output formats themselves are checked against the real Composer in
 * ComposerSimulatorContractTest.
 */
final class ComposerSimulatorTest extends SimulatorTestBase {

  /**
   * Tests an unknown command fails and is recorded, so a pipe cannot hide it.
   */
  public function testUnsupportedCommandFailsAndIsRecorded(): void {
    $invocation = $this->invocation(new Scenario(), Scenario::COMPOSER, 'require', 'example/a');

    $exit_code = (new ComposerSimulator())->handle($invocation);

    self::assertSame(ToolSimulator::UNSUPPORTED, $exit_code);
    self::assertStringContainsString('does not simulate "require example/a"', $this->stderr());
    self::assertCount(1, $invocation->state()->unsupportedCalls());
  }

  /**
   * Tests unknown Drush commands are unsupported too.
   */
  public function testUnsupportedDrushCommandFails(): void {
    $invocation = $this->invocation(new Scenario(), Scenario::DRUSH, '@self', 'sql:dump');

    self::assertSame(ToolSimulator::UNSUPPORTED, (new DrushSimulator())->handle($invocation));
  }

  /**
   * Tests audit reports declared advisories until the package is updated.
   */
  public function testAuditReportsAdvisoriesOfOutdatedVersionsOnly(): void {
    $scenario = (new Scenario())
      ->package(Package::named('example/a', '1.0.0')->withLatest('1.0.1')->withAdvisory('XSS', 'CVE-2026-0002'))
      ->package(Package::named('example/b', '1.0.0')->withLatest('1.0.1')->withAdvisory('SQL injection'));
    $this->writeLock(['example/a' => '1.0.0', 'example/b' => '1.0.1']);

    $exit_code = (new ComposerSimulator())->handle($this->invocation($scenario, Scenario::COMPOSER, 'audit', '--locked', '--format', 'plain'));

    self::assertSame(1, $exit_code);
    self::assertStringContainsString("Found 1 security vulnerability advisory affecting 1 package:\nPackage: example/a\n", $this->stdout());
    self::assertStringContainsString("CVE: CVE-2026-0002\nTitle: XSS\n", $this->stdout());
    self::assertStringNotContainsString('example/b', $this->stdout());
  }

  /**
   * Tests update with a wildcard and without changes.
   */
  public function testUpdateMatchesWildcardsAndReportsNothingToModify(): void {
    $scenario = (new Scenario())
      ->package(Package::named('drupal/core', '10.0.0')->withLatest('10.0.1'))
      ->package(Package::named('drupal/core-recommended', '10.0.0')->withLatest('10.0.1'));
    $this->writeLock(['drupal/core' => '10.0.0', 'drupal/core-recommended' => '10.0.0']);

    (new ComposerSimulator())->handle($this->invocation($scenario, Scenario::COMPOSER, 'update', 'drupal/core*'));
    (new ComposerSimulator())->handle($this->invocation($scenario, Scenario::COMPOSER, 'update', 'drupal/core*'));

    self::assertStringContainsString('Nothing to modify in lock file', $this->stderr());
    $lock = json_decode((string) file_get_contents($this->directory . '/composer.lock'), TRUE);
    self::assertSame(['10.0.1', '10.0.1'], array_column($lock['packages'], 'version'));
  }

  /**
   * Writes a composer.json requiring the packages and their composer.lock.
   *
   * @param array<string, string> $versions
   *   Locked versions keyed by package name.
   */
  private function writeLock(array $versions): void {
    Filesystem::writeJson($this->directory . '/composer.json', [
      'name' => 'example/project',
      'require' => array_map(fn () => '*', $versions),
    ]);
    $packages = [];
    foreach ($versions as $name => $version) {
      $packages[] = ['name' => $name, 'version' => $version, 'type' => 'library'];
    }
    Filesystem::writeJson($this->directory . '/composer.lock', ['packages' => $packages, 'packages-dev' => []]);
  }

}
