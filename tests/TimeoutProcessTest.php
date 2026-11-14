<?php

namespace DrupalUpdater\Tests;

use DrupalUpdater\Config\Config;
use DrupalUpdater\UpdaterCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Exposes the command runner without replacing its behavior. */
class TimeoutCommand extends UpdaterCommand {
  public function __construct(Config $config) {
    parent::__construct();
    $this->setConfiguration($config);
  }
  public function executeShell(string $command): Process { return $this->runCommand($command); }
}

class TimeoutProcessTest extends TestCase {
  private string $directory;
  private string $previousPath;
  private array $previousEnvironment;

  protected function setUp(): void {
    $this->previousPath = getenv('PATH');
    $this->previousEnvironment = [$_SERVER['PATH'] ?? NULL, $_ENV['PATH'] ?? NULL];
    $this->directory = sys_get_temp_dir() . '/updater-process-' . bin2hex(random_bytes(8));
    mkdir($this->directory);
    file_put_contents($this->directory . '/composer', "#!/bin/sh\nprintf '%s' \"\$COMPOSER_PROCESS_TIMEOUT\"\n");
    chmod($this->directory . '/composer', 0755);
    putenv('PATH=' . $this->directory . ':' . $this->previousPath);
    $_SERVER['PATH'] = $_ENV['PATH'] = $this->directory . ':' . $this->previousPath;
  }

  protected function tearDown(): void {
    putenv('PATH=' . $this->previousPath);
    if ($this->previousEnvironment[0] === NULL) { unset($_SERVER['PATH']); }
    else { $_SERVER['PATH'] = $this->previousEnvironment[0]; }
    if ($this->previousEnvironment[1] === NULL) { unset($_ENV['PATH']); }
    else { $_ENV['PATH'] = $this->previousEnvironment[1]; }
    foreach (glob($this->directory . '/*') as $file) { unlink($file); }
    rmdir($this->directory);
  }

  public function testComposerCommandsUseBothTimeoutSettings(): void {
    $command = $this->command();
    foreach (['composer show', 'composer show 2>/dev/null', 'composer audit | cat'] as $shell) {
      $process = $command->executeShell($shell);
      self::assertSame(1800.0, $process->getTimeout());
      self::assertSame('1800', $process->getEnv()['COMPOSER_PROCESS_TIMEOUT']);
      self::assertSame('1800', $process->getOutput());
    }
    self::assertSame(300.0, (new TimeoutCommand(new Config()))->executeShell('composer show')->getTimeout());
  }

  public function testOtherCommandsKeepTheirExistingTimeout(): void {
    foreach (['printf composer', 'git --version'] as $shell) {
      $process = $this->command()->executeShell($shell);
      self::assertSame(300.0, $process->getTimeout());
      self::assertArrayNotHasKey('COMPOSER_PROCESS_TIMEOUT', $process->getEnv());
    }
    copy($this->directory . '/composer', $this->directory . '/composer-lock-diff');
    chmod($this->directory . '/composer-lock-diff', 0755);
    self::assertSame(300.0, $this->command()->executeShell('composer-lock-diff')->getTimeout());
  }

  public function testExplicitShortShellTimeoutStillApplies(): void {
    if ((new Process(['sh', '-c', 'command -v timeout']))->run() !== 0) {
      self::markTestSkipped('Requires the timeout executable.');
    }
    file_put_contents($this->directory . '/composer', "#!/bin/sh\nexec sleep 2\n");
    $start = microtime(TRUE);
    try {
      $this->command()->executeShell('timeout 0.1 composer show');
      self::fail('The explicit shell timeout did not interrupt Composer.');
    }
    catch (\RuntimeException $e) {
      self::assertStringContainsString('Error running', $e->getMessage());
    }
    self::assertLessThan(1.5, microtime(TRUE) - $start);
  }

  private function command(): TimeoutCommand {
    $path = $this->directory . '/settings.yml';
    file_put_contents($path, 'composerTimeout: 1800');
    return new TimeoutCommand(Config::createFromConfigurationFile($path));
  }
}
