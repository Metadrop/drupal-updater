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
   * Tests the real Composer updates a root requirement with "all".
   */
  public function testRealComposerUpdatesRootRequirementWithAll(): void {
    $this->assertRootRequirementVersionWithRealComposer('all', '1.3.0');
  }

  /**
   * Tests the real Composer keeps a root requirement with "indirect".
   */
  public function testRealComposerKeepsRootRequirementWithIndirect(): void {
    $this->assertRootRequirementVersionWithRealComposer('indirect', '1.0.0');
  }

  /**
   * Tests the real Composer keeps every dependency with "none".
   */
  public function testRealComposerKeepsDependenciesWithNone(): void {
    $this->scenario()
      ->package(Package::named('example/library', '1.0.0')->withLatest('1.1.0')->requires('example/dependency'))
      ->package(Package::named('example/dependency', '1.0.0')->withLatest('1.3.0')->asTransitive());
    $this->useRealComposer();

    $this->runUpdater(['dependencies' => 'none'])->assertSuccessful();

    $this->assertLockedVersion('example/library', '1.1.0');
    $this->assertLockedVersion('example/dependency', '1.0.0');
  }

  /**
   * Updates a library whose dependency the root also requires.
   *
   * @param string $level
   *   Value of the dependencies configuration key.
   * @param string $version
   *   Expected version of the dependency.
   */
  private function assertRootRequirementVersionWithRealComposer(string $level, string $version): void {
    $this->scenario()
      ->package(Package::named('example/library', '1.0.0')->withLatest('1.1.0')->requires('example/dependency'))
      ->package(Package::named('example/dependency', '1.0.0')->withLatest('1.3.0'));
    $this->useRealComposer();

    $this->runUpdater(['dependencies' => $level, 'packages' => ['example/library']])->assertSuccessful();

    $this->assertLockedVersion('example/library', '1.1.0');
    $this->assertLockedVersion('example/dependency', $version);
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
