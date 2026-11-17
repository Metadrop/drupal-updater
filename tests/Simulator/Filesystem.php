<?php

namespace DrupalUpdater\Tests\Simulator;

/**
 * Filesystem helpers shared by the simulator and the test fixtures.
 */
final class Filesystem {

  /**
   * Removes a file or directory recursively; missing paths are ignored.
   */
  public static function remove(string $path): void {
    if (is_link($path) || is_file($path)) {
      unlink($path);
      return;
    }
    if (!is_dir($path)) {
      return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $child) {
      self::remove($path . '/' . $child);
    }
    rmdir($path);
  }

  /**
   * Writes a value as pretty JSON, creating the parent directory.
   */
  public static function writeJson(string $path, mixed $value): void {
    if (!is_dir(dirname($path))) {
      mkdir(dirname($path), 0777, TRUE);
    }
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
  }

}
