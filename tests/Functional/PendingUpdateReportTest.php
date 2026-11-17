<?php

namespace DrupalUpdater\Tests\Functional;

use DrupalUpdater\Tests\Simulator\Package;

/**
 * Covers the pending updates part of the final report.
 */
final class PendingUpdateReportTest extends UpdaterTestCase {

  /**
   * Tests every empty category of the report prints a message.
   */
  public function testEmptyReportHasMessagesForEveryCategory(): void {
    $this->scenario()->package(Package::named('example/current', '1.0.0'));

    $report = $this->runUpdater()->assertSuccessful()->section('Report');

    self::assertStringContainsString("/// Not Updated Packages (Direct): ///\n\nNo pending direct package updates found.", $report);
    self::assertStringContainsString("/// Not Updated Packages (ALL): ///\n\nNo pending package updates found.", $report);
    self::assertStringContainsString("/// Not Updated Securities (ALL): ///\n\nNo pending security updates found.", $report);
  }

  /**
   * Tests whitespace-only results count as empty.
   */
  public function testWhitespaceOnlyResultsAreReportedAsEmpty(): void {
    $this->scenario()->package(Package::named('example/current', '1.0.0'));
    $this->scenario()->composer()->on('show', '--outdated')->outputs(" \t\n");

    $report = $this->runUpdater()->assertSuccessful()->section('Report');

    self::assertStringContainsString("/// Not Updated Packages (Direct): ///\n\nNo pending direct package updates found.", $report);
    self::assertStringContainsString("/// Not Updated Packages (ALL): ///\n\nNo pending package updates found.", $report);
  }

  /**
   * Tests an empty security-only report prints a message.
   */
  public function testEmptySecurityOnlyReportHasMessage(): void {
    $this->scenario()->package(Package::named('example/current', '1.0.0'));

    $report = $this->runUpdater([], ['--security'])->assertSuccessful()->section('Report');

    self::assertStringContainsString("/// Not Updated Securities: ///\n\nNo pending security updates found.", $report);
    self::assertStringNotContainsString('Not Updated Packages', $report);
  }

  /**
   * Tests a report with pending updates keeps them.
   */
  public function testPopulatedReportRetainsResults(): void {
    $this->declarePackagesThatCannotBeUpdated();

    $report = $this->runUpdater()->assertSuccessful()->section('Report');

    self::assertStringContainsString("/// Not Updated Packages (Direct): ///\n\nexample/direct     1.0.0 ! 1.1.0", $report);
    self::assertStringContainsString('example/transitive 1.0.0 ! 1.1.0', $report);
    self::assertStringContainsString("/// Not Updated Securities (ALL): ///\n\nexample/vulnerable", $report);
    self::assertStringNotContainsString('No pending', $report);
  }

  /**
   * Tests a security-only report with pending advisories keeps them.
   */
  public function testPopulatedSecurityOnlyReportRetainsResults(): void {
    $this->declarePackagesThatCannotBeUpdated();

    $report = $this->runUpdater([], ['--security'])->assertSuccessful()->section('Report');

    self::assertStringContainsString("/// Not Updated Securities: ///\n\nexample/vulnerable", $report);
    self::assertStringNotContainsString('No pending', $report);
    self::assertStringNotContainsString('Not Updated Packages', $report);
  }

  /**
   * Tests a failed report command is not presented as an empty result.
   */
  public function testFailedReportCommandIsNotPresentedAsEmpty(): void {
    $this->scenario()->package(Package::named('example/current', '1.0.0'));
    $this->scenario()->composer()->on('show', '--outdated', '--direct')->fails('REPORT_FAILED', 2);

    $run = $this->runUpdater()->assertFailed()->assertOutputContains('REPORT_FAILED');

    self::assertStringNotContainsString('No pending', $run->section('Report'));
  }

  /**
   * Declares outdated and vulnerable packages whose updates change nothing.
   */
  private function declarePackagesThatCannotBeUpdated(): void {
    $this->scenario()
      ->package(Package::named('example/direct', '1.0.0')->withLatest('1.1.0')->requires('example/transitive'))
      ->package(Package::named('example/transitive', '1.0.0')->withLatest('1.1.0')->asTransitive())
      ->package(Package::named('example/vulnerable', '1.0.0')->withLatest('1.0.1')->withAdvisory('Remote code execution'));
    $this->scenario()->composer()->on('update')->succeeds();
  }

}
