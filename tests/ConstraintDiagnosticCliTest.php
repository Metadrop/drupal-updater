<?php

namespace DrupalUpdater\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Exercise the installed CLI with controlled Composer update results. */
class ConstraintDiagnosticCliTest extends TestCase {
  private string $directory;

  protected function setUp(): void {
    $this->directory = sys_get_temp_dir() . '/updater-constraint-' . bin2hex(random_bytes(8));
    mkdir($this->directory);
    mkdir($this->directory . '/control');
    mkdir($this->directory . '/web/core', 0777, TRUE);
    file_put_contents($this->directory . '/composer.json', '{"name":"example/project","require":{"example/noop":"1.0.0"}}');
    file_put_contents($this->directory . '/composer.lock', '{"packages":[{"name":"example/noop","version":"1.0.0"}],"packages-dev":[]}');
    file_put_contents($this->directory . '/settings.yml', "consolidateConfiguration: false\npackages: [example/noop]\n");
    file_put_contents($this->directory . '/control/composer', '#!' . PHP_BINARY . "\n" . file_get_contents(__DIR__ . '/fixtures/constraint-composer.php'));
    foreach (['composer-lock-diff' => "<?php if (in_array('--json', \$argv)) { echo '{}'; }", 'drush' => "<?php echo '[]';"] as $name => $script) {
      file_put_contents($this->directory . '/control/' . $name, '#!' . PHP_BINARY . "\n" . $script);
    }
    foreach (['composer', 'composer-lock-diff', 'drush'] as $name) { chmod($this->directory . '/control/' . $name, 0755); }
    foreach ([['git', 'init', '-q'], ['git', 'config', 'user.name', 'Test'], ['git', 'config', 'user.email', 'test@example.invalid'], ['git', 'config', 'commit.gpgsign', 'false'], ['git', 'add', 'composer.json', 'composer.lock'], ['git', '-c', 'core.hooksPath=/dev/null', 'commit', '-qm', 'Baseline']] as $args) {
      (new Process($args, $this->directory))->mustRun();
    }
  }

  public function testStableVersionHasAnActionableDryRunSuggestion(): void {
    $this->assertSuggestion('1.1.0');
  }

  public function testPrereleaseVersionIsPreservedInTheSuggestion(): void {
    $this->assertSuggestion('2.0.0-beta1');
  }

  public function testDevelopmentVersionIsPreservedInTheSuggestion(): void {
    $this->assertSuggestion('dev-main');
  }

  public function testCurrentPackageDoesNotHaveASuggestion(): void {
    $this->assertNoSuggestion('current');
  }

  public function testPackageWithoutAnAvailableUpdateDoesNotHaveASuggestion(): void {
    $this->assertNoSuggestion('absent');
  }

  public function testSuccessfulUpdateDoesNotHaveASuggestion(): void {
    $process = $this->updater('updated');
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    self::assertStringNotContainsString('For more information, run:', $this->cliOutput($process));
    self::assertSame('1.1.0', json_decode(file_get_contents($this->directory . '/composer.lock'), TRUE)['packages'][0]['version']);
    $this->assertNoRequireWasExecuted();
  }

  private function assertSuggestion(string $version): void {
    $before = file_get_contents($this->directory . '/composer.json');
    $process = $this->updater($version);
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    self::assertStringContainsString('For more information, run: composer require ' . "'example/noop:" . $version . "' -W --dry-run", $this->cliOutput($process));
    self::assertSame($before, file_get_contents($this->directory . '/composer.json'));
    self::assertSame('1.0.0', json_decode(file_get_contents($this->directory . '/composer.lock'), TRUE)['packages'][0]['version']);
    $this->assertNoRequireWasExecuted();
  }

  private function assertNoSuggestion(string $mode): void {
    $process = $this->updater($mode);
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    self::assertStringNotContainsString('For more information, run:', $this->cliOutput($process));
    $this->assertNoRequireWasExecuted();
  }

  private function assertNoRequireWasExecuted(): void {
    $calls = array_map(function ($line) { return json_decode($line, TRUE); }, file($this->directory . '/composer-calls.jsonl', FILE_IGNORE_NEW_LINES));
    self::assertSame([], array_values(array_filter($calls, function ($call) { return $call[0] === 'require'; })));
  }

  private function updater(string $mode): Process {
    $source = getenv('UPDATER_TEST_SOURCE') ?: dirname(__DIR__);
    file_put_contents($this->directory . '/bootstrap.php', '<?php $loader = require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', TRUE) . '; $loader->setPsr4("DrupalUpdater\\\\", ' . var_export($source . '/src', TRUE) . '); \Composer\InstalledVersions::reload(["root"=>["name"=>"example/project"],"versions"=>["drupal/core"=>["install_path"=>__DIR__."/web/core"]]]);');
    $args = [PHP_BINARY, '-d', 'auto_prepend_file=' . $this->directory . '/bootstrap.php', $source . '/drupal-updater', 'update', '--config=settings.yml', '--packages=example/noop', '--no-interaction'];
    $process = new Process($args, $this->directory, ['PATH' => $this->directory . '/control:' . getenv('PATH'), 'PWD' => $this->directory, 'CONSTRAINT_TEST_MODE' => $mode]);
    $process->setTimeout(20);
    $process->run();
    return $process;
  }

  private function cliOutput(Process $process): string { return $process->getOutput() . $process->getErrorOutput(); }

  protected function tearDown(): void { $this->remove($this->directory); }

  private function remove(string $path): void {
    if (is_dir($path) && !is_link($path)) {
      foreach (new \FilesystemIterator($path) as $file) { $this->remove($file->getPathname()); }
      rmdir($path);
    }
    else { unlink($path); }
  }
}
