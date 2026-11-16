<?php

namespace DrupalUpdater\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Exercise the installed CLI with controlled Composer report results.
 */
class PendingUpdateReportCliTest extends TestCase {

  /**
   * Fixture project directory.
   *
   * @var string
   */
  private string $directory;

  /**
   * Builds a fixture project with a controlled Composer report.
   */
  protected function setUp(): void {
    $this->directory = sys_get_temp_dir() . '/updater-empty-report-' . bin2hex(random_bytes(8));
    mkdir($this->directory);
    mkdir($this->directory . '/control');
    mkdir($this->directory . '/web/core', 0777, TRUE);
    file_put_contents($this->directory . '/composer.json', '{"name":"example/project"}');
    file_put_contents($this->directory . '/composer.lock', '{"packages":[],"packages-dev":[]}');
    file_put_contents($this->directory . '/settings.yml', "consolidateConfiguration: false\npackages: [example/noop]\n");
    file_put_contents($this->directory . '/control/composer', '#!' . PHP_BINARY . "\n" . file_get_contents(__DIR__ . '/fixtures/report-composer.php'));
    foreach (['composer-lock-diff' => "<?php exit(0);", 'drush' => "<?php echo '[]';"] as $name => $script) {
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
   * Tests every empty report category prints a message.
   */
  public function testEmptyNormalReportHasMessagesForEveryCategory(): void {
    $process = $this->updater('empty');
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    $report = $this->report($process);
    self::assertStringContainsString("/// Not Updated Packages (Direct): ///\n\nNo pending direct package updates found.", $report);
    self::assertStringContainsString("/// Not Updated Packages (ALL): ///\n\nNo pending package updates found.", $report);
    self::assertStringContainsString("/// Not Updated Securities (ALL): ///\n\nNo pending security updates found.", $report);
  }

  /**
   * Tests an empty security-only report prints a message.
   */
  public function testEmptySecurityOnlyReportHasMessage(): void {
    $process = $this->updater('empty', TRUE);
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    $report = $this->report($process);
    self::assertStringContainsString("/// Not Updated Securities: ///\n\nNo pending security updates found.", $report);
    self::assertStringNotContainsString('Not Updated Packages', $report);
  }

  /**
   * Tests a populated report keeps its results.
   */
  public function testPopulatedNormalReportRetainsResults(): void {
    $process = $this->updater('populated');
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    $report = $this->report($process);
    self::assertStringContainsString('example/direct 1.0.0 1.1.0', $report);
    self::assertStringContainsString('example/transitive 1.0.0 1.1.0', $report);
    self::assertStringContainsString('example/vulnerable', $report);
    self::assertStringNotContainsString('No pending', $report);
  }

  /**
   * Tests a populated security-only report keeps its results.
   */
  public function testPopulatedSecurityOnlyReportRetainsResults(): void {
    $process = $this->updater('populated', TRUE);
    self::assertSame(0, $process->getExitCode(), $this->cliOutput($process));
    $report = $this->report($process);
    self::assertStringContainsString('example/vulnerable', $report);
    self::assertStringNotContainsString('No pending', $report);
    self::assertStringNotContainsString('Not Updated Packages', $report);
  }

  /**
   * Tests a failed report command is not shown as an empty report.
   */
  public function testFailedPackageReportIsNotPresentedAsEmpty(): void {
    $process = $this->updater('failed');
    self::assertNotSame(0, $process->getExitCode());
    self::assertStringContainsString('REPORT_FAILED', preg_replace('/\s+/', '', $this->cliOutput($process)));
    self::assertStringNotContainsString('No pending', $this->report($process));
  }

  /**
   * Runs the updater CLI with the given fixture mode.
   */
  private function updater(string $mode, bool $security = FALSE): Process {
    $source = getenv('UPDATER_TEST_SOURCE') ?: dirname(__DIR__);
    file_put_contents($this->directory . '/bootstrap.php', '<?php $loader = require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', TRUE) . '; $loader->setPsr4("DrupalUpdater\\\\", ' . var_export($source . '/src', TRUE) . '); \Composer\InstalledVersions::reload(["root"=>["name"=>"example/project"],"versions"=>["drupal/core"=>["install_path"=>__DIR__."/web/core"]]]);');
    $args = [
      PHP_BINARY,
      '-d',
      'auto_prepend_file=' . $this->directory . '/bootstrap.php',
      $source . '/drupal-updater',
      'update',
      '--config=settings.yml',
      '--no-interaction',
    ];
    if ($security) {
      $args[] = '--security';
    }
    $process = new Process($args, $this->directory, [
      'PATH' => $this->directory . '/control:' . getenv('PATH'),
      'PWD' => $this->directory,
      'PENDING_REPORT_MODE' => $mode,
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
   * Gets the report section from the CLI output.
   */
  private function report(Process $process): string {
    $output = $this->cliOutput($process);
    $position = strpos($output, '// 4. REPORT //');
    self::assertNotFalse($position, $output);
    return substr($output, $position);
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
