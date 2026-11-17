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
  }

  /**
   * Tests a failed update restores the working files and reinstalls the tools.
   */
  public function testFailedUpdateRestoresWorkingFilesAndReinstallsTools(): void {
    $this->failUpdateChangingVendor();

    $run = $this->updatePackages('example/fail')->assertSuccessful();

    $this->assertWorkingStatePreserved();
    self::assertSame(['update', 'install'], $this->operations($run));
    $run->assertCalled(Scenario::COMPOSER, 'install', '--no-scripts');
    self::assertFileExists($this->project()->path('vendor/bin/composer-lock-diff'));
  }

  /**
   * Tests a failed update that left vendor untouched does not reinstall.
   */
  public function testFailedUpdateWithoutVendorChangesDoesNotReinstall(): void {
    $this->scenario()->composer()->on('update', 'example/fail')
      ->writes('composer.lock', '{}')
      ->fails('Your requirements could not be resolved to an installable set of packages.', 2);

    $run = $this->updatePackages('example/fail', 'example/later')->assertSuccessful();

    self::assertSame(['update', 'update'], $this->operations($run));
    $this->assertLockedVersion('example/fail', '1.0.0');
    $this->assertLockedVersion('example/later', '1.1.0');
    // The commit also takes the uncommitted example/tools change.
    $commits = $this->project()->commitSubjects();
    self::assertCount(1, $commits);
    self::assertStringStartsWith('UPDATE - example/later: package (1.0.0 -> 1.1.0)', $commits[0]);
  }

  /**
   * Tests a failed reinstall stops before updating later packages.
   */
  public function testFailedReinstallStopsBeforeLaterPackages(): void {
    $this->failUpdateChangingVendor();
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
    $this->failUpdateChangingVendor();
    $this->scenario()->composer()->on('install')->passthrough()->removes('vendor/bin/composer-lock-diff');

    $run = $this->updatePackages('example/fail', 'example/later')->assertFailed();

    $run->assertOutputContains('Required updater executable missing: vendor/bin/composer-lock-diff');
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
   * Makes the update of example/fail break vendor before failing.
   *
   * Like Composer, it rewrites vendor/composer/installed.json after removing
   * a package.
   */
  private function failUpdateChangingVendor(): void {
    $this->scenario()->composer()->on('update', 'example/fail')
      ->writes('composer.json', '{}')
      ->writes('composer.lock', '{}')
      ->removes('vendor/example/tools')
      ->removes('vendor/bin/composer-lock-diff')
      ->writes('vendor/composer/installed.json', '{"packages": [], "dev": true, "dev-package-names": []}')
      ->fails('curl error 28: deliberate failed update');
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
