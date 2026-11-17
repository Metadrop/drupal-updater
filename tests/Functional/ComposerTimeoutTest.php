<?php

namespace DrupalUpdater\Tests\Functional;

use DrupalUpdater\Tests\Simulator\Package;
use DrupalUpdater\Tests\Simulator\Scenario;

/**
 * Covers the configurable timeout of the Composer commands.
 */
final class ComposerTimeoutTest extends UpdaterTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->scenario()->package(Package::named('example/slow', '1.0.0')->withLatest('1.1.0'));
  }

  /**
   * Tests a Composer command is stopped at the configured timeout.
   */
  public function testComposerIsStoppedAtTheConfiguredTimeout(): void {
    $this->scenario()->composer()->on('update', 'example/slow')->sleeps(2)->bumps('example/slow');

    $run = $this->runUpdater(['composerTimeout' => 1])->assertSuccessful();

    $run->assertOutputContains('exceeded the timeout of 1 seconds');
    $this->assertLockedVersion('example/slow', '1.0.0');
    $this->assertCommitSubjects([]);
  }

  /**
   * Tests the same command finishes with a longer timeout.
   */
  public function testSameCommandFinishesWithLongerTimeout(): void {
    $this->scenario()->composer()->on('update', 'example/slow')->sleeps(2)->bumps('example/slow');

    $run = $this->runUpdater(['composerTimeout' => 5])->assertSuccessful();

    self::assertStringNotContainsString('exceeded the timeout', $run->output());
    $this->assertLockedVersion('example/slow', '1.1.0');
  }

  /**
   * Tests every Composer call gets the timeout in COMPOSER_PROCESS_TIMEOUT.
   */
  public function testComposerCallsReceiveTheTimeoutInTheirEnvironment(): void {
    $run = $this->runUpdater(['composerTimeout' => 1800])->assertSuccessful();

    self::assertNotEmpty($run->callEnvironments(Scenario::COMPOSER));
    foreach ($run->callEnvironments(Scenario::COMPOSER) as $environment) {
      self::assertSame('1800', $environment['COMPOSER_PROCESS_TIMEOUT'] ?? NULL);
    }
    foreach ($run->callEnvironments(Scenario::LOCK_DIFF) as $environment) {
      self::assertArrayNotHasKey('COMPOSER_PROCESS_TIMEOUT', $environment);
    }
  }

  /**
   * Tests the timeout defaults to 300 seconds.
   */
  public function testTimeoutDefaultsTo300Seconds(): void {
    $run = $this->runUpdater()->assertSuccessful();

    self::assertSame('300', $run->callEnvironments(Scenario::COMPOSER)[0]['COMPOSER_PROCESS_TIMEOUT'] ?? NULL);
  }

  /**
   * Tests the explicit "timeout 2" of the recursive why still applies.
   */
  public function testExplicitShellTimeoutStillApplies(): void {
    // A dependency two levels deep, so its direct package needs "why -r".
    $this->scenario()
      ->package(Package::named('example/library', '1.0.0')->requires('example/middle'))
      ->package(Package::named('example/middle', '1.0.0')->requires('example/deep')->asTransitive())
      ->package(Package::named('example/deep', '1.0.0')->withLatest('1.0.1')->asTransitive());
    $this->scenario()->composer()->on('why', 'example/deep', '-r')->sleeps(10);

    $start = microtime(TRUE);
    $run = $this->runUpdater(['composerTimeout' => 60])->assertSuccessful();

    self::assertLessThan(8, microtime(TRUE) - $start);
    $run->assertCalled(Scenario::COMPOSER, 'why', 'example/deep', '-r');
    // Without the recursive answer, the package is updated on its own.
    $run->assertCalled(Scenario::COMPOSER, 'update', 'example/deep');
  }

}
