<?php

namespace DrupalUpdater\Tests\Simulator;

/**
 * What a test declares: the project packages and the scripted tool answers.
 */
final class Scenario {

  public const COMPOSER = 'composer';

  public const DRUSH = 'drush';

  public const LOCK_DIFF = 'composer-lock-diff';

  /**
   * Packages keyed by name.
   *
   * @var array<string, \DrupalUpdater\Tests\Simulator\Package>
   */
  private array $packages = [];

  /**
   * Rule sets keyed by tool.
   *
   * @var array<string, \DrupalUpdater\Tests\Simulator\RuleSet>
   */
  private array $ruleSets;

  /**
   * Paths of the real tools, keyed by tool, used by passthrough rules.
   *
   * @var array<string, string>
   */
  private array $realBinaries = [];

  /**
   * Creates an empty scenario.
   */
  public function __construct() {
    $this->ruleSets = [
      self::COMPOSER => new RuleSet(),
      self::DRUSH => new RuleSet(),
      self::LOCK_DIFF => new RuleSet(),
    ];
  }

  /**
   * Restores a scenario from its array form.
   *
   * @param array<string, mixed> $data
   *   Data produced by toArray().
   */
  public static function fromArray(array $data): self {
    $scenario = new self();
    foreach ($data['packages'] as $package) {
      $scenario->package(Package::fromArray($package));
    }
    $scenario->ruleSets = array_map([RuleSet::class, 'fromArray'], $data['ruleSets']);
    $scenario->realBinaries = $data['realBinaries'];
    return $scenario;
  }

  /**
   * Adds a package to the project.
   */
  public function package(Package $package): self {
    $this->packages[$package->name()] = $package;
    return $this;
  }

  /**
   * Gets the packages keyed by name.
   *
   * @return array<string, \DrupalUpdater\Tests\Simulator\Package>
   *   Packages.
   */
  public function packages(): array {
    return $this->packages;
  }

  /**
   * Gets the scripted Composer answers.
   */
  public function composer(): RuleSet {
    return $this->ruleSets[self::COMPOSER];
  }

  /**
   * Gets the scripted Drush answers.
   */
  public function drush(): RuleSet {
    return $this->ruleSets[self::DRUSH];
  }

  /**
   * Gets the scripted composer-lock-diff answers.
   */
  public function lockDiff(): RuleSet {
    return $this->ruleSets[self::LOCK_DIFF];
  }

  /**
   * Gets the rule set of a tool.
   */
  public function ruleSet(string $tool): RuleSet {
    return $this->ruleSets[$tool];
  }

  /**
   * Sets the path of the real tool, used by passthrough rules.
   */
  public function setRealBinary(string $tool, string $path): self {
    $this->realBinaries[$tool] = $path;
    return $this;
  }

  /**
   * Gets the path of the real tool, NULL when unknown.
   */
  public function realBinary(string $tool): ?string {
    return $this->realBinaries[$tool] ?? NULL;
  }

  /**
   * Gets the array form, stored in the scenario file.
   *
   * @return array<string, mixed>
   *   Scenario data.
   */
  public function toArray(): array {
    return [
      'packages' => array_values(array_map(fn (Package $package) => $package->toArray(), $this->packages)),
      'ruleSets' => array_map(fn (RuleSet $set) => $set->toArray(), $this->ruleSets),
      'realBinaries' => $this->realBinaries,
    ];
  }

}
