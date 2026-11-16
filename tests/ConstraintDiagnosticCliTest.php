<?php

namespace DrupalUpdater\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Exercise the installed CLI with controlled Composer update results.
 */
class ConstraintDiagnosticCliTest extends TestCase {

  /**
   * Fixture project directory.
   *
   * @var string
   */
  private string $directory;

  /**
   * Builds a fixture project with a controlled Composer update.
   */
  protected function setUp(): void {
    $this->directory = sys_get_temp_dir() . '/updater-constraint-' . bin2hex(random_bytes(8));
    mkdir($this->directory);
    mkdir($this->directory . '/control');
    mkdir($this->directory . '/web/core', 0777, TRUE);
    file_put_contents($this->directory . '/composer.json', '{"name":"example/project","require":{"example/noop":"1.0.0"}}');
    file_put_contents($this->directory . '/composer.lock', '{"packages":[{"name":"example/noop","version":"1.0.0"}],"packages-dev":[]}');
    file_put_contents($this->directory . '/settings.yml', "consolidateConfiguration: false\npackages: [example/noop]\n");
    file_put_contents($this->directory . '/control/composer', '#!' . PHP_BINARY . "\n" . file_get_contents(__DIR__ . '/fixtures/constraint-composer.php'));
    $scripts = [
      'composer-lock-diff' => "<?php if (in_array('--json', \$argv)) { echo '{}'; }",
      'drush' => "<?php echo '[]';",
    ];
    foreach ($scripts as $name => $script) {
      file_put_contents($this->directory . '/control/' . $name, '#!' . PHP_BINARY . "\n" . $script);
    }
    foreach (['composer', 'composer-lock-diff', 'drush'] as $name) {
      chmod($this->directory . '/control/' . $name, 0755);
    }
    $git_commands = [
      ['git', 'init', '-q'],
      ['git', 'config', 'user.name', 'Test'],
      ['git', 'config', 'user.email', 'test@example.invalid'],
      ['git', 'config', 'commit.gpgsign', 'false'],
      ['git', 'add', 'composer.json', 'composer.lock'],
      ['git', '-c', 'core.hooksPath=/dev/null', 'commit', '-qm', 'Baseline'],
    ];
    foreach ($git_commands as $args) {
      (new Process($args, $this->directory))->mustRun();
    }
  }

  /**
   * Tests a stable version gets a dry run suggestion.
   */
  public function testStableVersionHasAnActionableDryRunSuggestion(): void {
    $this->assertSuggestion('1.1.0');
  }

  /**
   * Tests a prerelease version is kept as is in the suggestion.
   */
  public function testPrereleaseVersionIsPreservedInTheSuggestion(): void {
    $this->assertSuggestion('2.0.0-beta1');
  }

  /**
   * Tests a development version is kept as is in the suggestion.
   */
  public function testDevelopmentVersionIsPreservedInTheSuggestion(): void {
    $this->assertSuggestion('dev-main');
  }

  /**
   * Tests an up to date package gets no suggestion.
   */
  public function testCurrentPackageDoesNotHaveSuggestion(): void {
    $this->assertNoSuggestion('current');
  }

  /**
   * Tests a package without an available update gets no suggestion.
   */
  public function testPackageWithoutAvailableUpdateDoesNotHaveSuggestion(): void {
    $this->assertNoSuggestion('absent');
  }

  /**
   * Tests a successful update gets no suggestion.
   */
  public function testSuccessfulUpdateDoesNotHaveSuggestion(): void {
    $process = $this->updater('updated');
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    self::assertStringNotContainsString('For more information, run:', $this->cliOutput($process));
    self::assertSame('1.1.0', json_decode(file_get_contents($this->directory . '/composer.lock'), TRUE)['packages'][0]['version']);
    $this->assertNoRequireWasExecuted();
  }

  /**
   * Asserts the dry run suggestion for a version is printed without running it.
   */
  private function assertSuggestion(string $version): void {
    $before = file_get_contents($this->directory . '/composer.json');
    $process = $this->updater($version);
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    self::assertStringContainsString('For more information, run: composer require ' . "'example/noop:" . $version . "' -W --dry-run", $this->cliOutput($process));
    self::assertSame($before, file_get_contents($this->directory . '/composer.json'));
    self::assertSame('1.0.0', json_decode(file_get_contents($this->directory . '/composer.lock'), TRUE)['packages'][0]['version']);
    $this->assertNoRequireWasExecuted();
  }

  /**
   * Asserts no suggestion is printed for the given fixture mode.
   */
  private function assertNoSuggestion(string $mode): void {
    $process = $this->updater($mode);
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    self::assertStringNotContainsString('For more information, run:', $this->cliOutput($process));
    $this->assertNoRequireWasExecuted();
  }

  /**
   * Asserts composer require was never executed.
   */
  private function assertNoRequireWasExecuted(): void {
    $calls = array_map(function ($line) {
      return json_decode($line, TRUE);
    }, file($this->directory . '/composer-calls.jsonl', FILE_IGNORE_NEW_LINES));
    self::assertSame([], array_values(array_filter($calls, function ($call) {
      return $call[0] === 'require';
    })));
  }

  /**
   * Runs the updater CLI with the given fixture mode.
   */
  private function updater(string $mode): Process {
    $source = getenv('UPDATER_TEST_SOURCE') ?: dirname(__DIR__);
    file_put_contents($this->directory . '/bootstrap.php', '<?php $loader = require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', TRUE) . '; $loader->setPsr4("DrupalUpdater\\\\", ' . var_export($source . '/src', TRUE) . '); \Composer\InstalledVersions::reload(["root"=>["name"=>"example/project"],"versions"=>["drupal/core"=>["install_path"=>__DIR__."/web/core"]]]);');
    $args = [
      PHP_BINARY,
      '-d',
      'auto_prepend_file=' . $this->directory . '/bootstrap.php',
      $source . '/drupal-updater',
      'update',
      '--config=settings.yml',
      '--packages=example/noop',
      '--no-interaction',
    ];
    $process = new Process($args, $this->directory, [
      'PATH' => $this->directory . '/control:' . getenv('PATH'),
      'PWD' => $this->directory,
      'CONSTRAINT_TEST_MODE' => $mode,
    ]);
    $process->setTimeout(20);
    $process->run();
    return $process;
  }

  /**
   * Gets the standard and error output of the CLI.
   */
  private function cliOutput(Process $process): string {
    return $process->getOutput() . $process->getErrorOutput();
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
