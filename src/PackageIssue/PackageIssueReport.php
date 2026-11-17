<?php

namespace DrupalUpdater\PackageIssue;

/**
 * The package issues of one run, printed at its end.
 */
final class PackageIssueReport {

  /**
   * Issues in the order they happened.
   *
   * @var list<\DrupalUpdater\PackageIssue\PackageIssue>
   */
  private array $issues = [];

  /**
   * Records an issue.
   */
  public function record(PackageIssue $issue): void {
    $this->issues[] = $issue;
  }

  /**
   * Resolves the open issues of the packages a successful update changed.
   *
   * @param list<string> $changed_packages
   *   Packages composer-lock-diff reports as changed by the update.
   * @param array<string, array<int, string|null>> $fingerprints
   *   Lock fingerprints after the update, keyed by package name.
   */
  public function resolveChanged(array $changed_packages, array $fingerprints): void {
    foreach ($this->issues as $issue) {
      if (!$issue->isResolved() && in_array($issue->package(), $changed_packages, TRUE)) {
        $issue->resolveIfChanged($fingerprints);
      }
    }
  }

  /**
   * Whether no issue was recorded.
   */
  public function isEmpty(): bool {
    return $this->issues === [];
  }

  /**
   * Gets one report line per issue.
   *
   * @return list<string>
   *   Lines.
   */
  public function lines(): array {
    return array_map(fn (PackageIssue $issue) => $issue->line(), $this->issues);
  }

}
