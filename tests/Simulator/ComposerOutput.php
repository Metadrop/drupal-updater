<?php

namespace DrupalUpdater\Tests\Simulator;

/**
 * Formats the simulated answers exactly as Composer 2 prints them.
 *
 * ComposerSimulatorContractTest compares these formats with a real Composer.
 */
final class ComposerOutput {

  /**
   * Formats "show --outdated --format json".
   *
   * @param list<array{name: string, version: string, latest: string, direct: bool}> $outdated
   *   Outdated packages, sorted by name.
   */
  public static function outdatedJson(array $outdated): string {
    $locked = array_map(fn (array $package) => [
      'name' => $package['name'],
      'direct-dependency' => $package['direct'],
      'homepage' => NULL,
      'source' => NULL,
      'version' => $package['version'],
      'release-age' => '',
      'release-date' => '',
      'latest' => $package['latest'],
      'latest-status' => self::latestStatus($package['version'], $package['latest']),
      'latest-release-date' => '',
      'description' => NULL,
      'abandoned' => FALSE,
    ], $outdated);
    return json_encode(['locked' => $locked], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
  }

  /**
   * Formats "show --outdated" as text: direct packages first, then the rest.
   *
   * @param list<array{name: string, version: string, latest: string, direct: bool}> $outdated
   *   Outdated packages, sorted by name.
   */
  public static function outdatedText(array $outdated): string {
    usort($outdated, fn (array $a, array $b) => [$b['direct'], $a['name']] <=> [$a['direct'], $b['name']]);
    $rows = array_map(fn (array $package) => [
      $package['name'],
      $package['version'],
      self::latestStatus($package['version'], $package['latest']) === 'semver-safe-update' ? '!' : '~',
      $package['latest'],
    ], $outdated);
    return self::table($rows);
  }

  /**
   * Formats a "--name-only" list.
   *
   * @param list<string> $names
   *   Package names.
   */
  public static function names(array $names): string {
    return implode('', array_map(fn (string $name) => $name . "\n", $names));
  }

  /**
   * Formats "show <package>".
   *
   * @param array{name: string, version: string, type: string, require: array<string, string>} $package
   *   Locked package.
   */
  public static function packageDetails(array $package): string {
    $lines = [
      'name     : ' . $package['name'],
      'descrip. : ',
      'keywords : ',
      'versions : * ' . $package['version'],
      'type     : ' . $package['type'],
      'homepage : ',
      'source   : []  ',
      'names    : ' . $package['name'],
    ];
    if ($package['require'] !== []) {
      $lines[] = '';
      $lines[] = 'requires';
      foreach ($package['require'] as $name => $constraint) {
        $lines[] = $name . ' ' . $constraint;
      }
    }
    return implode("\n", $lines) . "\n";
  }

  /**
   * Formats "why": who requires a package.
   *
   * @param list<array{name: string, version: string, target: string, constraint: string}> $links
   *   Requirement links.
   */
  public static function why(array $links): string {
    // Composer pads the description column too and ends each line with a
    // space: the empty last column reproduces that.
    $rows = array_map(fn (array $link) => [
      $link['name'],
      $link['version'],
      sprintf('requires %s (%s)', $link['target'], $link['constraint']),
      '',
    ], $links);
    return self::table($rows);
  }

  /**
   * Formats "audit --format plain".
   *
   * @param list<array{package: string, title: string, cve: ?string, severity: ?string, affected: string}> $advisories
   *   Advisories, grouped by package.
   */
  public static function auditPlain(array $advisories): string {
    if ($advisories === []) {
      return "No security vulnerability advisories found.\n";
    }
    $count = count($advisories);
    $packages = count(array_unique(array_column($advisories, 'package')));
    $lines = [sprintf(
      'Found %d security vulnerability advisor%s affecting %d package%s:',
      $count,
      $count === 1 ? 'y' : 'ies',
      $packages,
      $packages === 1 ? '' : 's',
      ),
    ];
    foreach ($advisories as $index => $advisory) {
      if ($index > 0) {
        $lines[] = '--------';
      }
      array_push(
        $lines,
        'Package: ' . $advisory['package'],
        'Severity: ' . ($advisory['severity'] ?? ''),
        'Advisory ID: SIMULATED-' . ($index + 1),
        'CVE: ' . ($advisory['cve'] ?? 'NO CVE'),
        'Title: ' . $advisory['title'],
        'URL: ',
        'Affected versions: ' . $advisory['affected'],
        'Reported at: 2026-01-01T00:00:00+00:00',
      );
    }
    return implode("\n", $lines) . "\n";
  }

  /**
   * Gets Composer's latest-status: a same-major update is semver-safe.
   */
  public static function latestStatus(string $version, string $latest): string {
    $current_major = preg_match('/^v?(\d+)\./', $version, $current) === 1 ? $current[1] : NULL;
    $latest_major = preg_match('/^v?(\d+)\./', $latest, $next) === 1 ? $next[1] : NULL;
    return $current_major !== NULL && $current_major === $latest_major ? 'semver-safe-update' : 'update-possible';
  }

  /**
   * Formats rows as space-separated columns padded to the widest cell.
   *
   * @param list<list<string>> $rows
   *   Rows; the last column is not padded.
   */
  private static function table(array $rows): string {
    if ($rows === []) {
      return '';
    }
    $widths = [];
    foreach ($rows as $row) {
      foreach (array_slice($row, 0, -1) as $column => $cell) {
        $widths[$column] = max($widths[$column] ?? 0, strlen($cell));
      }
    }
    $lines = array_map(function (array $row) use ($widths) {
      $last = array_pop($row);
      $cells = array_map(fn (string $cell, int $column) => str_pad($cell, $widths[$column]), $row, array_keys($row));
      return implode(' ', [...$cells, $last]);
    }, $rows);
    return implode("\n", $lines) . "\n";
  }

}
