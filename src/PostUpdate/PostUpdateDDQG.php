<?php

namespace DrupalUpdater\PostUpdate;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Post-update processor for DDQG ignore entries.
 */
class PostUpdateDDQG implements PostUpdateInterface {

  /**
   * {@inheritdoc}
   */
  public function execute(string $package, array $composerLockDiff, OutputInterface $output) {
    $composerJson = json_decode(file_get_contents('composer.json'), true);
    if (!isset($composerJson['config']['audit']['ignore'])) {
      return false;
    }

    $packageParts = explode('/', $package);
    if (count($packageParts) !== 2) {
      return false;
    }
    $moduleName = $packageParts[1];

    if (!$this->isPackageUpdated($package, $composerLockDiff)) {
      return false;
    }

    [$oldVersion, $newVersion] = $this->getPackageUpdate($package, $composerLockDiff);

    // Find matching DDQG ignore entry and update if necessary
    $ignoreEntries = &$composerJson['config']['audit']['ignore'];

    if ($this->processDDQGEntry($ignoreEntries, $moduleName, $oldVersion, $newVersion, $output)) {
      $json = json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
      file_put_contents('composer.json', $json);
      $this->runCommand('git add composer.json');
    }

  }

  /**
   * Process a DDQG ignore entry for a module.
   *
   * @param array &$ignoreEntries
   *   Reference to the ignore entries array.
   * @param string $moduleName
   *   The module name without vendor prefix.
   * @param string $oldVersion
   *   The old version of the module.
   * @param string $newVersion
   *   The new version of the module.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The output interface for logging.
   *
   * @return bool
   *   TRUE if the entry was modified, FALSE otherwise.
   */
  protected function processDDQGEntry(array &$ignoreEntries, string $moduleName, string $oldVersion, string $newVersion, OutputInterface $output): bool {
    foreach ($ignoreEntries as $key => $message) {
      // Match pattern: DDQG-unsupported-drupal-{module_name}-{version}.
      if (preg_match('/^DDQG-unsupported-drupal-' . preg_quote($moduleName) . '-(.+)$/', $key, $matches)) {
        $oldReleaseType = $this->getReleaseType($oldVersion);
        $newReleaseType = $this->getReleaseType($newVersion);

        // Let composer audit the module if it has changed to abandoned.
        if ($this->isPackageAbandoned($newVersion) && !$this->isPackageAbandoned($oldVersion)) {
          unset($ignoreEntries[$key]);
          $output->writeln("Removed DDQG ignore entry for $moduleName (now abandoned)");
          return true;
        }

        // Do not ignore it anymore as its stable.
        if ($newReleaseType === 'stable' && $oldReleaseType !== 'stable') {
          unset($ignoreEntries[$key]);
          $output->writeln("Removed DDQG ignore entry for $moduleName (now stable)");
          return true;
        }

        // It is still not stable / it hasn't changed its version.
        $newKey = 'DDQG-unsupported-drupal-' . $moduleName . '-' . $this->formatVersionForDDQG($newVersion);
        unset($ignoreEntries[$key]);
        $ignoreEntries[$newKey] = $message;
        $output->writeln("Updated DDQG ignore entry for $moduleName: $oldVersion -> $newVersion");
        return true;
      }
    }

    return false;
  }

  /**
   * Determines the release type from a version string.
   *
   * @param string $version
   *   The version string (e.g., "1.0.0", "2.0.0-alpha3", "3.0.0-RC2").
   *
   * @return string
   *   The release type: 'stable', 'alpha', 'beta', or 'rc'.
   */
  protected function getReleaseType(string $version): string {
    if (str_contains($version, '-alpha')) {
      return 'alpha';
    }
    elseif (str_contains($version, '-beta')) {
      return 'beta';
    }
    elseif (str_contains(strtolower($version), '-rc')) {
      return 'rc';
    }
    return 'stable';
  }

  /**
   * Checks if a package version indicates it's abandoned.
   *
   * @param string $version
   *   The version string.
   *
   * @return bool
   *   TRUE if the package is abandoned, FALSE otherwise.
   */
  protected function isPackageAbandoned(string $version): bool {
    return str_contains(strtolower($version), 'abandoned');
  }

  /**
   * Formats a version string for DDQG ignore entry.
   *
   * @param string $version
   *   The version string (e.g., "2.0.0-alpha3").
   *
   * @return string
   *   The formatted version (e.g., "2.0.0.0-alpha3").
   */
  protected function formatVersionForDDQG(string $version): string {
    // DDQG format appears to use x.x.x.x format
    // If version has 3 parts (x.x.x), add a .0.
    $parts = explode('-', $version);
    $versionNumber = $parts[0];
    $suffix = isset($parts[1]) ? '-' . $parts[1] : '';

    $versionParts = explode('.', $versionNumber);
    if (count($versionParts) === 3) {
      $versionNumber .= '.0';
    }

    return $versionNumber . $suffix;
  }

  /**
   * Check if package has been updated.
   *
   * @param string $packageName
   *   Package name.
   * @param array $composerLockDiff
   *   Composer lock diff.
   *
   * @return bool
   *   TRUE when the package is updated.
   */
  protected function isPackageUpdated(string $packageName, array $composerLockDiff): bool {
    return !empty($this->getPackageUpdate($packageName, $composerLockDiff));
  }

  /**
   * Gets package update information.
   *
   * @param string $packageName
   *   Package name.
   * @param array $composerLockDiff
   *   Composer lock diff.
   *
   * @return array
   *   Data indicating what has been updated.
   */
  protected function getPackageUpdate(string $packageName, array $composerLockDiff): array {
    return $composerLockDiff['changes'][$packageName] ?? $composerLockDiff['changes-dev'][$packageName] ?? [];
  }

  /**
   * Runs a shell command.
   *
   * @param string $command
   *   Command.
   *
   * @throws \RuntimeException
   *   When the command fails.
   */
  protected function runCommand(string $command): void {
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(300);
    $process->run();
    if (!$process->isSuccessful()) {
      throw new \RuntimeException(sprintf('Error running "%s" command: %s', $command, $process->getErrorOutput()));
    }
  }

}
