<?php

namespace DrupalUpdater\Tests\Functional;

use DrupalUpdater\Tests\Simulator\Filesystem;
use DrupalUpdater\Tests\Simulator\Scenario;
use DrupalUpdater\Tests\Simulator\SimulatorState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Base for tests that run the real updater CLI against a fixture project.
 *
 * The test declares a scenario (packages and scripted answers), then runs
 * the updater. Composer and Drush are simulated through tests/bin, which goes
 * first in PATH; Git and composer-lock-diff are the real ones. See
 * tests/README.md.
 */
abstract class UpdaterTestCase extends TestCase {

  /**
   * Temporary directory holding the project, the state and the settings.
   */
  private string $root;

  /**
   * Scenario of the test.
   */
  private ?Scenario $scenario = NULL;

  /**
   * Fixture project, created from the scenario on first use.
   */
  private ?FixtureProject $project = NULL;

  /**
   * Whether the project is installed by the real Composer.
   */
  private bool $realComposer = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->root = sys_get_temp_dir() . '/drupal-updater-test-' . bin2hex(random_bytes(6));
    foreach (['project', 'simulator', 'composer-home'] as $directory) {
      mkdir($this->root . '/' . $directory, 0777, TRUE);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (getenv('DRUPAL_UPDATER_KEEP_FIXTURES') === FALSE) {
      Filesystem::remove($this->root);
    }
    parent::tearDown();
  }

  /**
   * Gets the scenario, to declare packages and scripted answers.
   */
  protected function scenario(): Scenario {
    if ($this->scenario === NULL) {
      $this->scenario = new Scenario();
      $this->scenario->setRealBinary(Scenario::LOCK_DIFF, dirname(__DIR__, 2) . '/vendor/bin/composer-lock-diff');
      $composer = (new ExecutableFinder())->find('composer');
      if ($composer !== NULL) {
        $this->scenario->setRealBinary(Scenario::COMPOSER, $composer);
      }
    }
    return $this->scenario;
  }

  /**
   * Runs the real Composer for every call no rule matches.
   *
   * The project is then installed by the real Composer too.
   */
  protected function useRealComposer(): void {
    $this->installWithRealComposer();
    $this->scenario()->composer()->delegateToRealTool();
  }

  /**
   * Installs the project with the real Composer; calls are still simulated.
   */
  protected function installWithRealComposer(): void {
    if ($this->scenario()->realBinary(Scenario::COMPOSER) === NULL) {
      $this->markTestSkipped('Composer is not available in PATH.');
    }
    $this->realComposer = TRUE;
  }

  /**
   * Gets the fixture project, creating it from the scenario on first use.
   *
   * Packages added to the scenario after this call are not in the project.
   */
  protected function project(): FixtureProject {
    if ($this->project === NULL) {
      $this->project = $this->realComposer
        ? new RealComposerProject($this->root . '/project', $this->scenario(), (string) $this->scenario()->realBinary(Scenario::COMPOSER), $this->composerEnvironment())
        : new FixtureProject($this->root . '/project', $this->scenario());
      $this->project->create();
    }
    return $this->project;
  }

  /**
   * Runs the updater CLI.
   *
   * @param array<string, mixed> $settings
   *   Settings of .drupal-updater.yml; configuration consolidation is off
   *   unless set.
   * @param list<string> $options
   *   Extra command line options, e.g. "--security".
   */
  protected function runUpdater(array $settings = [], array $options = []): UpdaterRun {
    $project = $this->project();
    $state = $this->state();
    $state->save($this->scenario());
    $settings_file = $this->root . '/drupal-updater.yml';
    file_put_contents($settings_file, Yaml::dump($settings + ['consolidateConfiguration' => FALSE]));

    $command = [
      PHP_BINARY,
      dirname(__DIR__, 2) . '/drupal-updater',
      'update',
      '--config',
      $settings_file,
      '--no-interaction',
      ...$options,
    ];
    $process = new Process(
      $command,
      $project->path(),
      $this->simulatedEnvironment(),
    );
    $process->setTimeout(120);
    $process->run();

    self::assertSame([], $state->unsupportedCalls(), 'The updater ran commands the simulator does not support.');
    return new UpdaterRun($process, $state);
  }

  /**
   * Gets the environment in which composer and drush are the simulated ones.
   *
   * @return array<string, string|false>
   *   Environment variables; FALSE removes an inherited one.
   */
  protected function simulatedEnvironment(): array {
    return [
      'PATH' => dirname(__DIR__) . '/bin' . PATH_SEPARATOR . getenv('PATH'),
      'PWD' => $this->project()->path(),
      // Wide enough for the console not to wrap error messages.
      'COLUMNS' => '1000',
      // "composer test" exports it, and the updater must be the one setting it.
      'COMPOSER_PROCESS_TIMEOUT' => FALSE,
      SimulatorState::ENVIRONMENT_VARIABLE => $this->state()->directory(),
    ] + $this->composerEnvironment();
  }

  /**
   * Gets the simulator state of the test.
   */
  protected function state(): SimulatorState {
    return new SimulatorState($this->root . '/simulator');
  }

  /**
   * Gets the environment that isolates the real Composer from the user's.
   *
   * @return array<string, string>
   *   Environment variables.
   */
  protected function composerEnvironment(): array {
    return [
      'COMPOSER_HOME' => $this->root . '/composer-home',
      'COMPOSER_CACHE_DIR' => $this->root . '/composer-home/cache',
      'COMPOSER_DISABLE_NETWORK' => '1',
      'COMPOSER_NO_AUDIT' => '1',
    ];
  }

  /**
   * Asserts the version of a package in composer.lock.
   */
  protected function assertLockedVersion(string $name, ?string $version): void {
    self::assertSame($version, $this->project()->lockedVersion($name), sprintf('Locked version of %s.', $name));
  }

  /**
   * Asserts the subjects of the commits made by the updater, oldest first.
   *
   * @param list<string> $subjects
   *   Expected subjects.
   */
  protected function assertCommitSubjects(array $subjects): void {
    self::assertSame($subjects, $this->project()->commitSubjects());
  }

}
