<?php

namespace DrupalUpdater\Tests\Simulator\Effect;

use DrupalUpdater\Tests\Simulator\ComposerFiles;
use DrupalUpdater\Tests\Simulator\Invocation;

/**
 * Changes the locked version of some packages in composer.lock.
 */
final class SetLockVersions implements EffectInterface {

  /**
   * Creates the effect.
   *
   * @param array<string, string|null> $versions
   *   New versions keyed by package name; NULL means the package latest.
   */
  public function __construct(
    private array $versions,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function apply(Invocation $invocation): int {
    $packages = $invocation->state()->packages();
    $versions = [];
    foreach ($this->versions as $name => $version) {
      $versions[$name] = $version ?? $packages[$name]->latest() ?? $packages[$name]->version();
    }
    (new ComposerFiles($invocation->cwd()))->setLockedVersions($versions);
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return ['type' => 'lock', 'versions' => $this->versions];
  }

}
