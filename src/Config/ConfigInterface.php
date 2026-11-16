<?php

namespace DrupalUpdater\Config;

/**
 * Configuration of the updater.
 */
interface ConfigInterface {

  const DEFAULT_COMMIT_AUTHOR = 'Drupal <drupal@update-helper>';

  /**
   * Get the commits author.
   *
   * @return string
   *   Commits author.
   */
  public function getAuthor() : string;

  /**
   * Sets the commits author.
   *
   * @param string $author
   *   Commits author.
   */
  public function setAuthor(string $author): void;

  /**
   * Gets the environment list.
   *
   * @return string[]
   *   List of environments.
   */
  public function getEnvironments() : array;

  /**
   * Sets the environment list.
   *
   * @param string[] $environments
   *   Environments.
   */
  public function setEnvironments(array $environments): void;

  /**
   * Indicates if only securities should be updated.
   *
   * @return bool
   *   True when only securities will e updated.
   */
  public function onlyUpdateSecurities() : bool;

  /**
   * Setup securities update.
   *
   * @param bool $only_update_securities
   *   If true, only securities will be updated.
   */
  public function setOnlySecurities(bool $only_update_securities): void;

  /**
   * Indicates whether not update dev packages.
   *
   * @return bool
   *   True when dev packages must not be updated.
   */
  public function noDev() : bool;

  /**
   * Setup to not update development packages.
   *
   * @param bool $no_dev
   *   If true, development packages won't be updated.
   */
  public function setNoDev(bool $no_dev): void;

  /**
   * Gets the list of packages to update.
   *
   * @return string[]
   *   List of packages to update.
   */
  public function getPackages() : array;

  /**
   * Set the list of packages to update.
   *
   * @param string[] $packages
   *   Packages list.
   */
  public function setPackages(array $packages): void;

  /**
   * Set if configuration should be or not procesed.
   *
   * @param bool $consolidate
   *   Set to true to import / export configuraton.
   */
  public function setConsolidateConfiguration(bool $consolidate): void;

  /**
   * Get if configuration must be exported or imported.
   *
   * @return bool
   *   TRUE when configuration must be managed.
   */
  public function getConsolidateConfiguration();

  /**
   * Get the maximum number of direct packages to update.
   */
  public function setLimit(int $limit) : void;

  /**
   * Sets the maximum number of direct packages to update.
   */
  public function getLimit() : ?int;

}
