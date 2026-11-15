<?php

namespace DrupalUpdater\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Runs the installed CLI with real Git/Composer and a controlled failed update. */
class ComposerRecoveryCliTest extends TestCase {
  private string $directory;
  private string $composer;
  private array $before;

  protected function setUp(): void {
    $this->directory = sys_get_temp_dir() . '/updater-recovery-' . bin2hex(random_bytes(8));
    mkdir($this->directory);
    $this->composer = trim($this->process(['sh', '-c', 'command -v composer'])->getOutput());
    $source = getenv('UPDATER_TEST_SOURCE') ?: dirname(__DIR__);
    foreach (['fixtures/updater', 'fixtures/diff', 'fixtures/library-1', 'fixtures/library-2', 'control', 'web/core'] as $path) {
      mkdir($this->directory . '/' . $path, 0777, TRUE);
    }
    // The fixture package contains the candidate's actual source and entrypoint.
    // Test-runner dependencies supply Symfony; no Drupal database is installed.
    $this->copy($source . '/src', $this->directory . '/fixtures/updater/src');
    copy($source . '/drupal-updater', $this->directory . '/fixtures/updater/drupal-updater');
    $this->json('fixtures/updater/composer.json', [
      'name' => 'metadrop/drupal-updater', 'version' => '1.0.0',
      'require' => ['davidrjonas/composer-lock-diff' => '1.7.1'],
      'autoload' => ['psr-4' => ['DrupalUpdater\\' => 'src/']],
      'bin' => ['drupal-updater'],
    ]);
    $this->json('fixtures/diff/composer.json', [
      'name' => 'davidrjonas/composer-lock-diff', 'version' => '1.7.1',
      'bin' => ['composer-lock-diff'],
    ]);
    file_put_contents($this->directory . '/fixtures/diff/composer-lock-diff', "#!/usr/bin/env php\n<?php if (in_array('--probe', \$argv)) { echo 'tool available'; } elseif (in_array('--json', \$argv)) { echo '{\"changes\":[],\"changes-dev\":[]}'; }\n");
    foreach (['library-1' => '1.0.0', 'library-2' => '1.1.0'] as $path => $version) {
      $this->json('fixtures/' . $path . '/composer.json', ['name' => 'example/library', 'version' => $version]);
    }
    $manifest = [
      'name' => 'example/project', 'require' => ['example/library' => '1.0.0'],
      'repositories' => [
        ['type' => 'path', 'url' => 'fixtures/*', 'options' => ['symlink' => FALSE]],
        ['packagist.org' => FALSE],
      ],
      'scripts' => ['post-install-cmd' => '@php -r "file_put_contents(\'project-script-ran\', \'unexpected\');"'],
    ];
    $this->json('composer.json', $manifest);
    $this->installFixture();
    file_put_contents($this->directory . '/earlier-success.txt', 'retain earlier work');
    foreach ([['git', 'init', '-q'], ['git', 'add', 'composer.json', 'composer.lock', 'earlier-success.txt'], ['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgsign=false', 'commit', '-qm', 'Before temporary updater installation']] as $command) {
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
    file_put_contents($this->directory . '/settings.yml', "consolidateConfiguration: false\n");
    file_put_contents($this->directory . '/control/composer', '#!' . PHP_BINARY . "\n" . file_get_contents(__DIR__ . '/fixtures/composer-failure.php'));
    chmod($this->directory . '/control/composer', 0755);
    file_put_contents($this->directory . '/bootstrap.php', '<?php $loader = require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', TRUE) . '; $loader->setPsr4("DrupalUpdater\\\\", __DIR__."/vendor/metadrop/drupal-updater/src"); \Composer\InstalledVersions::reload(["root"=>["name"=>"example/project"],"versions"=>["drupal/core"=>["install_path"=>__DIR__."/web/core"]]]);');
  }

  public function testFailedMutationRestoresFilesAndReinstallsTools(): void {
    $process = $this->updater('recover');
    $this->assertWorkingStatePreserved();
    self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    self::assertSame(['update', 'install'], $this->operations());
    self::assertFileDoesNotExist($this->directory . '/project-script-ran');
    self::assertSame(0, $this->process($this->cliArguments(['--help']))->getExitCode());
    self::assertSame('tool available', $this->process([PHP_BINARY, 'vendor/bin/composer-lock-diff', '--probe'])->getOutput());
  }

  public function testFailedReinstallStopsBeforeLaterPackages(): void {
    $process = $this->updater('install-fails', 'example/fail,example/later');
    self::assertFileDoesNotExist($this->directory . '/later-package-ran');
    self::assertNotSame(0, $process->getExitCode());
    self::assertStringContainsString('Composer recovery failed; stopping', $process->getOutput() . $process->getErrorOutput());
    self::assertSame(['update', 'install'], $this->operations());
    $this->assertWorkingStatePreserved();
  }

  public function testSuccessfulInstallWithMissingToolStillStops(): void {
    $process = $this->updater('missing-tool', 'example/fail,example/later');
    self::assertFileDoesNotExist($this->directory . '/later-package-ran');
    self::assertNotSame(0, $process->getExitCode());
    self::assertStringContainsString('Required updater executable missing', $process->getOutput() . $process->getErrorOutput());
    self::assertSame(['update', 'install'], $this->operations());
    $this->assertWorkingStatePreserved();
  }

  private function updater(string $mode, string $packages = 'example/fail'): Process {
    $process = new Process($this->cliArguments(['update', '--config=settings.yml', '--packages=' . $packages, '--no-interaction']), $this->directory, [
      'PATH' => $this->directory . '/control:' . $this->directory . '/vendor/bin:' . getenv('PATH'),
      'PWD' => $this->directory, 'RECOVERY_REAL_COMPOSER' => $this->composer,
      'RECOVERY_TEST_MODE' => $mode,
    ]);
    $process->setTimeout(30);
    $process->run();
    return $process;
  }

  private function cliArguments(array $arguments): array {
    return array_merge([PHP_BINARY, '-d', 'auto_prepend_file=' . $this->directory . '/bootstrap.php', 'vendor/bin/drupal-updater'], $arguments);
  }

  private function installFixture(): void {
    $this->process([$this->composer, 'update', '--no-interaction', '--no-progress', '--no-scripts', '--no-plugins', '--no-audit']);
  }

  private function assertWorkingStatePreserved(): void {
    self::assertSame($this->before['json'], file_get_contents($this->directory . '/composer.json'));
    self::assertSame($this->before['lock'], file_get_contents($this->directory . '/composer.lock'));
    self::assertSame($this->before['head'], $this->process(['git', 'rev-parse', 'HEAD'])->getOutput());
    self::assertSame($this->before['index'], $this->process(['git', 'ls-files', '--stage'])->getOutput());
    self::assertSame('retain earlier work', file_get_contents($this->directory . '/earlier-success.txt'));
  }

  private function operations(): array {
    return array_map(function ($line) { return json_decode($line, TRUE)[0]; }, file($this->directory . '/composer-calls.jsonl', FILE_IGNORE_NEW_LINES));
  }

  private function process(array $arguments): Process {
    $process = new Process($arguments, $this->directory);
    $process->setTimeout(30);
    $process->mustRun();
    return $process;
  }

  private function json(string $path, array $value): void {
    file_put_contents($this->directory . '/' . $path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }

  private function copy(string $source, string $target): void {
    mkdir($target);
    foreach (new \FilesystemIterator($source) as $file) {
      $destination = $target . '/' . $file->getFilename();
      if ($file->isDir()) { $this->copy($file->getPathname(), $destination); }
      else { copy($file->getPathname(), $destination); }
    }
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
