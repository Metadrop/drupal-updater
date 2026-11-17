<?php

namespace DrupalUpdater\Tests\Functional;

use DrupalUpdater\Tests\Simulator\Package;
use DrupalUpdater\Tests\Simulator\Scenario;

/**
 * Covers the main update workflow of the current updater.
 */
final class UpdatePackagesTest extends UpdaterTestCase {

  /**
   * Tests an outdated library is updated and committed on its own.
   */
  public function testOutdatedLibraryIsUpdatedAndCommitted(): void {
    $this->scenario()
      ->package(Package::named('example/library', '1.0.0')->withLatest('1.1.0'))
      ->package(Package::named('example/current', '2.0.0'));

    $run = $this->runUpdater()->assertSuccessful();

    $this->assertLockedVersion('example/library', '1.1.0');
    $this->assertLockedVersion('example/current', '2.0.0');
    $this->assertCommitSubjects(['UPDATE - example/library: package (1.0.0 -> 1.1.0)']);
    self::assertStringContainsString('example/library', $run->section('Report'));
    $run->assertNotCalled(Scenario::COMPOSER, 'update', 'example/current');
    $run->assertNotCalled(Scenario::DRUSH, 'updb');
  }

  /**
   * Tests a Drupal module update runs the database updates in each site.
   */
  public function testDrupalModuleUpdateRunsDatabaseUpdatesPerEnvironment(): void {
    $this->scenario()->package(Package::named('drupal/example', '1.0.0')->withLatest('1.2.0')->asDrupalModule());

    $run = $this->runUpdater(['environments' => ['@site1', '@site2']])->assertSuccessful();

    $this->assertCommitSubjects(['UPDATE - drupal/example: package (1.0.0 -> 1.2.0)']);
    foreach (['@site1', '@site2'] as $alias) {
      $run->assertCalled(Scenario::DRUSH, $alias, 'cr');
      $run->assertCalled(Scenario::DRUSH, $alias, 'updb');
    }
  }

  /**
   * Tests a failed update is reverted and the next package still updates.
   */
  public function testFailedUpdateIsRevertedAndTheRunContinues(): void {
    $this->scenario()
      ->package(Package::named('example/broken', '1.0.0')->withLatest('1.1.0'))
      ->package(Package::named('example/library', '1.0.0')->withLatest('1.1.0'));
    $this->scenario()->composer()->on('update', 'example/broken')
      ->writes('composer.json', '{}')
      ->writes('composer.lock', '{}')
      ->fails('curl error 28: deliberate network outage');

    $run = $this->runUpdater()->assertSuccessful();

    $run->assertOutputContains('curl error 28: deliberate network outage');
    $this->assertLockedVersion('example/broken', '1.0.0');
    $this->assertLockedVersion('example/library', '1.1.0');
    $this->assertCommitSubjects(['UPDATE - example/library: package (1.0.0 -> 1.1.0)']);
    self::assertSame('', $this->project()->uncommittedChanges());
    self::assertStringContainsString('example/broken', (string) file_get_contents($this->project()->path('composer.json')));
  }

  /**
   * Tests an outdated transitive package is updated through its requirer.
   */
  public function testTransitivePackageIsUpdatedThroughItsDirectPackage(): void {
    $this->scenario()
      ->package(Package::named('example/library', '1.0.0')->requires('example/dependency'))
      ->package(Package::named('example/dependency', '1.0.0')->withLatest('1.3.0')->asTransitive());

    $run = $this->runUpdater()->assertSuccessful();

    $run->assertCalled(Scenario::COMPOSER, 'update', 'example/library', '--with-dependencies');
    $this->assertLockedVersion('example/dependency', '1.3.0');
    $this->assertCommitSubjects(['UPDATE - example/library: dependencies']);
  }

  /**
   * Tests the security mode only updates the packages with advisories.
   */
  public function testSecurityModeOnlyUpdatesPackagesWithAdvisories(): void {
    $this->scenario()
      ->package(Package::named('example/vulnerable', '1.0.0')->withLatest('1.0.1')->withAdvisory('Remote code execution', 'CVE-2026-0001'))
      ->package(Package::named('example/library', '1.0.0')->withLatest('1.1.0'));

    $run = $this->runUpdater([], ['--security'])->assertSuccessful();

    $this->assertLockedVersion('example/vulnerable', '1.0.1');
    $this->assertLockedVersion('example/library', '1.0.0');
    $this->assertCommitSubjects(['UPDATE - example/vulnerable: package (1.0.0 -> 1.0.1)']);
    self::assertMatchesRegularExpression('#Not Updated Securities: ///\s*///#', $run->section('Report'));
  }

  /**
   * Tests the same workflow with the real Composer resolving the update.
   */
  public function testRealComposerUpdatesThePackageAndItsDependencies(): void {
    $this->scenario()
      ->package(Package::named('example/library', '1.0.0')->withLatest('1.1.0')->requires('example/dependency'))
      ->package(Package::named('example/dependency', '1.0.0')->withLatest('1.3.0')->asTransitive());
    $this->useRealComposer();

    $this->runUpdater()->assertSuccessful();

    $this->assertLockedVersion('example/library', '1.1.0');
    $this->assertLockedVersion('example/dependency', '1.3.0');
    $this->assertCommitSubjects(['UPDATE - example/library: package (1.0.0 -> 1.1.0), dependencies']);
  }

  /**
   * Tests the configuration is consolidated before updating.
   */
  public function testConfigurationIsConsolidatedBeforeUpdating(): void {
    $this->scenario()->package(Package::named('example/library', '1.0.0')->withLatest('1.1.0'));
    $this->scenario()->drush()->on('cex')
      ->writes('config/sync/system.site.yml', "name: Example\n");

    $this->runUpdater(['consolidateConfiguration' => TRUE])->assertSuccessful();

    $this->assertCommitSubjects([
      'CONFIG - Consolidate current configuration on @self',
      'UPDATE - example/library: package (1.0.0 -> 1.1.0)',
    ]);
  }

}
