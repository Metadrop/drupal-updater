# Tests

```bash
composer test                                   # everything
vendor/bin/phpunit tests/Unit                   # fast, no processes
vendor/bin/phpunit --filter UpdatePackagesTest  # one class
DRUPAL_UPDATER_KEEP_FIXTURES=1 vendor/bin/phpunit --filter testName  # keep the temporary project
```

Requirements: PHP, Git, a POSIX shell with `timeout`, and Composer 2 in `PATH` for the tests that use the
real Composer (they are skipped without it). No network, Drupal site or database is needed.

## Layout

| Path | What it is |
| --- | --- |
| `Functional/` | Tests that run the real `drupal-updater` CLI against a temporary project. |
| `Functional/UpdaterTestCase.php` | Base class of those tests. |
| `Simulator/` | The Composer and Drush simulator used by the functional tests. |
| `bin/` | `composer`, `drush` and `composer-lock-diff` executables that run the simulator. |
| `Unit/` | Plain unit tests, including the simulator's own. |

## How a functional test works

The updater drives a site by shelling out to `composer`, `drush`, `git` and `composer-lock-diff`.
`UpdaterTestCase::runUpdater()` runs the real CLI in a temporary Git project with `tests/bin` first in
`PATH`, so:

- **Composer** is simulated. Its answers are derived from the project, like the real one: current
  versions from `composer.lock`, direct requirements from `composer.json`, and available versions and
  advisories from the packages the test declares. `update` locks the requested packages (and their
  requirements with `-w`) at their latest version. There is no dependency resolution.
- **Drush** is simulated: every command the updater uses succeeds, and there is no Drupal site.
- **Git** and **composer-lock-diff** are the real ones.

A test declares a scenario, runs the updater and checks the result:

```php
final class ExampleTest extends UpdaterTestCase {

  public function testFailedUpdateIsReverted(): void {
    $this->scenario()
      ->package(Package::named('example/ok', '1.0.0')->withLatest('1.1.0'))
      ->package(Package::named('example/fail', '1.0.0')->withLatest('1.1.0'));
    $this->scenario()->composer()->on('update', 'example/fail')->fails('curl error 28: network outage');

    $run = $this->runUpdater()->assertSuccessful();

    $this->assertLockedVersion('example/ok', '1.1.0');
    $this->assertLockedVersion('example/fail', '1.0.0');
    $this->assertCommitSubjects(['UPDATE - example/ok: package (1.0.0 -> 1.1.0)']);
    $run->assertCalled(Scenario::COMPOSER, 'update', 'example/fail');
  }

}
```

### Packages

`Package::named($name, $version)` is a direct, non-dev library locked at `$version`. Then:
`withLatest()` makes a newer version available, `asDrupalModule()` / `ofType()` set the type,
`asTransitive()` and `asDev()` change how it is required, `requires()` adds a requirement and
`withAdvisory()` makes `composer audit` report it until it is updated to its latest version.

### Rules: scripted answers

The simulated defaults cover the normal flow. Anything else — failures, timeouts, odd output, files
left changed — is a rule on `scenario()->composer()`, `drush()` or `lockDiff()`:

```php
$this->scenario()->composer()->on('update', 'example/a')->sleeps(2);
$this->scenario()->composer()->on('show', '--outdated')->outputs(" \t\n");
$this->scenario()->drush()->on('updb')->fails('deliberate Drupal failure');
$this->scenario()->lockDiff()->on('--from')->fails('deliberate report failure');
```

- `on(...$tokens)` matches a call that contains all the tokens, in any order. The first matching rule
  answers; `once()` makes a rule answer only its first call.
- Effects run in order and the first failing one ends the call: `outputs()`, `fails($stderr, $exit)`,
  `sleeps()`, `writes($path, $contents)`, `removes($path)`, `bumps(...$names)` (lock them at their
  latest), `locks($name, $version)` and `passthrough()` (run the real tool). A rule without effects
  just succeeds, e.g. `->succeeds()` for an update that changes nothing.

### Real Composer

`useRealComposer()` installs the project with the real Composer from local `path` repositories (no
network) and sends every call no rule matches to it. Rules still apply, so a test can make one
update fail and let the recovery run for real. `installWithRealComposer()` only installs the project.

### Checking the result

- `UpdaterRun`: `assertSuccessful()`, `assertFailed()`, `assertOutputContains()`, `section('Report')`,
  `assertCalled()` / `assertNotCalled()` / `countCalls()` and `calls()` / `callEnvironments()` for
  the arguments and `COMPOSER_*`/`DRUSH_*` variables of every call.
- `UpdaterTestCase`: `assertLockedVersion()`, `assertCommitSubjects()`, and `project()` for files and
  Git (`commitSubjects()`, `uncommittedChanges()`, `git()`).

## Extending the simulator

A command the simulator does not know fails the test with a message naming it, even inside a pipe.
When the updater starts running a new command:

1. If it is specific to one test, script it with a rule.
2. If the updater runs it in its normal flow, simulate it in `ComposerSimulator` (or `DrushSimulator`),
   format its output in `ComposerOutput`, and add the exact command line to
   `ComposerSimulatorContractTest::COMMANDS`, which compares the simulator with the real Composer.

Keep scenario logic out of the simulator: it must not know about any particular test.
