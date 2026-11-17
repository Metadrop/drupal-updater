<?php

namespace DrupalUpdater\Tests\Unit\PackageIssue;

use DrupalUpdater\PackageIssue\PackageIssue;
use DrupalUpdater\PackageIssue\PackageIssueReport;
use PHPUnit\Framework\TestCase;

/**
 * Covers how package issues are classified, resolved and printed.
 */
final class PackageIssueReportTest extends TestCase {

  /**
   * Lock fingerprint of a package at 1.0.0.
   */
  private const BEFORE = ['1.0.0', 'abc', 'abc'];

  /**
   * Tests Composer failures are classified by their error.
   */
  public function testComposerFailuresAreClassifiedByTheirError(): void {
    $report = new PackageIssueReport();
    $report->record(PackageIssue::composerFailure('example/a', 'curl error 28', self::BEFORE));
    $report->record(PackageIssue::composerFailure('example/b', 'Your requirements could not be resolved to an installable set of packages.', self::BEFORE));
    $report->record(PackageIssue::unchangedLock('example/c', '1.1.0', self::BEFORE));

    self::assertSame([
      '[execution] example/a / composer-update: curl error 28',
      '[constraint] example/b / composer-update: Your requirements could not be resolved to an installable set of packages.',
      '[constraint] example/c / composer-update: No compatible change was produced by Composer; available version: 1.1.0',
    ], $report->lines());
  }

  /**
   * Tests only changed packages with a new fingerprint are resolved.
   */
  public function testOnlyChangedPackagesWithNewFingerprintAreResolved(): void {
    $report = new PackageIssueReport();
    $report->record(PackageIssue::composerFailure('example/changed', 'error', self::BEFORE));
    $report->record(PackageIssue::composerFailure('example/same', 'error', self::BEFORE));
    $report->record(PackageIssue::composerFailure('example/untouched', 'error', self::BEFORE));

    $report->resolveChanged(['example/changed', 'example/same'], [
      'example/changed' => ['1.1.0', 'def', 'def'],
      'example/same' => self::BEFORE,
      'example/untouched' => ['1.1.0', 'def', 'def'],
    ]);

    self::assertSame([
      '[resolved] example/changed / composer-update: error (Resolved by a later successful package update.)',
      '[execution] example/same / composer-update: error',
      '[execution] example/untouched / composer-update: error',
    ], $report->lines());
  }

  /**
   * Tests a Drush failure is not resolved by a new package version.
   */
  public function testDrushFailureIsNeverResolved(): void {
    $report = new PackageIssueReport();
    $report->record(PackageIssue::drushFailure('drupal/example', 'updb failed', self::BEFORE));

    $report->resolveChanged(['drupal/example'], ['drupal/example' => ['1.1.0', 'def', 'def']]);

    self::assertSame(['[execution] drupal/example / drush-update: updb failed'], $report->lines());
  }

  /**
   * Tests an empty report.
   */
  public function testNewReportIsEmpty(): void {
    $report = new PackageIssueReport();

    self::assertTrue($report->isEmpty());
    self::assertSame([], $report->lines());
  }

}
