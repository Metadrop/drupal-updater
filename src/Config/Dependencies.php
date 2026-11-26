<?php

namespace DrupalUpdater\Config;

/**
 * Dependencies updated along with each package.
 */
enum Dependencies: string {

  case All = 'all';
  case Transitive = 'transitive';
  case None = 'none';

  /**
   * Gets the "composer update" option that applies this level.
   *
   * @return string
   *   Composer option, empty when only the package is updated.
   */
  public function composerOption(): string {
    return match ($this) {
      self::All => '--with-all-dependencies',
      self::Transitive => '--with-dependencies',
      self::None => '',
    };
  }

}
