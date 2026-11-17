<?php

namespace DrupalUpdater\Tests\Simulator\Effect;

/**
 * Restores effects from the array form stored in the scenario file.
 */
final class EffectFactory {

  /**
   * Creates the effect described by its array form.
   *
   * @param array<string, mixed> $data
   *   Data produced by EffectInterface::toArray().
   */
  public static function fromArray(array $data): EffectInterface {
    return match ($data['type']) {
      'output' => new Output($data['stdout'], $data['stderr']),
      'fail' => new Fail($data['stderr'], $data['exitCode']),
      'sleep' => new Sleep($data['seconds']),
      'write' => new WriteFile($data['path'], $data['contents']),
      'remove' => new RemovePath($data['path']),
      'lock' => new SetLockVersions($data['versions']),
      'passthrough' => new Passthrough(),
      default => throw new \InvalidArgumentException(sprintf('Unknown simulator effect "%s".', $data['type'])),
    };
  }

}
