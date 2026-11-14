<?php

namespace DrupalUpdater\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Black-box CLI checks for #33. Composer is a controlled slow executable;
 * configuration loading, Symfony command execution, timeouts and Git are real.
 * No UpdaterCommand methods are replaced. No live Drupal/database is required.
 */
class TimeoutCliTest extends TestCase {
  private string $directory;

  protected function tearDown(): void {
    if (isset($this->directory)) { $this->remove($this->directory); }
  }

  public function testCliStopsComposerAtConfiguredTimeout(): void {
    $process = $this->runCli(1);
    self::assertStringContainsString('exceeded the timeout of 1 seconds', $process->getOutput() . $process->getErrorOutput());
    self::assertSame('1', trim(file_get_contents($this->directory . '/received-timeout')));
    self::assertFileDoesNotExist($this->directory . '/update-completed');
  }

  public function testCliAllowsSameOperationWithLongerTimeout(): void {
    $process = $this->runCli(5);
    self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    self::assertSame('5', trim(file_get_contents($this->directory . '/received-timeout')));
    self::assertFileExists($this->directory . '/update-completed');
    self::assertStringNotContainsString('exceeded the timeout', $process->getOutput() . $process->getErrorOutput());
  }

  private function runCli(int $timeout): Process {
    $source = getenv('UPDATER_TEST_SOURCE') ?: dirname(__DIR__);
    $this->directory = sys_get_temp_dir() . '/updater-cli-' . bin2hex(random_bytes(8));
    mkdir($this->directory);
    mkdir($this->directory . '/bin');
    mkdir($this->directory . '/web');
    mkdir($this->directory . '/web/core');
    file_put_contents($this->directory . '/composer.json', '{"require":{"example/tool":"1.0.0"}}');
    file_put_contents($this->directory . '/composer.lock', '{"packages":[{"name":"example/tool","version":"1.0.0"}]}');
    // Register the fixture Drupal install path for the CLI's normal root lookup.
    // This changes fixture metadata, not the command implementation.
    file_put_contents($this->directory . '/bootstrap.php', '<?php $loader = require ' . var_export($source . '/vendor/autoload.php', TRUE) . '; $loader->setPsr4("DrupalUpdater\\\\", ' . var_export($source . '/src', TRUE) . '); \Composer\InstalledVersions::reload(["root"=>["name"=>"example/project"],"versions"=>["drupal/core"=>["install_path"=>__DIR__."/web/core"]]]);');
    file_put_contents($this->directory . '/settings.yml', "consolidateConfiguration: false\ncomposerTimeout: $timeout\n");
    file_put_contents($this->directory . '/bin/composer', '#!' . PHP_BINARY . "\n" . <<<'PHP'
<?php
if ($argv[1] === 'update') {
  file_put_contents('received-timeout', getenv('COMPOSER_PROCESS_TIMEOUT'));
  usleep(2000000);
  file_put_contents('update-completed', 'yes');
}
elseif ($argv[1] === 'show') { echo '{"locked":[]}'; }
elseif ($argv[1] !== 'install') { fwrite(STDERR, 'Unexpected fixture command'); exit(90); }
PHP
    );
    chmod($this->directory . '/bin/composer', 0755);
    // No-op package updates still invoke the real CLI's final diff command.
    file_put_contents($this->directory . '/bin/composer-lock-diff', "#!/bin/sh\nexit 0\n");
    chmod($this->directory . '/bin/composer-lock-diff', 0755);
    foreach ([['git', 'init', '-q'], ['git', 'add', 'composer.json', 'composer.lock'], ['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgsign=false', 'commit', '-qm', 'Fixture baseline']] as $command) {
      (new Process($command, $this->directory))->mustRun();
    }
    $process = new Process([
      PHP_BINARY, '-d', 'auto_prepend_file=' . $this->directory . '/bootstrap.php',
      $source . '/drupal-updater', 'update', '--config', 'settings.yml',
      '--packages=example/tool', '--no-interaction',
    ], $this->directory, [
      'PATH' => $this->directory . '/bin:' . getenv('PATH'),
      'PWD' => $this->directory,
    ]);
    $process->setTimeout(15);
    $process->run();
    return $process;
  }

  private function remove(string $path): void {
    if (is_dir($path) && !is_link($path)) {
      foreach (scandir($path) as $name) {
        if ($name !== '.' && $name !== '..') { $this->remove($path . '/' . $name); }
      }
      rmdir($path);
    }
    else { unlink($path); }
  }
}
