<?php

namespace DrupalUpdater\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Actual CLI/Git/lock-diff with controlled Composer and Drush outcomes. */
class PackageIssueReportCliTest extends TestCase {
  private string $directory;

  protected function setUp(): void {
    $this->directory = sys_get_temp_dir() . '/updater-report-' . bin2hex(random_bytes(8));
    mkdir($this->directory);
    mkdir($this->directory . '/control');
    mkdir($this->directory . '/web');
    mkdir($this->directory . '/web/core');
    file_put_contents($this->directory . '/web/index.php', '<?php // Fixture Drupal root.');
    $names = ['example/success', 'example/fail', 'example/constraint', 'example/noop', 'example/later', 'example/unrelated', 'drupal/example'];
    $packages = array_map(function ($name) { return ['name' => $name, 'version' => '1.0.0']; }, $names);
    file_put_contents($this->directory . '/composer.json', json_encode(['name' => 'example/project', 'require' => array_fill_keys($names, '^1.0')]));
    file_put_contents($this->directory . '/composer.lock', json_encode(['packages' => $packages, 'packages-dev' => []], JSON_PRETTY_PRINT));
    file_put_contents($this->directory . '/settings.yml', "consolidateConfiguration: false\n");
    foreach (['composer' => 'composer.php', 'composer-lock-diff' => 'lock-diff.php'] as $command => $fixture) {
      file_put_contents($this->directory . '/control/' . $command, '#!' . PHP_BINARY . "\n" . file_get_contents(__DIR__ . '/fixtures/' . $fixture));
      chmod($this->directory . '/control/' . $command, 0755);
    }
    file_put_contents($this->directory . '/control/drush', '#!' . PHP_BINARY . "\n<?php file_put_contents('drush-calls', implode(' ', array_slice(\$argv, 1)).\"\\n\", FILE_APPEND); fwrite(STDERR, 'deliberate Drupal failure'); exit(1);\n");
    chmod($this->directory . '/control/drush', 0755);
    foreach ([['git', 'init', '-q'], ['git', 'config', 'user.name', 'Test'], ['git', 'config', 'user.email', 'test@example.invalid'], ['git', 'config', 'commit.gpgsign', 'false'], ['git', 'add', 'composer.json', 'composer.lock', 'web'], ['git', '-c', 'core.hooksPath=/dev/null', 'commit', '-qm', 'Baseline']] as $arguments) {
      (new Process($arguments, $this->directory))->mustRun();
    }
  }

  public function testMixedOutcomesAreCollectedAtTheEnd(): void {
    $process = $this->updater(['example/success', 'example/fail', 'example/constraint', 'example/noop']);
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    $report = $this->report($process);
    self::assertStringContainsString('[execution] example/fail / composer-update:', $report);
    self::assertStringContainsString('<error>network outage</error>', $report);
    self::assertStringContainsString('[constraint] example/constraint / composer-update:', $report);
    self::assertStringContainsString('Your requirements could not be resolved', $report);
    self::assertStringContainsString('[constraint] example/noop / composer-update:', $report);
    self::assertStringContainsString('available version: 1.1.0', $report);
    self::assertStringNotContainsString('example/success', $report);
    self::assertSame('1.1.0', $this->version('example/success'));
    $this->assertSingleAttempts(['example/success', 'example/fail', 'example/constraint', 'example/noop']);
  }

  public function testLaterSuccessfulDependencyUpdateResolvesEarlierIssues(): void {
    $process = $this->updater(['example/fail', 'example/constraint', 'example/later']);
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    $report = $this->report($process);
    self::assertStringContainsString('[resolved] example/fail / composer-update:', $report);
    self::assertStringContainsString('[resolved] example/constraint / composer-update:', $report);
    self::assertStringContainsString('network outage', $report);
    self::assertStringContainsString('Resolved by a later successful package update.', $report);
    self::assertStringNotContainsString('[execution]', $report);
    self::assertSame('1.1.0', $this->version('example/fail'));
    $this->assertSingleAttempts(['example/fail', 'example/constraint', 'example/later']);
  }

  public function testUnrelatedSuccessDoesNotResolveAnEarlierFailure(): void {
    $process = $this->updater(['example/fail', 'example/unrelated']);
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    $report = $this->report($process);
    self::assertStringContainsString('[execution] example/fail / composer-update:', $report);
    self::assertStringNotContainsString('[resolved]', $report);
    self::assertSame('1.0.0', $this->version('example/fail'));
    $this->assertSingleAttempts(['example/fail', 'example/unrelated']);
  }

