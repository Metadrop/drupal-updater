<?php

namespace DrupalUpdater\Tests\Simulator\Effect;

use DrupalUpdater\Tests\Simulator\Filesystem;
use DrupalUpdater\Tests\Simulator\Invocation;

/**
 * Removes a file or directory of the project.
 */
final class RemovePath implements EffectInterface {

  public function __construct(
    private string $path,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function apply(Invocation $invocation): int {
    Filesystem::remove($invocation->path($this->path));
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return ['type' => 'remove', 'path' => $this->path];
  }

}
