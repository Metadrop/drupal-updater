<?php

namespace DrupalUpdater;

/**
 * Saves the working Composer files, including uncommitted requirements.
 */
class ComposerState {

  /**
   * Saved file contents, keyed by path.
   *
   * @var array<string, string>
   */
  protected array $files = [];

  /**
   * Saves the Composer files of the given directory.
   *
   * @param string $directory
   *   Directory holding composer.json and composer.lock.
   *
   * @throws \RuntimeException
   *   When a file cannot be read.
   */
  public function __construct(string $directory = '.') {
    foreach (['composer.json', 'composer.lock'] as $name) {
      $path = $directory . '/' . $name;
      if (!is_file($path) || !is_readable($path)) {
        throw new \RuntimeException('Cannot snapshot ' . $path);
      }
      $contents = file_get_contents($path);
      if ($contents === FALSE) {
        throw new \RuntimeException('Cannot read ' . $path);
      }
      $this->files[$path] = $contents;
    }
  }

  /**
   * Writes the saved Composer files back.
   */
  public function restore(): void {
    foreach ($this->files as $path => $contents) {
      if (file_put_contents($path, $contents) !== strlen($contents)) {
        throw new \RuntimeException('Cannot restore ' . $path);
      }
    }
  }

}
