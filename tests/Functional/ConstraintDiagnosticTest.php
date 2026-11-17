<?php

namespace DrupalUpdater\Tests\Functional;

use DrupalUpdater\Tests\Simulator\Package;
use DrupalUpdater\Tests\Simulator\Scenario;

/**
 * Covers the dry run suggested when constraints block an available update.
 */
final class ConstraintDiagnosticTest extends UpdaterTestCase {

  /**
   * Tests a stable version gets a dry run suggestion.
   */
  public function testStableVersionHasAnActionableDryRunSuggestion(): void {
    $this->assertSuggestion('1.1.0');
  }

  /**
   * Tests a prerelease version is kept as is in the suggestion.
   */
  public function testPrereleaseVersionIsPreservedInTheSuggestion(): void {
    $this->assertSuggestion('2.0.0-beta1');
  }

  /**
   * Tests a development version is kept as is in the suggestion.
   */
  public function testDevelopmentVersionIsPreservedInTheSuggestion(): void {
    $this->assertSuggestion('dev-main');
  }

  /**
   * Tests an up to date package gets no suggestion.
   */
  public function testCurrentPackageDoesNotHaveSuggestion(): void {
    $this->scenario()->package(Package::named('example/blocked', '1.0.0'));

    $run = $this->runUpdater(['packages' => ['example/blocked']])->assertSuccessful();

    self::assertStringNotContainsString('For more information, run:', $run->output());
    $run->assertNotCalled(Scenario::COMPOSER, 'require');
  }

  /**
   * Tests a package that is not in the project gets no suggestion.
   */
  public function testMissingPackageDoesNotHaveSuggestion(): void {
    $this->scenario()->package(Package::named('example/current', '1.0.0'));

    $run = $this->runUpdater(['packages' => ['example/missing']])->assertSuccessful();

    self::assertStringNotContainsString('For more information, run:', $run->output());
    $run->assertNotCalled(Scenario::COMPOSER, 'require');
  }

  /**
   * Tests a successful update gets no suggestion.
   */
  public function testSuccessfulUpdateDoesNotHaveSuggestion(): void {
    $this->scenario()->package(Package::named('example/blocked', '1.0.0')->withLatest('1.1.0'));

    $run = $this->runUpdater(['packages' => ['example/blocked']])->assertSuccessful();

    self::assertStringNotContainsString('For more information, run:', $run->output());
    $this->assertLockedVersion('example/blocked', '1.1.0');
    $run->assertNotCalled(Scenario::COMPOSER, 'require');
  }

  /**
   * Asserts a dry run is suggested, not run, when the update changes nothing.
   */
  private function assertSuggestion(string $latest): void {
    $this->scenario()->package(Package::named('example/blocked', '1.0.0')->withLatest($latest));
    // Constraints keep the lock file as it is.
    $this->scenario()->composer()->on('update', 'example/blocked')->succeeds();
    $manifest = (string) file_get_contents($this->project()->path('composer.json'));

    $run = $this->runUpdater(['packages' => ['example/blocked']])->assertSuccessful();

    $run->assertOutputContains("For more information, run: composer require 'example/blocked:" . $latest . "' -W --dry-run");
    self::assertSame($manifest, file_get_contents($this->project()->path('composer.json')));
    $this->assertLockedVersion('example/blocked', '1.0.0');
    $run->assertNotCalled(Scenario::COMPOSER, 'require');
  }

}
