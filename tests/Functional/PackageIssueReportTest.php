<?php

namespace DrupalUpdater\Tests\Functional;

use DrupalUpdater\Tests\Simulator\Package;
use DrupalUpdater\Tests\Simulator\Scenario;

/**
 * Covers the package update issues printed at the end of each run.
 */
final class PackageIssueReportTest extends UpdaterTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['success', 'fail', 'constraint', 'noop', 'later', 'unrelated'] as $name) {
      $this->scenario()->package(Package::named('example/' . $name, '1.0.0')->withLatest('1.1.0'));
    }
    $this->scenario()->package(Package::named('drupal/example', '1.0.0')->withLatest('1.1.0')->asDrupalModule());
    $composer = $this->scenario()->composer();
    $composer->on('update', 'example/fail')->fails('curl error 28: deliberate <error>network outage</error>');
    $composer->on('update', 'example/constraint')->fails('Your requirements could not be resolved to an installable set of packages.', 2);
    $composer->on('update', 'example/noop')->succeeds();
  }

  /**
   * Tests failures of several kinds are collected in the final report.
   */
  public function testMixedOutcomesAreCollectedAtTheEnd(): void {
    $run = $this->updatePackages('example/success', 'example/fail', 'example/constraint', 'example/noop');

    $issues = $run->assertSuccessful()->section('Package update issues');
    self::assertStringContainsString('[execution] example/fail / composer-update:', $issues);
    self::assertStringContainsString('<error>network outage</error>', $issues);
    self::assertStringContainsString('[constraint] example/constraint / composer-update:', $issues);
    self::assertStringContainsString('Your requirements could not be resolved', $issues);
    self::assertStringContainsString('[constraint] example/noop / composer-update:', $issues);
    self::assertStringContainsString('available version: 1.1.0', $issues);
    self::assertStringNotContainsString('example/success', $issues);
    $this->assertLockedVersion('example/success', '1.1.0');
    $this->assertSingleAttempts($run, ['example/success', 'example/fail', 'example/constraint', 'example/noop']);
  }

  /**
   * Tests a later update that changes the failed packages resolves them.
   */
  public function testLaterSuccessfulDependencyUpdateResolvesEarlierIssues(): void {
    $this->scenario()->composer()->on('update', 'example/later')->bumps('example/later', 'example/fail', 'example/constraint');

    $run = $this->updatePackages('example/fail', 'example/constraint', 'example/later');

    $issues = $run->assertSuccessful()->section('Package update issues');
    self::assertStringContainsString('[resolved] example/fail / composer-update:', $issues);
    self::assertStringContainsString('[resolved] example/constraint / composer-update:', $issues);
    self::assertStringContainsString('network outage', $issues);
    self::assertStringContainsString('Resolved by a later successful package update.', $issues);
    self::assertStringNotContainsString('[execution]', $issues);
    $this->assertLockedVersion('example/fail', '1.1.0');
    $this->assertSingleAttempts($run, ['example/fail', 'example/constraint', 'example/later']);
  }

  /**
   * Tests an unrelated successful update does not resolve an earlier failure.
   */
  public function testUnrelatedSuccessDoesNotResolveAnEarlierFailure(): void {
    $run = $this->updatePackages('example/fail', 'example/unrelated');

    $issues = $run->assertSuccessful()->section('Package update issues');
    self::assertStringContainsString('[execution] example/fail / composer-update:', $issues);
    self::assertStringNotContainsString('[resolved]', $issues);
    $this->assertLockedVersion('example/fail', '1.0.0');
    $this->assertSingleAttempts($run, ['example/fail', 'example/unrelated']);
  }

  /**
   * Tests a stale outdated list does not reopen a resolved constraint.
   */
  public function testStaleOutdatedListDoesNotReopenResolvedConstraint(): void {
    $this->scenario()->composer()->on('update', 'example/later')->bumps('example/later', 'example/noop');

    $run = $this->updatePackages('example/noop', 'example/later', 'example/noop');

    $issues = $run->assertSuccessful()->section('Package update issues');
    self::assertStringContainsString('[resolved] example/noop / composer-update:', $issues);
    self::assertStringNotContainsString('[constraint]', $issues);
    self::assertSame(1, substr_count($issues, 'example/noop / composer-update:'));
    $this->assertLockedVersion('example/noop', '1.1.0');
    $this->assertSingleAttempts($run, ['example/noop', 'example/later', 'example/noop']);
  }

  /**
   * Tests recorded issues are printed even when the final report fails.
   */
  public function testRecordedReasonsSurviveFatalFinalReportError(): void {
    $this->scenario()->lockDiff()->on('--from')->fails('deliberate final report failure');

    $run = $this->updatePackages('example/fail');

    $run->assertFailed()->assertOutputContains('deliberate final report failure');
    self::assertStringContainsString('[execution] example/fail / composer-update:', $run->section('Package update issues'));
    $this->assertSingleAttempts($run, ['example/fail']);
  }

  /**
   * Tests Drush failures are reported without changing the exit code.
   */
  public function testDrushFailureIsReportedWithoutChangingItsExitPolicy(): void {
    $this->scenario()->drush()->on('cr')->fails('deliberate Drupal failure');

    $run = $this->updatePackages('drupal/example');

    $issues = $run->assertSuccessful()->section('Package update issues');
    self::assertStringContainsString('[execution] drupal/example / drush-update:', $issues);
    self::assertStringContainsString('deliberate Drupal failure', $issues);
    self::assertSame([['@self', 'cr']], $run->calls(Scenario::DRUSH));
    $this->assertSingleAttempts($run, ['drupal/example']);
  }

  /**
   * Tests a successful run prints no issue report.
   */
  public function testSuccessfulRunDoesNotPrintAnEmptyIssueReport(): void {
    $run = $this->updatePackages('example/success');

    $run->assertSuccessful();
    self::assertStringNotContainsString('// PACKAGE UPDATE ISSUES //', $run->output());
    $this->assertLockedVersion('example/success', '1.1.0');
    $this->assertSingleAttempts($run, ['example/success']);
  }

  /**
   * Runs the updater for the given packages, in order.
   */
  private function updatePackages(string ...$packages): UpdaterRun {
    return $this->runUpdater([], ['--packages=' . implode(',', $packages)]);
  }

  /**
   * Asserts each package was updated once, in the given order.
   *
   * @param \DrupalUpdater\Tests\Functional\UpdaterRun $run
   *   Updater run.
   * @param list<string> $packages
   *   Expected packages of the "composer update" calls.
   */
  private function assertSingleAttempts(UpdaterRun $run, array $packages): void {
    $updates = array_filter($run->calls(Scenario::COMPOSER), fn (array $arguments) => $arguments[0] === 'update');
    self::assertSame($packages, array_values(array_map(fn (array $arguments) => $arguments[1], $updates)));
  }

}
