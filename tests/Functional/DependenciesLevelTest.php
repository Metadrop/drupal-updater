<?php

namespace DrupalUpdater\Tests\Functional;

use DrupalUpdater\Tests\Simulator\Package;
use DrupalUpdater\Tests\Simulator\Scenario;

/**
 * Covers the dependencies updated along with each package.
 */
final class DependenciesLevelTest extends UpdaterTestCase {

  /**
   * Tests --with-all-dependencies is passed to Composer.
   */
  public function testWithAllDependenciesOption(): void {
    $this->outdatedLibraryWithOutdatedDependency();

    $run = $this->runUpdater([], ['--with-all-dependencies'])->assertSuccessful();

    $run->assertCalled(Scenario::COMPOSER, 'update', 'example/library', '--with-all-dependencies');
    $run->assertNotCalled(Scenario::COMPOSER, 'update', '--with-dependencies');
    $this->assertLockedVersion('example/dependency', '1.3.0');
  }

  /**
   * Tests --no-dependencies only updates the package itself.
   */
  public function testNoDependenciesOption(): void {
    $this->outdatedLibraryWithOutdatedDependency();

    $run = $this->runUpdater([], ['--no-dependencies'])->assertSuccessful();

    $run->assertNotCalled(Scenario::COMPOSER, 'update', '--with-dependencies');
    $run->assertNotCalled(Scenario::COMPOSER, 'update', '--with-all-dependencies');
    $this->assertLockedVersion('example/library', '1.1.0');
    $this->assertLockedVersion('example/dependency', '1.0.0');
    $this->assertCommitSubjects(['UPDATE - example/library: package (1.0.0 -> 1.1.0)']);
  }

  /**
   * Tests "all" in the configuration is passed to Composer as its option.
   */
  public function testAllConfigurationValue(): void {
    $this->outdatedLibraryWithOutdatedDependency();

    $this->runUpdater(['dependencies' => 'all'])->assertSuccessful()
      ->assertCalled(Scenario::COMPOSER, 'update', 'example/library', '--with-all-dependencies');
  }

  /**
   * Tests "none" in the configuration only updates the package itself.
   */
  public function testNoneConfigurationValue(): void {
    $this->outdatedLibraryWithOutdatedDependency();

    $run = $this->runUpdater(['dependencies' => 'none'])->assertSuccessful();

    $run->assertNotCalled(Scenario::COMPOSER, 'update', '--with-dependencies');
    $this->assertLockedVersion('example/dependency', '1.0.0');
  }

  /**
   * Tests the command line option overrides the configuration.
   */
  public function testOptionOverridesConfiguration(): void {
    $this->outdatedLibraryWithOutdatedDependency();

    $this->runUpdater(['dependencies' => 'none'], ['--with-dependencies'])->assertSuccessful()
      ->assertCalled(Scenario::COMPOSER, 'update', 'example/library', '--with-dependencies');
    $this->assertLockedVersion('example/dependency', '1.3.0');
  }

  /**
   * Tests combining dependencies options is rejected.
   */
  public function testConflictingOptionsAreRejected(): void {
    $this->outdatedLibraryWithOutdatedDependency();

    $run = $this->runUpdater([], ['--no-dependencies', '--with-all-dependencies'])->assertFailed();

    $run->assertOutputContains('Only one of --with-dependencies, --with-all-dependencies and --no-dependencies can be used.');
    self::assertSame([], $run->calls());
  }

  /**
   * Declares an outdated library requiring an outdated transitive package.
   */
  private function outdatedLibraryWithOutdatedDependency(): void {
    $this->scenario()
      ->package(Package::named('example/library', '1.0.0')->withLatest('1.1.0')->requires('example/dependency'))
      ->package(Package::named('example/dependency', '1.0.0')->withLatest('1.3.0')->asTransitive());
  }

}
