<?php

namespace DrupalUpdater\Tests\Simulator;

/**
 * A package of the simulated project, as the test declares it.
 *
 * The project files (composer.json and composer.lock) are generated from these
 * packages; the latest version and the advisories only exist here, as they
 * would come from the package repositories in a real project.
 */
final class Package {

  /**
   * Version available in the repositories, NULL when it is up to date.
   */
  private ?string $latest = NULL;

  /**
   * Composer package type.
   */
  private string $type = 'library';

  /**
   * Whether the root composer.json requires the package.
   */
  private bool $direct = TRUE;

  /**
   * Whether the package is a development requirement.
   */
  private bool $dev = FALSE;

  /**
   * Packages this package requires, keyed by name, with their constraint.
   *
   * @var array<string, string>
   */
  private array $requires = [];

  /**
   * Security advisories affecting the locked version.
   *
   * @var list<array{title: string, cve: ?string, severity: ?string}>
   */
  private array $advisories = [];

  /**
   * Executables the package installs in vendor/bin, with the real Composer.
   *
   * @var list<string>
   */
  private array $binaries = [];

  /**
   * Creates a package locked at the given version.
   */
  private function __construct(
    private string $name,
    private string $version,
  ) {}

  /**
   * Creates a package locked at the given version.
   *
   * @param string $name
   *   Package name, vendor/project.
   * @param string $version
   *   Locked version.
   */
  public static function named(string $name, string $version): self {
    return new self($name, $version);
  }

  /**
   * Restores a package from its array form.
   *
   * @param array<string, mixed> $data
   *   Data produced by toArray().
   */
  public static function fromArray(array $data): self {
    $package = new self($data['name'], $data['version']);
    $package->latest = $data['latest'];
    $package->type = $data['type'];
    $package->direct = $data['direct'];
    $package->dev = $data['dev'];
    $package->requires = $data['requires'];
    $package->advisories = $data['advisories'];
    $package->binaries = $data['binaries'];
    return $package;
  }

  /**
   * Makes a newer version available.
   */
  public function withLatest(string $latest): self {
    $this->latest = $latest;
    return $this;
  }

  /**
   * Sets the Composer package type.
   */
  public function ofType(string $type): self {
    $this->type = $type;
    return $this;
  }

  /**
   * Marks the package as a Drupal module.
   */
  public function asDrupalModule(): self {
    return $this->ofType('drupal-module');
  }

  /**
   * Marks the package as a transitive dependency.
   */
  public function asTransitive(): self {
    $this->direct = FALSE;
    return $this;
  }

  /**
   * Marks the package as a development requirement.
   */
  public function asDev(): self {
    $this->dev = TRUE;
    return $this;
  }

  /**
   * Declares a requirement on another package.
   */
  public function requires(string $name, string $constraint = '^1.0'): self {
    $this->requires[$name] = $constraint;
    return $this;
  }

  /**
   * Declares a security advisory affecting the locked version.
   */
  public function withAdvisory(string $title, ?string $cve = NULL, ?string $severity = 'high'): self {
    $this->advisories[] = [
      'title' => $title,
      'cve' => $cve,
      'severity' => $severity,
    ];
    return $this;
  }

  /**
   * Declares an executable the real Composer installs in vendor/bin.
   */
  public function withBinary(string $name): self {
    $this->binaries[] = $name;
    return $this;
  }

  /**
   * Gets the executables the package installs in vendor/bin.
   *
   * @return list<string>
   *   Executable names.
   */
  public function binaries(): array {
    return $this->binaries;
  }

  /**
   * Gets the package name.
   */
  public function name(): string {
    return $this->name;
  }

  /**
   * Gets the locked version.
   */
  public function version(): string {
    return $this->version;
  }

  /**
   * Gets the newest available version, NULL when up to date.
   */
  public function latest(): ?string {
    return $this->latest;
  }

  /**
   * Gets the Composer package type.
   */
  public function type(): string {
    return $this->type;
  }

  /**
   * Whether the root composer.json requires the package.
   */
  public function isDirect(): bool {
    return $this->direct;
  }

  /**
   * Whether the package is a development requirement.
   */
  public function isDev(): bool {
    return $this->dev;
  }

  /**
   * Gets the requirements, keyed by package name.
   *
   * @return array<string, string>
   *   Constraints keyed by package name.
   */
  public function requirements(): array {
    return $this->requires;
  }

  /**
   * Gets the advisories affecting the locked version.
   *
   * @return list<array{title: string, cve: ?string, severity: ?string}>
   *   Advisories.
   */
  public function advisories(): array {
    return $this->advisories;
  }

  /**
   * Gets the constraint the root composer.json uses for the package.
   */
  public function rootConstraint(): string {
    return '^' . $this->version;
  }

  /**
   * Gets the array form, used to share the package with the simulator.
   *
   * @return array<string, mixed>
   *   Package data.
   */
  public function toArray(): array {
    return [
      'name' => $this->name,
      'version' => $this->version,
      'latest' => $this->latest,
      'type' => $this->type,
      'direct' => $this->direct,
      'dev' => $this->dev,
      'requires' => $this->requires,
      'advisories' => $this->advisories,
      'binaries' => $this->binaries,
    ];
  }

}
