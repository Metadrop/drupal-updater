<?php

namespace DrupalUpdater;

/**
 * Saves the working Composer files, including uncommitted requirements.
 *
 * It also remembers vendor/composer/installed.json, which Composer rewrites
 * after every package it installs or removes, to tell whether a failed
 * update changed the vendor folder.
 */
class ComposerState {

  /**
   * Saved file contents, keyed by path.
   *
   * @var array<string, string>
   */
  protected array $files = [];

  /**
   * Hash of vendor/composer/installed.json, NULL when there is none.
   */
  protected ?string $installed;

  /**
   * Saves the Composer files of the given directory.
   *
   * @param string $directory
   *   Directory holding composer.json and composer.lock.
   *
   * @throws \RuntimeException
   *   When a file cannot be read.
   */
  public function __construct(protected string $directory = '.') {
    $this->installed = $this->installedHash();
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
   * Whether the vendor folder changed since the state was saved.
   */
  public function isVendorChanged(): bool {
    return $this->installedHash() !== $this->installed;
  }

  /**
   * Gets the hash of vendor/composer/installed.json, NULL when there is none.
   */
  protected function installedHash(): ?string {
    $path = $this->directory . '/vendor/composer/installed.json';
    $hash = is_file($path) ? sha1_file($path) : FALSE;
    return $hash === FALSE ? NULL : $hash;
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
