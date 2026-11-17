<?php

namespace DrupalUpdater\Tests\Functional;

use DrupalUpdater\Tests\Simulator\Package;

/**
 * Covers the list of packages with pending security advisories.
 */
final class SecurityReportTest extends UpdaterTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->scenario()
      ->package(Package::named('example/first', '1.0.0')->withLatest('1.0.1')->withAdvisory('XSS'))
      ->package(Package::named('example/second', '1.0.0')->withLatest('1.0.1')->withAdvisory('SQL injection'));
    // The updates change nothing, so both advisories stay pending.
    $this->scenario()->composer()->on('update')->succeeds();
  }

  /**
   * Tests the full report lists each vulnerable package on its own line.
   */
  public function testFullReportListsPackageNamesWithoutPadding(): void {
    $report = $this->runUpdater()->assertSuccessful()->section('Report');

    self::assertStringContainsString("/// Not Updated Securities (ALL): ///\n\nexample/first\nexample/second\n", $report);
  }

  /**
   * Tests the security-only report lists each vulnerable package too.
   */
  public function testSecurityOnlyReportListsPackageNamesWithoutPadding(): void {
    $run = $this->runUpdater([], ['--security'])->assertSuccessful();

    self::assertStringContainsString("/// Not Updated Securities: ///\n\nexample/first\nexample/second\n", $run->section('Report'));
    $run->assertCalled('composer', 'update', 'example/first');
    $run->assertCalled('composer', 'update', 'example/second');
  }

}