  public function testStaleOutdatedCacheDoesNotReopenAResolvedConstraint(): void {
    $process = $this->updater(['example/noop', 'example/later', 'example/noop'], 'resolve-noop');
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    $report = $this->report($process);
    self::assertStringContainsString('[resolved] example/noop / composer-update:', $report);
    self::assertStringNotContainsString('[constraint]', $report);
    self::assertSame(1, substr_count($report, 'example/noop / composer-update:'));
    self::assertSame('1.1.0', $this->version('example/noop'));
    $this->assertSingleAttempts(['example/noop', 'example/later', 'example/noop']);
  }

  public function testRecordedReasonsSurviveAFatalFinalReportError(): void {
    $process = $this->updater(['example/fail'], 'fatal-report');
    self::assertNotSame(0, $process->getExitCode());
    self::assertStringContainsString('deliberate final report failure', $this->cliOutput($process));
    self::assertStringContainsString('[execution] example/fail / composer-update:', $this->report($process));
    $this->assertSingleAttempts(['example/fail']);
  }

  public function testDrushFailureIsReportedWithoutChangingItsExitPolicy(): void {
    $process = $this->updater(['drupal/example']);
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    $report = $this->report($process);
    self::assertStringContainsString('[execution] drupal/example / drush-update:', $report);
    self::assertStringContainsString('deliberate Drupal failure', $report);
    self::assertSame("@self cr\n", file_get_contents($this->directory . '/drush-calls'));
    $this->assertSingleAttempts(['drupal/example']);
  }

  public function testSuccessfulRunDoesNotPrintAnEmptyIssueReport(): void {
    $process = $this->updater(['example/success']);
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    self::assertStringNotContainsString('// PACKAGE UPDATE ISSUES //', $this->cliOutput($process));
    self::assertSame('1.1.0', $this->version('example/success'));
    $this->assertSingleAttempts(['example/success']);
  }

  private function updater(array $packages, string $mode = ''): Process {
    $source = getenv('UPDATER_TEST_SOURCE') ?: dirname(__DIR__);
    file_put_contents($this->directory . '/bootstrap.php', '<?php $loader = require ' . var_export($source . '/vendor/autoload.php', TRUE) . '; $loader->setPsr4("DrupalUpdater\\\\", ' . var_export($source . '/src', TRUE) . '); \Composer\InstalledVersions::reload(["root"=>["name"=>"example/project"],"versions"=>["drupal/core"=>["install_path"=>__DIR__."/web/core"]]]);');
    $process = new Process([PHP_BINARY, '-d', 'auto_prepend_file=' . $this->directory . '/bootstrap.php', $source . '/drupal-updater', 'update', '--config=settings.yml', '--packages=' . implode(',', $packages), '--no-interaction'], $this->directory, [
      'PATH' => $this->directory . '/control:' . getenv('PATH'), 'PWD' => $this->directory,
      'REPORT_TEST_MODE' => $mode, 'REPORT_REAL_DIFF' => dirname(__DIR__) . '/vendor/bin/composer-lock-diff',
    ]);
    $process->setTimeout(20);
    $process->run();
    return $process;
  }

  private function report(Process $process): string {
    $output = $this->cliOutput($process);
    $position = strpos($output, '// PACKAGE UPDATE ISSUES //');
    self::assertNotFalse($position, $output);
    return substr($output, $position);
  }

  private function cliOutput(Process $process): string { return $process->getOutput() . $process->getErrorOutput(); }

  private function version(string $name): string {
    foreach (json_decode(file_get_contents($this->directory . '/composer.lock'), TRUE)['packages'] as $package) {
      if ($package['name'] === $name) { return $package['version']; }
    }
    throw new \RuntimeException('Missing fixture package: ' . $name);
  }

  private function assertSingleAttempts(array $expected): void {
    $calls = array_map(function ($line) { return json_decode($line, TRUE); }, file($this->directory . '/composer-calls.jsonl', FILE_IGNORE_NEW_LINES));
    $updates = array_filter($calls, function ($call) { return $call[0] === 'update'; });
    self::assertSame($expected, array_values(array_map(function ($call) { return $call[1]; }, $updates)));
  }

  protected function tearDown(): void { $this->remove($this->directory); }

  private function remove(string $path): void {
    if (is_dir($path) && !is_link($path)) {
      foreach (new \FilesystemIterator($path) as $file) { $this->remove($file->getPathname()); }
      rmdir($path);
    }
    else { unlink($path); }
  }
}
