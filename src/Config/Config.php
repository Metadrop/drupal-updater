<?php

namespace DrupalUpdater\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * Configuration extracted from .drupal-updater.yml file.
 */
class Config implements ConfigInterface {

  /**
   * Commits author.
   *
   * @var string
   */
  protected string $author = self::DEFAULT_COMMIT_AUTHOR;

  /**
   * List of environments to update.
   *
   * @var array|string[]
   */
  protected array $environments = ['@self'];

  /**
   * Set to true to only update securities.
   *
   * @var bool
   */
  protected bool $onlySecurities = false;

  /**
   * Set to true to only update production packages.
   *
   * @var bool
   */
  protected bool $noDev = false;

  /**
   * Limit of updates.
   *
   * @var int
   */
  protected ?int $limit = null;

  /**
   * Set to true to consolidate configuration.
   *
   * Used to allow not consolidating conrfiguration in special cases.
   *
   * @var bool
   */
  protected bool $consolidateConfiguration = true;

  /**
   * If set, only this packages will be updated, along its dependencies.
   *
   * @var array
   */
  protected array $packages = [];

  /**
   * {@inheritdoc}
   */
  public function setAuthor(string $author) {
    $this->author = $author;
  }

  /**
   * {@inheritdoc}
   */
  public function getAuthor(): string {
    return $this->author;
  }

  /**
   * {@inheritdoc}
   */
  public function setEnvironments(array $environments) {
    $this->environments = $environments;
  }

  /**
   * {@inheritdoc}
   */
  public function getEnvironments(): array {
    return $this->environments;
  }

  /**
   * {@inheritdoc}
   */
  public function setLimit(int $limit) : void {
    if ($limit <= 0) {
      throw new \InvalidArgumentException('Invalid limit ' . $limit . '. It must be integer positive');
    }

    $this->limit = $limit;
  }

  /**
   * {@inheritdoc}
   */
  public function getLimit() : ?int {
    return $this->limit;
  }

  /**
   * {@inheritdoc}
   */
  public function setOnlySecurities(bool $onlySecurities) {
    $this->onlySecurities = $onlySecurities;
  }

  /**
   * {@inheritdoc}
   */
  public function onlyUpdateSecurities(): bool {
    return $this->onlySecurities;
  }

  /**
   * {@inheritdoc}
   */
  public function setNoDev(bool $noDev) {
    $this->noDev = $noDev;
  }

  /**
   * {@inheritdoc}
   */
  public function noDev(): bool {
    return $this->noDev;
  }

  /**
   * {@inheritdoc}
   */
  public function setPackages(array $packages) {
    $this->packages = $packages;
  }

  /**
   * {@inheritdoc}
   */
  public function getPackages(): array {
    return $this->packages;
  }

  /**
   * {@inheritdoc}
   */
  public function setConsolidateConfiguration(bool $consolidate) {
    $this->consolidateConfiguration = $consolidate;
  }

  /**
   * {@inheritdoc}
   */
  public function getConsolidateConfiguration(): bool {
    return $this->consolidateConfiguration;
  }

  /**
   * Creates a configuration isntance given a YAML configuration file.
   *
   * @param string $config_file
   *   Configuration file.
   *
   * @return self
   *   Configuration ready to use.
   */
  public static function createFromConfigurationFile(string $config_file) {

    $instance = new static();
    $configuration = Yaml::parseFile($config_file);

    $string_fields = [
      'author',
    ];

    foreach ($string_fields as $string_field) {
      if (isset($configuration[$string_field]) && !is_string($configuration[$string_field])) {
        throw new \InvalidArgumentException(sprintf('"%s" configuration key must be a string, %s given', $string_field, gettype($configuration[$string_field])));
      }
      elseif (!empty($configuration[$string_field])) {
        $instance->{$string_field} = $configuration[$string_field];
      }
    }

    $integer_fields = [
      'limit',
    ];

    foreach ($integer_fields as $integer_field) {
      if (isset($configuration[$integer_field]) && ($configuration[$integer_field] <= 0 || !is_int($configuration[$integer_field]))) {
        throw new \InvalidArgumentException(sprintf('"%s" configuration key must be positive integer, %s given', $integer_field, gettype($configuration[$integer_field])));
      }
      else {
        $instance->{$integer_field} = $configuration[$integer_field];
      }
    }

    $boolean_fields = [
      'onlySecurities',
      'noDev',
      'consolidateConfiguration',
    ];

    foreach ($boolean_fields as $boolean_field) {
      if (isset($configuration[$boolean_field]) && !is_bool($configuration[$boolean_field])) {
        throw new \InvalidArgumentException(sprintf('"%s" config key must be a boolean, %s given!', $boolean_field, gettype($configuration[$boolean_field])));
      }
      elseif (isset($configuration[$boolean_field])) {
        $instance->{$boolean_field} = $configuration[$boolean_field];
      }
    }

    $array_fields = [
      'environments',
      'packages',
    ];

    foreach ($array_fields as $array_field) {
      if (isset($configuration[$array_field]) && !is_array($configuration[$array_field])) {
        throw new \InvalidArgumentException(sprintf('"%s" config key must be an array, %s given!', $array_field, gettype($configuration[$array_field])));
      }
      elseif (!empty($configuration[$array_field])) {
        $instance->{$array_field} = $configuration[$array_field];
      }
    }

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function validate() : void {
    if (!empty($this->packages) && !empty($this->limit)) {
      throw new \InvalidArgumentException("The parameter packages and limit can't be used toguether.");
    }
  }

}
