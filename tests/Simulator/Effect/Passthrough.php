<?php

namespace DrupalUpdater\Tests\Simulator\Effect;

use DrupalUpdater\Tests\Simulator\Invocation;

/**
 * Runs the real tool with the same arguments.
 */
final class Passthrough implements EffectInterface {

  /**
   * {@inheritdoc}
   */
  public function apply(Invocation $invocation): int {
    $binary = $invocation->state()->realBinary($invocation->tool());
    if ($binary === NULL) {
      $invocation->err(sprintf('[simulator] No real %s binary is configured for passthrough.', $invocation->tool()));
      return 91;
    }
    $process = proc_open(
      array_merge([$binary], $invocation->arguments()),
      [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
      $invocation->cwd(),
    );
    if (!is_resource($process)) {
      return 91;
    }
    $invocation->out((string) stream_get_contents($pipes[1]));
    $invocation->err((string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($process);
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return ['type' => 'passthrough'];
  }

}
