<?php

namespace DrupalUpdater\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Runs the installed CLI with real Git/Composer and a controlled failed update.
 */
class ComposerRecoveryCliTest extends TestCase {

  /**
   * Fixture project directory.
   *
   * @var string
   */
  private string $directory;

  /**
   * Path to the real Composer executable.
   *
   * @var string
   */
  private string $composer;

  /**
   * Composer files and Git state before the update.
   *
   * @var array<string, string>
   */
  private array $before;

  /**
   * Builds a fixture project with the updater installed through Composer.
   */
  protected function setUp(): void {
    $this->directory = sys_get_temp_dir() . '/updater-recovery-' . bin2hex(random_bytes(8));
    mkdir($this->directory);
    $this->composer = trim($this->process(['sh', '-c', 'command -v composer'])->getOutput());
    $source = getenv('UPDATER_TEST_SOURCE') ?: dirname(__DIR__);
    foreach (['fixtures/updater', 'fixtures/diff', 'fixtures/library-1', 'fixtures/library-2', 'control', 'web/core'] as $path) {
      mkdir($this->directory . '/' . $path, 0777, TRUE);
    }
    // The fixture package contains the candidate's actual source and
    // entrypoint. Test-runner dependencies supply Symfony; no Drupal database
    // is installed.
    $this->copy($source . '/src', $this->directory . '/fixtures/updater/src');
    copy($source . '/drupal-updater', $this->directory . '/fixtures/updater/drupal-updater');
    $this->json('fixtures/updater/composer.json', [
      'name' => 'metadrop/drupal-updater',
      'version' => '1.0.0',
      'require' => ['davidrjonas/composer-lock-diff' => '1.7.1'],
      'autoload' => ['psr-4' => ['DrupalUpdater\\' => 'src/']],
      'bin' => ['drupal-updater'],
    ]);
    $this->json('fixtures/diff/composer.json', [
      'name' => 'davidrjonas/composer-lock-diff',
      'version' => '1.7.1',
      'bin' => ['composer-lock-diff'],
    ]);
    file_put_contents($this->directory . '/fixtures/diff/composer-lock-diff', "#!/usr/bin/env php\n<?php if (in_array('--probe', \$argv)) { echo 'tool available'; } elseif (in_array('--json', \$argv)) { echo '{\"changes\":[],\"changes-dev\":[]}'; }\n");
    foreach (['library-1' => '1.0.0', 'library-2' => '1.1.0'] as $path => $version) {
      $this->json('fixtures/' . $path . '/composer.json', ['name' => 'example/library', 'version' => $version]);
    }
    $manifest = [
      'name' => 'example/project',
      'require' => ['example/library' => '1.0.0'],
      'repositories' => [
        ['type' => 'path', 'url' => 'fixtures/*', 'options' => ['symlink' => FALSE]],
        ['packagist.org' => FALSE],
      ],
      'scripts' => ['post-install-cmd' => '@php -r "file_put_contents(\'project-script-ran\', \'unexpected\');"'],
    ];
    $this->json('composer.json', $manifest);
    $this->installFixture();
    file_put_contents($this->directory . '/earlier-success.txt', 'retain earlier work');
    foreach ([['git', 'init', '-q'], ['git', 'config', 'user.name', 'Test'], ['git', 'config', 'user.email', 'test@example.invalid'], ['git', 'config', 'commit.gpgsign', 'false'], ['git', 'add', 'composer.json', 'composer.lock', 'earlier-success.txt'], ['git', '-c', 'core.hooksPath=/dev/null', 'commit', '-qm', 'Before temporary updater installation']] as $command) {
      $this->process($command);
    }
    // Neither temporary tooling nor the later successful dependency resolution
    // is in Git: checkout from Git would lose both pre-attempt changes.
    $manifest['require']['metadrop/drupal-updater'] = '1.0.0';
    $manifest['require']['example/library'] = '^1.0';
    $this->json('composer.json', $manifest);
    $this->installFixture();
    $this->before = [
      'json' => file_get_contents($this->directory . '/composer.json'),
      'lock' => file_get_contents($this->directory . '/composer.lock'),
      'head' => $this->process(['git', 'rev-parse', 'HEAD'])->getOutput(),
      'index' => $this->process(['git', 'ls-files', '--stage'])->getOutput(),
    ];
    self::assertStringContainsString('1.1.0', $this->before['lock']);
    self::assertStringNotContainsString('metadrop/drupal-updater', $this->process(['git', 'show', 'HEAD:composer.json'])->getOutput());
    file_put_contents($this->directory . '/settings.yml', "consolidateConfiguration: false\ncomposerAdditionalPasses: 0\ncomposerAttempts: 1\n");
    file_put_contents($this->directory . '/control/composer', '#!' . PHP_BINARY . "\n" . file_get_contents(__DIR__ . '/fixtures/composer-failure.php'));
    chmod($this->directory . '/control/composer', 0755);
    file_put_contents($this->directory . '/web/index.php', '<?php // Fixture Drupal root.');
    file_put_contents($this->directory . '/control/drush', '#!' . PHP_BINARY . "\n<?php if (strpos(getenv('RECOVERY_TEST_MODE'), 'discovery-') === 0) { echo '[]'; exit(0); } file_put_contents('drush-ran', \"yes\\n\", FILE_APPEND); fwrite(STDERR, getenv('RECOVERY_TEST_MODE') === 'drush-transient' ? 'curl error 28: database connection timed out' : 'deliberate Drupal failure'); exit(1);\n");
    chmod($this->directory . '/control/drush', 0755);
    file_put_contents($this->directory . '/bootstrap.php', '<?php $loader = require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', TRUE) . '; $loader->setPsr4("DrupalUpdater\\\\", __DIR__."/vendor/metadrop/drupal-updater/src"); \Composer\InstalledVersions::reload(["root"=>["name"=>"example/project"],"versions"=>["drupal/core"=>["install_path"=>__DIR__."/web/core"]]]);');
  }

  /**
   * Tests a failed update restores the files and reinstalls the updater tools.
   */
  public function testFailedMutationRestoresFilesAndReinstallsTools(): void {
    $process = $this->updater('recover');
    $this->assertWorkingStatePreserved();
    self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    self::assertSame(['update', 'install'], $this->operations());
    self::assertFileDoesNotExist($this->directory . '/project-script-ran');
    self::assertSame(0, $this->process($this->cliArguments(['--help']))->getExitCode());
    self::assertSame('tool available', $this->process([PHP_BINARY, 'vendor/bin/composer-lock-diff', '--probe'])->getOutput());
  }

  /**
   * Tests a failed reinstall stops before updating later packages.
   */
  public function testFailedReinstallStopsBeforeLaterPackages(): void {
    $process = $this->updater('install-fails', 'example/fail,example/later');
    self::assertFileDoesNotExist($this->directory . '/later-package-ran');
    self::assertNotSame(0, $process->getExitCode());
    self::assertStringContainsString('Composer recovery failed; stopping', $process->getOutput() . $process->getErrorOutput());
    self::assertSame(['update', 'install'], $this->operations());
    $this->assertWorkingStatePreserved();
  }

  /**
   * Tests the run stops when a tool is still missing after reinstalling.
   */
  public function testSuccessfulInstallWithMissingToolStillStops(): void {
    $process = $this->updater('missing-tool', 'example/fail,example/later');
    self::assertFileDoesNotExist($this->directory . '/later-package-ran');
    self::assertNotSame(0, $process->getExitCode());
    self::assertStringContainsString('Required updater executable missing', $process->getOutput() . $process->getErrorOutput());
    self::assertSame(['update', 'install'], $this->operations());
    $this->assertWorkingStatePreserved();
  }

  /**
   * Tests Drush failures keep using the package update error handler.
   */
  public function testDrushFailureStillUsesItsExistingSingleArgumentHandler(): void {
    $process = $this->updater('drush-fails', 'drupal/example');
    $output = $process->getOutput() . $process->getErrorOutput();
    self::assertFileExists($this->directory . '/drush-ran');
    self::assertSame(0, $process->getExitCode(), $output);
    self::assertStringContainsString('deliberate Drupal failure', $output);
    self::assertStringNotContainsString('Too few arguments', $output);
    self::assertNotContains('install', $this->operations());
  }

  public function testRetryTransientUpdateRecoversBeforeNextAttempt(): void {
    $this->retryConfiguration(3);
    $process = $this->updater('transient-once');
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    self::assertSame(['update', 'install', 'update'], $this->mutations());
    self::assertSame($this->before['json'], file_get_contents($this->directory . '/composer.json'));
    self::assertSame($this->before['lock'], file_get_contents($this->directory . '/composer.lock'));
    self::assertStringContainsString('[resolved] example/fail / composer-update (2 attempts)', $this->cliOutput($process));
    self::assertStringContainsString('Composer command succeeded after retry.', $this->cliOutput($process));
    self::assertSame(0, $this->process($this->cliArguments(['--help']))->getExitCode());
  }

  public function testRetryExhaustionKeepsRecoveredStateAndFinalReason(): void {
    $this->retryConfiguration(3);
    $process = $this->updater('recover');
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    self::assertSame(['update', 'install', 'update', 'install', 'update', 'install'], $this->mutations());
    $this->assertWorkingStatePreserved();
    self::assertStringContainsString('[execution] example/fail / composer-update (3 attempts)', $this->cliOutput($process));
    self::assertStringNotContainsString('Composer attempt 3/3 failed; retrying', $this->cliOutput($process));
  }

  public function testRetryDeterministicFailuresAreNotRepeated(): void {
    $this->retryConfiguration(3);
    foreach (['conflict', 'auth', 'unknown'] as $mode) {
      if (is_file($this->directory . '/composer-calls.jsonl')) { unlink($this->directory . '/composer-calls.jsonl'); }
      $process = $this->updater($mode);
      self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
      self::assertSame(['update', 'install'], $this->mutations());
      self::assertStringNotContainsString('retrying in', $this->cliOutput($process));
    }
    $this->assertWorkingStatePreserved();
  }

  public function testRetryDisabledPerformsOneAttempt(): void {
    $this->retryConfiguration(1);
    $process = $this->updater('transient-once');
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    self::assertSame(['update', 'install'], $this->mutations());
    $this->assertWorkingStatePreserved();
  }

  public function testRetryRecoveryFailureStopsWithoutFurtherAttempts(): void {
    $this->retryConfiguration(3);
    $process = $this->updater('install-fails');
    self::assertNotSame(0, $process->getExitCode());
    self::assertSame(['update', 'install'], $this->mutations());
    self::assertStringContainsString('Composer recovery failed; stopping', $this->cliOutput($process));
    self::assertStringNotContainsString('retrying in', $this->cliOutput($process));
  }

  public function testRetryDrushFailureIsNotRepeated(): void {
    $this->retryConfiguration(3);
    $process = $this->updater('drush-fails', 'drupal/example');
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    self::assertSame("yes\n", file_get_contents($this->directory . '/drush-ran'));
    self::assertSame(['update'], $this->mutations());
  }

  public function testRetryDiscoveryTransientFailureSucceeds(): void {
    $this->retryConfiguration(3);
    $process = $this->updater('discovery-once', NULL);
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    self::assertSame('2', file_get_contents($this->directory . '/discovery-attempts'));
    self::assertSame([], $this->mutations());
    self::assertStringContainsString('[resolved] run / composer show --locked --outdated --name-only', $this->cliOutput($process));
  }

  public function testRetryDiscoveryExhaustionStopsBeforeAnyUpdate(): void {
    $this->retryConfiguration(3);
    $process = $this->updater('discovery-always', NULL);
    self::assertNotSame(0, $process->getExitCode());
    self::assertSame('3', file_get_contents($this->directory . '/discovery-attempts'));
    self::assertSame([], $this->mutations());
    self::assertStringContainsString('HTTP/2 503 temporary failure', $this->cliOutput($process));
    $this->assertWorkingStatePreserved();
  }

  public function testRetryBackoffWaitsOneThenTwoSeconds(): void {
    $this->retryConfiguration(3, 1);
    $process = $this->updater('transient-twice');
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    self::assertStringContainsString('retrying in 1 seconds', $this->cliOutput($process));
    self::assertStringContainsString('retrying in 2 seconds', $this->cliOutput($process));
    $events = array_map(function ($line) { return json_decode($line, TRUE); }, file($this->directory . '/retry-events.jsonl', FILE_IGNORE_NEW_LINES));
    $updates = array_values(array_filter($events, function ($event) { return $event['operation'] === 'update'; }));
    self::assertCount(3, $updates);
    self::assertGreaterThanOrEqual(1.0, $updates[1]['time'] - $updates[0]['time']);
    self::assertGreaterThanOrEqual(2.0, $updates[2]['time'] - $updates[1]['time']);
  }

  public function testRetryProcessTimeoutCanRecoverOnLaterAttempt(): void {
    $this->retryConfiguration(2);
    file_put_contents($this->directory . '/settings.yml', "composerTimeout: 1\n", FILE_APPEND);
    $started = microtime(TRUE);
    $process = $this->updater('timeout-once');
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    self::assertSame(['update', 'install', 'update'], $this->mutations());
    self::assertStringContainsString('exceeded the timeout of 1 seconds', $this->cliOutput($process));
    self::assertStringContainsString('Composer command succeeded after retry.', $this->cliOutput($process));
    // Wait beyond the first child's delayed mutation: a surviving shell child
    // must not corrupt the state after recovery/the successful next attempt.
    $remaining = 2.2 - (microtime(TRUE) - $started);
    if ($remaining > 0) { usleep((int) ($remaining * 1000000)); }
    self::assertSame($this->before['json'], file_get_contents($this->directory . '/composer.json'));
    self::assertSame($this->before['lock'], file_get_contents($this->directory . '/composer.lock'));
  }

  public function testAdditionalPassRecoversPendingTransientPackage(): void {
    $this->retryConfiguration(1, 0, 1);
    $process = $this->updater('transient-once');
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    self::assertSame(['update', 'install', 'update'], $this->mutations());
    self::assertStringContainsString('RETRY PASS 1/1', $this->cliOutput($process));
    self::assertStringContainsString('[resolved] example/fail / composer-update (2 attempts)', $this->cliOutput($process));
    self::assertSame(1, substr_count($this->cliOutput($process), '// SETUP //'));
    self::assertSame($this->before['json'], file_get_contents($this->directory . '/composer.json'));
    self::assertSame($this->before['lock'], file_get_contents($this->directory . '/composer.lock'));
    self::assertSame(0, $this->process($this->cliArguments(['--help']))->getExitCode());
  }

  public function testAdditionalPassAndCommandBoundsAreBothHonored(): void {
    $this->retryConfiguration(2, 0, 2);
    $process = $this->updater('recover');
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    self::assertSame(array_merge(...array_fill(0, 6, ['update', 'install'])), $this->mutations());
    self::assertStringContainsString('RETRY PASS 1/2', $this->cliOutput($process));
    self::assertStringContainsString('RETRY PASS 2/2', $this->cliOutput($process));
    self::assertStringNotContainsString('RETRY PASS 3/', $this->cliOutput($process));
    self::assertStringContainsString('[execution] example/fail / composer-update (6 attempts)', $this->cliOutput($process));
    self::assertSame(1, substr_count($this->cliOutput($process), '[execution] example/fail / composer-update'));
    $this->assertWorkingStatePreserved();
  }

  public function testAdditionalPassSkipsDeterministicErrors(): void {
    $this->retryConfiguration(1, 0, 1);
    foreach (['conflict', 'auth', 'unknown'] as $mode) {
      if (is_file($this->directory . '/composer-calls.jsonl')) { unlink($this->directory . '/composer-calls.jsonl'); }
      $process = $this->updater($mode);
      self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
      self::assertSame(['update', 'install'], $this->mutations());
      self::assertStringNotContainsString('RETRY PASS', $this->cliOutput($process));
    }
    $this->assertWorkingStatePreserved();
  }

  public function testAdditionalPassDoesNotRepeatSuccessfulPackages(): void {
    $this->retryConfiguration(1, 0, 1);
    $process = $this->updater('transient-once', 'example/success,example/fail');
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    $calls = array_map(function ($line) { return json_decode($line, TRUE); }, file($this->directory . '/composer-calls.jsonl', FILE_IGNORE_NEW_LINES));
    $updates = array_values(array_filter($calls, function ($call) { return $call[0] === 'update'; }));
    self::assertSame(['example/success', 'example/fail', 'example/fail'], array_column($updates, 1));
    self::assertSame('2', trim($this->process(['git', 'rev-list', '--count', 'HEAD'])->getOutput()));
    self::assertSame('retain earlier work', file_get_contents($this->directory . '/earlier-success.txt'));
    self::assertStringContainsString('[resolved] example/fail', $this->cliOutput($process));
  }

  public function testAdditionalPassStopsOnFatalRecovery(): void {
    $this->retryConfiguration(1, 0, 1);
    $process = $this->updater('install-fails');
    self::assertNotSame(0, $process->getExitCode());
    self::assertSame(['update', 'install'], $this->mutations());
    self::assertStringNotContainsString('RETRY PASS', $this->cliOutput($process));
  }

  public function testAdditionalPassDoesNotRepeatDrushFailures(): void {
    $this->retryConfiguration(1, 0, 1);
    $process = $this->updater('drush-transient', 'example/fail,drupal/example');
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    self::assertSame("yes\n", file_get_contents($this->directory . '/drush-ran'));
    self::assertSame(['update', 'install', 'update'], $this->mutations());
    self::assertStringNotContainsString('RETRY PASS', $this->cliOutput($process));
  }

  private function retryConfiguration(int $attempts, int $delay = 0, int $additionalPasses = 0): void {
    file_put_contents($this->directory . '/settings.yml', "consolidateConfiguration: false\ncomposerAdditionalPasses: $additionalPasses\ncomposerAttempts: $attempts\ncomposerRetryDelay: $delay\n");
  }

  private function mutations(): array {
    return array_values(array_filter($this->operations(), function ($operation) { return in_array($operation, ['update', 'install']); }));
  }

  private function cliOutput(Process $process): string { return $process->getOutput() . $process->getErrorOutput(); }

  private function updater(string $mode, ?string $packages = 'example/fail'): Process {
    $arguments = ['update', '--config=settings.yml', '--no-interaction'];
    if ($packages !== NULL) { $arguments[] = '--packages=' . $packages; }
    $process = new Process($this->cliArguments($arguments), $this->directory, [
      'PATH' => $this->directory . '/control:' . $this->directory . '/vendor/bin:' . getenv('PATH'),
      'PWD' => $this->directory,
      'RECOVERY_REAL_COMPOSER' => $this->composer,
      'RECOVERY_TEST_MODE' => $mode,
    ]);
    $process->setTimeout(30);
    $process->run();
    return $process;
  }

  /**
   * Builds the arguments to run the fixture updater CLI.
   */
  private function cliArguments(array $arguments): array {
    return array_merge([
      PHP_BINARY,
      '-d',
      'auto_prepend_file=' . $this->directory . '/bootstrap.php',
      'vendor/bin/drupal-updater',
    ], $arguments);
  }

  /**
   * Resolves the fixture project dependencies offline.
   */
  private function installFixture(): void {
    $this->process([
      $this->composer,
      'update',
      '--no-interaction',
      '--no-progress',
      '--no-scripts',
      '--no-plugins',
      '--no-audit',
    ]);
  }

  /**
   * Asserts the Composer files and Git state are the ones before the update.
   */
  private function assertWorkingStatePreserved(): void {
    self::assertSame($this->before['json'], file_get_contents($this->directory . '/composer.json'));
    self::assertSame($this->before['lock'], file_get_contents($this->directory . '/composer.lock'));
    self::assertSame($this->before['head'], $this->process(['git', 'rev-parse', 'HEAD'])->getOutput());
    self::assertSame($this->before['index'], $this->process(['git', 'ls-files', '--stage'])->getOutput());
    self::assertSame('retain earlier work', file_get_contents($this->directory . '/earlier-success.txt'));
  }

  /**
   * Gets the Composer operations the fixture received.
   */
  private function operations(): array {
    return array_map(function ($line) {
      return json_decode($line, TRUE)[0];
    }, file($this->directory . '/composer-calls.jsonl', FILE_IGNORE_NEW_LINES));
  }

  /**
   * Runs a command in the fixture project.
   */
  private function process(array $arguments): Process {
    $process = new Process($arguments, $this->directory);
    $process->setTimeout(30);
    $process->mustRun();
    return $process;
  }

  /**
   * Writes a JSON file in the fixture project.
   */
  private function json(string $path, array $value): void {
    file_put_contents($this->directory . '/' . $path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Copies a directory recursively.
   */
  private function copy(string $source, string $target): void {
    mkdir($target);
    foreach (new \FilesystemIterator($source) as $file) {
      $destination = $target . '/' . $file->getFilename();
      if ($file->isDir()) {
        $this->copy($file->getPathname(), $destination);
      }
      else {
        copy($file->getPathname(), $destination);
      }
    }
  }

  /**
   * Removes the fixture project.
   */
  protected function tearDown(): void {
    $this->remove($this->directory);
  }

  /**
   * Removes a file or directory recursively.
   */
  private function remove(string $path): void {
    if (is_dir($path) && !is_link($path)) {
      foreach (new \FilesystemIterator($path) as $file) {
        $this->remove($file->getPathname());
      }
      rmdir($path);
    }
    else {
      unlink($path);
    }
  }

}
