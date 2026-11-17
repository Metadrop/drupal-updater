<?php

namespace DrupalUpdater\PackageIssue;

/**
 * A package that could not be updated, kept for the final report.
 */
final class PackageIssue {

  public const COMPOSER_UPDATE = 'composer-update';

  public const DRUSH_UPDATE = 'drush-update';

  public const EXECUTION = 'execution';

  public const CONSTRAINT = 'constraint';

  /**
   * Composer errors that mean the update is blocked by constraints.
   */
  private const CONSTRAINT_ERRORS = '/Your requirements could not be resolved to an installable set of packages|conflicts with your root composer.json require/i';

  /**
   * Whether a later successful update changed the package.
   */
  private bool $resolved = FALSE;

  /**
   * Creates the issue.
   *
   * @param string $package
   *   Package name.
   * @param string $operation
   *   Failed operation: COMPOSER_UPDATE or DRUSH_UPDATE.
   * @param string $kind
   *   Issue kind: EXECUTION or CONSTRAINT.
   * @param string $reason
   *   Failure reason.
   * @param array<int, string|null>|null $before
   *   Lock fingerprint of the package before the update, NULL if not locked.
   */
  public function __construct(
    private string $package,
    private string $operation,
    private string $kind,
    private string $reason,
    private ?array $before,
  ) {}

  /**
   * Creates the issue of a failed "composer update".
   *
   * @param string $package
   *   Package name.
   * @param string $reason
   *   Composer error output.
   * @param array<int, string|null>|null $before
   *   Lock fingerprint of the package before the update.
   */
  public static function composerFailure(string $package, string $reason, ?array $before): self {
    $kind = preg_match(self::CONSTRAINT_ERRORS, $reason) === 1 ? self::CONSTRAINT : self::EXECUTION;
    return new self($package, self::COMPOSER_UPDATE, $kind, $reason, $before);
  }

  /**
   * Creates the issue of an update that left the lock file unchanged.
   *
   * @param string $package
   *   Package name.
   * @param string $latest
   *   Available version that was not installed.
   * @param array<int, string|null>|null $before
   *   Lock fingerprint of the package before the update.
   */
  public static function unchangedLock(string $package, string $latest, ?array $before): self {
    $reason = 'No compatible change was produced by Composer; available version: ' . $latest;
    return new self($package, self::COMPOSER_UPDATE, self::CONSTRAINT, $reason, $before);
  }

  /**
   * Creates the issue of a failed Drush step after updating a package.
   *
   * @param string $package
   *   Package name.
   * @param string $reason
   *   Drush error output.
   * @param array<int, string|null>|null $before
   *   Lock fingerprint of the package before the update.
   */
  public static function drushFailure(string $package, string $reason, ?array $before): self {
    return new self($package, self::DRUSH_UPDATE, self::EXECUTION, $reason, $before);
  }

  /**
   * Marks a Composer issue resolved when a later update changed the package.
   *
   * Drush issues stay open: a new version does not run the database updates
   * that failed.
   *
   * @param array<string, array<int, string|null>> $fingerprints
   *   Lock fingerprints after the update, keyed by package name.
   */
  public function resolveIfChanged(array $fingerprints): void {
    if ($this->operation !== self::COMPOSER_UPDATE || !isset($fingerprints[$this->package])) {
      return;
    }
    if ($fingerprints[$this->package] !== $this->before) {
      $this->resolved = TRUE;
    }
  }

  /**
   * Gets the package name.
   */
  public function package(): string {
    return $this->package;
  }

  /**
   * Whether a later successful update changed the package.
   */
  public function isResolved(): bool {
    return $this->resolved;
  }

  /**
   * Gets the report line.
   */
  public function line(): string {
    if ($this->resolved) {
      return sprintf('[resolved] %s / %s: %s (Resolved by a later successful package update.)', $this->package, $this->operation, $this->reason);
    }
    return sprintf('[%s] %s / %s: %s', $this->kind, $this->package, $this->operation, $this->reason);
  }

}
