<?php

namespace DrupalUpdater\Tests\Functional;

use DrupalUpdater\Tests\Simulator\Filesystem;
use DrupalUpdater\Tests\Simulator\Package;
use DrupalUpdater\Tests\Simulator\Scenario;
use Symfony\Component\Process\Process;

/**
 * A disposable Drupal project in Git, built from the scenario packages.
 *
 * It has composer.json, composer.lock, a web root and a config folder, all
 * committed, so the updater can diff and commit as it does on a real site.
 */
class FixtureProject {

  /**
   * Commit the fixture starts from.
   */
  private string $baseline;

  public function __construct(
    protected string $directory,
    protected Scenario $scenario,
  ) {}

  /**
   * Writes the project files and commits them.
   */
  public function create(): void {
    mkdir($this->directory . '/web', 0777, TRUE);
    mkdir($this->directory . '/config/sync', 0777, TRUE);
    file_put_contents($this->directory . '/web/index.php', "<?php\n");
    file_put_contents($this->directory . '/config/sync/.gitkeep', '');
    $this->writeComposerFiles();
    $this->git('init', '-q');
    $this->git('config', 'user.name', 'Fixture');
    $this->git('config', 'user.email', 'fixture@example.invalid');
    $this->git('config', 'commit.gpgsign', 'false');
    $this->git('config', 'core.hooksPath', '/dev/null');
    $this->git('add', '-A');
    $this->git('commit', '-qm', 'Fixture baseline');
    $this->baseline = trim($this->git('rev-parse', 'HEAD'));
  }

  /**
   * Gets the project root.
   */
  public function path(string $relative = ''): string {
    return $relative === '' ? $this->directory : $this->directory . '/' . $relative;
  }

  /**
   * Gets the version of a package in composer.lock, NULL when not locked.
   */
  public function lockedVersion(string $name): ?string {
    $lock = json_decode((string) file_get_contents($this->path('composer.lock')), TRUE);
    foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
      if ($package['name'] === $name) {
        return $package['version'];
      }
    }
    return NULL;
  }

  /**
   * Gets the subjects of the commits made after the baseline, oldest first.
   *
   * @return list<string>
   *   Commit subjects.
   */
  public function commitSubjects(): array {
    $log = trim($this->git('log', '--reverse', '--format=%s', $this->baseline . '..HEAD'));
    return $log === '' ? [] : explode("\n", $log);
  }

  /**
   * Gets the uncommitted changes, as "git status --porcelain" prints them.
   */
  public function uncommittedChanges(): string {
    return trim($this->git('status', '--porcelain'));
  }

  /**
   * Runs git in the project and returns its output.
   */
  public function git(string ...$arguments): string {
    $process = new Process(['git', ...$arguments], $this->directory);
    $process->mustRun();
    return $process->getOutput();
  }

  /**
   * Writes composer.json and composer.lock from the scenario packages.
   */
  protected function writeComposerFiles(): void {
    $packages = $this->scenario->packages();
    ksort($packages);
    Filesystem::writeJson($this->path('composer.json'), $this->manifest($packages));
    Filesystem::writeJson($this->path('composer.lock'), [
      'packages' => $this->lockEntries(array_filter($packages, fn (Package $package) => !$package->isDev())),
      'packages-dev' => $this->lockEntries(array_filter($packages, fn (Package $package) => $package->isDev())),
    ]);
  }

  /**
   * Builds the root composer.json.
   *
   * @param array<string, \DrupalUpdater\Tests\Simulator\Package> $packages
   *   Packages keyed by name.
   * @param array<string, mixed> $extra
   *   Extra top-level keys.
   *
   * @return array<string, mixed>
   *   Manifest.
   */
  protected function manifest(array $packages, array $extra = []): array {
    $manifest = ['name' => 'example/project', 'version' => '1.0.0', 'type' => 'project'] + $extra;
    foreach ($packages as $package) {
      if ($package->isDirect()) {
        $manifest[$package->isDev() ? 'require-dev' : 'require'][$package->name()] = $package->rootConstraint();
      }
    }
    return $manifest;
  }

  /**
   * Builds composer.lock entries.
   *
   * @param array<string, \DrupalUpdater\Tests\Simulator\Package> $packages
   *   Packages keyed by name.
   *
   * @return list<array<string, mixed>>
   *   Lock entries.
   */
  private function lockEntries(array $packages): array {
    return array_values(array_map(fn (Package $package) => array_filter([
      'name' => $package->name(),
      'version' => $package->version(),
      'type' => $package->type(),
      'require' => $package->requirements(),
    ]), $packages));
  }

}
