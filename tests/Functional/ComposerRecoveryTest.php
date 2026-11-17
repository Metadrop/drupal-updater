<?php

namespace DrupalUpdater\Tests\Functional;

use DrupalUpdater\Tests\Simulator\Package;
use DrupalUpdater\Tests\Simulator\Scenario;

/**
 * Covers the recovery of the project after a failed Composer update.
 *
 * The project is installed by the real Composer, which also runs the recovery
 * install; only the failures are scripted.
 */
final class ComposerRecoveryTest extends UpdaterTestCase {

  /**
   * Contents of composer.json and composer.lock before the run.
   *
   * @var array<string, string>
   */
  private array $before;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->scenario()
      ->package(Package::named('example/fail', '1.0.0')->withLatest('1.1.0'))
      ->package(Package::named('example/later', '1.0.0')->withLatest('1.1.0'))
      ->package(Package::named('example/tools', '1.0.0')->withLatest('1.1.0')->withBinary('composer-lock-diff'))
      ->package(Package::named('drupal/example', '1.0.0')->withLatest('1.1.0')->asDrupalModule());
    $this->useRealComposer();
    $project = $this->project();
    self::assertInstanceOf(RealComposerProject::class, $project);

    // An earlier change still uncommitted: a checkout from Git would lose it.
    $project->composer('update', 'example/tools', '--no-progress', '--no-scripts')->mustRun();
    $this->before = [
      'composer.json' => (string) file_get_contents($project->path('composer.json')),
      'composer.lock' => (string) file_get_contents($project->path('composer.lock')),
    ];
    self::assertNotSame('', $project->uncommittedChanges());
    self::assertFileExists($project->path('vendor/bin/composer-lock-diff'));

    $this->scenario()->composer()->on('update', 'example/fail')
      ->writes('composer.json', '{}')
      ->writes('composer.lock', '{}')
      ->removes('vendor/example/tools')
      ->removes('vendor/bin/composer-lock-diff')
      ->fails('curl error 28: deliberate failed update');
  }

  /**
   * Tests a failed update restores the working files and reinstalls the tools.
   */
  public function testFailedUpdateRestoresWorkingFilesAndReinstallsTools(): void {
    $run = $this->updatePackages('example/fail')->assertSuccessful();

    $this->assertWorkingStatePreserved();
    self::assertSame(['update', 'install'], $this->operations($run));
    $run->assertCalled(Scenario::COMPOSER, 'install', '--no-scripts');
    self::assertFileExists($this->project()->path('vendor/bin/composer-lock-diff'));
  }

  /**
   * Tests a failed reinstall stops before updating later packages.
   */
  public function testFailedReinstallStopsBeforeLaterPackages(): void {
    $this->scenario()->composer()->on('install')->fails('deliberate recovery install failure');

    $run = $this->updatePackages('example/fail', 'example/later')->assertFailed();

    $run->assertOutputContains('Composer recovery failed; stopping');
    self::assertSame(['update', 'install'], $this->operations($run));
    $this->assertWorkingStatePreserved();
  }

  /**
   * Tests the run stops when a tool is still missing after reinstalling.
   */
  public function testMissingToolAfterReinstallStillStops(): void {
    $this->scenario()->composer()->on('install')->passthrough()->removes('vendor/bin/composer-lock-diff');

    $run = $this->updatePackages('example/fail', 'example/later')->assertFailed();

    $run->assertOutputContains('Required updater executable missing');
    self::assertSame(['update', 'install'], $this->operations($run));
    $this->assertWorkingStatePreserved();
  }

  /**
   * Tests Drush failures keep using the existing error handler.
   */
  public function testDrushFailureKeepsItsExistingErrorHandler(): void {
    $this->scenario()->drush()->on('cr')->fails('deliberate Drupal failure');

    $run = $this->updatePackages('drupal/example')->assertSuccessful();

    $run->assertOutputContains('deliberate Drupal failure');
    self::assertStringNotContainsString('Too few arguments', $run->output());
    $run->assertCalled(Scenario::DRUSH, 'cr');
    $run->assertNotCalled(Scenario::COMPOSER, 'install');
  }

  /**
   * Runs the updater for the given packages, in order.
   */
  private function updatePackages(string ...$packages): UpdaterRun {
    return $this->runUpdater([], ['--packages=' . implode(',', $packages)]);
  }

  /**
   * Gets the update and install operations Composer received, in order.
   *
   * @return list<string>
   *   Subcommands.
   */
  private function operations(UpdaterRun $run): array {
    $operations = array_column($run->calls(Scenario::COMPOSER), 0);
    return array_values(array_filter($operations, fn (string $operation) => in_array($operation, ['update', 'install'], TRUE)));
  }

  /**
   * Asserts the Composer files and Git are as they were before the run.
   */
  private function assertWorkingStatePreserved(): void {
    foreach ($this->before as $file => $contents) {
      self::assertSame($contents, file_get_contents($this->project()->path($file)), $file);
    }
    $this->assertCommitSubjects([]);
  }

}
