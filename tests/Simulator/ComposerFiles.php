<?php

namespace DrupalUpdater\Tests\Simulator;

/**
 * Reads and updates the composer.json and composer.lock of a project.
 *
 * Like real Composer, the simulator takes the current versions from the lock
 * file and the direct requirements from composer.json, so it follows the
 * changes the updater and the scripted rules make to them.
 */
final class ComposerFiles {

  public function __construct(
    private string $directory,
  ) {}

  /**
   * Gets the root package name.
   */
  public function rootName(): string {
    return $this->manifest()['name'] ?? '__root__';
  }

  /**
   * Gets the root package version.
   */
  public function rootVersion(): string {
    return $this->manifest()['version'] ?? '-';
  }

  /**
   * Gets the root requirements, keyed by package name.
   *
   * @return array<string, string>
   *   Constraints keyed by package name.
   */
  public function rootRequirements(bool $include_dev = TRUE): array {
    $manifest = $this->manifest();
    $requirements = $manifest['require'] ?? [];
    if ($include_dev) {
      $requirements += $manifest['require-dev'] ?? [];
    }
    return array_filter($requirements, fn (string $name) => str_contains($name, '/'), ARRAY_FILTER_USE_KEY);
  }

  /**
   * Gets the locked packages, keyed by name and sorted by name.
   *
   * @return array<string, array{name: string, version: string, type: string, require: array<string, string>, dev: bool}>
   *   Locked packages.
   */
  public function lockedPackages(bool $include_dev = TRUE): array {
    $lock = $this->lock();
    $packages = [];
    $sections = $include_dev ? ['packages' => FALSE, 'packages-dev' => TRUE] : ['packages' => FALSE];
    foreach ($sections as $section => $dev) {
      foreach ($lock[$section] ?? [] as $package) {
        $packages[$package['name']] = [
          'name' => $package['name'],
          'version' => $package['version'],
          'type' => $package['type'] ?? 'library',
          'require' => $package['require'] ?? [],
          'dev' => $dev,
        ];
      }
    }
    ksort($packages);
    return $packages;
  }

  /**
   * Changes the locked version of some packages.
   *
   * @param array<string, string> $versions
   *   New versions keyed by package name.
   */
  public function setLockedVersions(array $versions): void {
    $lock = $this->lock();
    foreach (['packages', 'packages-dev'] as $section) {
      foreach ($lock[$section] ?? [] as $index => $package) {
        if (isset($versions[$package['name']])) {
          $lock[$section][$index]['version'] = $versions[$package['name']];
        }
      }
    }
    Filesystem::writeJson($this->directory . '/composer.lock', $lock);
  }

  /**
   * Gets the decoded composer.json.
   *
   * @return array<string, mixed>
   *   Manifest.
   */
  private function manifest(): array {
    return $this->read('composer.json');
  }

  /**
   * Gets the decoded composer.lock.
   *
   * @return array<string, mixed>
   *   Lock.
   */
  private function lock(): array {
    return $this->read('composer.lock');
  }

  /**
   * Reads a JSON file of the project; a missing or broken one is empty.
   *
   * @return array<string, mixed>
   *   Decoded file.
   */
  private function read(string $name): array {
    $path = $this->directory . '/' . $name;
    $data = is_file($path) ? json_decode((string) file_get_contents($path), TRUE) : NULL;
    return is_array($data) ? $data : [];
  }

}
