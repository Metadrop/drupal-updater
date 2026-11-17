<?php

namespace DrupalUpdater\Tests\Simulator\Effect;

use DrupalUpdater\Tests\Simulator\Invocation;

/**
 * Writes a file of the project, to simulate a command that leaves it changed.
 */
final class WriteFile implements EffectInterface {

  public function __construct(
    private string $path,
    private string $contents,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function apply(Invocation $invocation): int {
    file_put_contents($invocation->path($this->path), $this->contents);
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return ['type' => 'write', 'path' => $this->path, 'contents' => $this->contents];
  }

}
