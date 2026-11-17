<?php

namespace DrupalUpdater\Tests\Functional;

use DrupalUpdater\Tests\Simulator\Filesystem;
use DrupalUpdater\Tests\Simulator\Package;
use DrupalUpdater\Tests\Simulator\Scenario;
use Symfony\Component\Process\Process;

/**
 * A fixture project installed by the real Composer, without network.
 *
 * Every version of every scenario package is published in a local "path"
 * repository, packagist.org is disabled, and the packages are installed at
 * their declared version, so the latest one is available to update to.
 */
final class RealComposerProject extends FixtureProject {

  /**
   * Creates the project.
   *
   * @param string $directory
   *   Project root.
   * @param \DrupalUpdater\Tests\Simulator\Scenario $scenario
   *   Scenario.
   * @param string $composer
   *   Path of the real Composer.
   * @param array<string, string> $environment
   *   Environment for Composer, e.g. an isolated COMPOSER_HOME.
   */
  public function __construct(
    string $directory,
    Scenario $scenario,
    private string $composer,
    private array $environment,
  ) {
    parent::__construct($directory, $scenario);
  }

  /**
   * Runs the real Composer in the project.
   *
   * @return \Symfony\Component\Process\Process
   *   The finished process.
   */
  public function composer(string ...$arguments): Process {
    $process = new Process([$this->composer, ...$arguments, '--no-interaction'], $this->directory, $this->environment);
    $process->setTimeout(120);
    $process->run();
    return $process;
  }

  /**
   * {@inheritdoc}
   */
  protected function writeComposerFiles(): void {
    $packages = $this->scenario->packages();
    ksort($packages);
    foreach ($packages as $package) {
      $this->publish($package, $package->version());
      if ($package->latest() !== NULL) {
        $this->publish($package, $package->latest());
      }
    }
    file_put_contents($this->path('.gitignore'), "/vendor/\n/repository/\n");
    $repositories = [
      'repositories' => [
        ['type' => 'path', 'url' => 'repository/*/*', 'options' => ['symlink' => FALSE]],
        ['packagist.org' => FALSE],
      ],
    ];

    // Install every package at its declared version, transitive ones too...
    $pinned = $this->manifest([], $repositories);
    foreach ($packages as $package) {
      $pinned[$package->isDev() ? 'require-dev' : 'require'][$package->name()] = $package->version();
    }
    Filesystem::writeJson($this->path('composer.json'), $pinned);
    $this->composer('update', '--no-progress', '--no-scripts')->mustRun();

    // ...then keep only the real root requirements, without changing versions.
    Filesystem::writeJson($this->path('composer.json'), $this->manifest($packages, $repositories));
    $this->composer('update', '--lock', '--no-progress', '--no-scripts')->mustRun();
  }

  /**
   * Publishes one version of a package in the local repository.
   */
  private function publish(Package $package, string $version): void {
    Filesystem::writeJson($this->path(sprintf('repository/%s/%s/composer.json', str_replace('/', '--', $package->name()), $version)), array_filter([
      'name' => $package->name(),
      'version' => $version,
      'type' => $package->type(),
      'require' => $package->requirements(),
    ]));
  }

}
