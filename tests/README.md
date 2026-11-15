# Local tests for published prerequisite contributions

# Composer timeout tests

This is the initial local PHPUnit setup for the configurable timeout change
(#33). It does not require hosted CI, a Drupal site, or network access during
test execution. PHP, Composer and Git must be available. The executable fixtures
use a POSIX shell; the explicit shell-timeout test skips if `timeout` is absent.

The tested dependency combination is PHP 8.4.27, PHPUnit 11.5.58, Console 7.4.23,
Process 7.4.22, YAML 7.4.23 and Drush 13.8.1. Generate a compatible development
lock without changing the package's runtime dependency constraints:

```sh
composer update --with="symfony/console:7.4.*" --with="symfony/process:7.4.*" \
  --with="symfony/yaml:7.4.*" --with="drush/drush:^13" \
  --prefer-stable --no-interaction --no-progress
composer test
composer lint
```

An unconstrained fresh install may select Symfony Console 8, exposing a separate
CLI compatibility issue. This timeout change deliberately does not fix that
issue or claim compatibility across every permitted dependency version.

## Functional check

```sh
composer test -- tests/TimeoutCliTest.php
```

The functional tests invoke the actual CLI in disposable Git projects and load
YAML normally. A Composer stand-in runs for two seconds. With timeout 1 it must
be interrupted; with timeout 5 it must complete. Both cases verify the child
`COMPOSER_PROCESS_TIMEOUT`. Composer and the final diff executable are stand-ins;
the Symfony command lifecycle, process runner and Git operations are real. A
fixture Drupal install path is registered solely for the CLI's root lookup.
No updater methods are replaced, and no real database or package update occurs.

To reproduce the failure against a pristine base using exactly the same tests
and dependencies (run from this checkout):

```sh
git worktree add --detach ../updater-timeout-base ff5a2fa
ln -s "$(pwd)/vendor" ../updater-timeout-base/vendor
UPDATER_TEST_SOURCE="$(pwd)/../updater-timeout-base" \
  composer test -- tests/TimeoutCliTest.php
```

Both tests are expected to fail on that base: the short operation completes
instead of timing out, and the child does not receive the configured value.
`UPDATER_TEST_SOURCE` must be an absolute path; the fixture loads that source's
classes and CLI while sharing the installed test dependencies. Run the same
command without this variable to test the changed branch. A startup/dependency
error is not a successful reproduction of the timeout bug.

The other tests cover defaults, invalid YAML values, Composer discovery/audit
command forms, unchanged non-Composer limits, and explicit shell timeouts.


# Composer recovery CLI tests

Run locally with PHP, Composer and Git. No live Drupal site, hosted CI, or network
access during test execution is required. Fixtures use a POSIX shell.

The test setup is the same initial PHPUnit foundation proposed in #39. The
recovery change is independently based on upstream `1.x` and does not contain or
require #39's timeout behavior. If #39 merges first, rebase onto its test setup.

This dependency combination was used for the tests:

```sh
composer update --with="symfony/console:7.4.*" --with="symfony/process:7.4.*" \
  --with="symfony/yaml:7.4.*" --with="drush/drush:^13" \
  --prefer-stable --no-interaction --no-progress
composer test
composer lint
```

These temporary development constraints do not change runtime requirements.
Unconstrained development installs may select Symfony 8 and expose a separate
CLI compatibility issue; this recovery change does not address that issue.

## Functional scenarios

Each test creates a disposable Git/Composer project with Packagist disabled:

1. Commit a baseline without the updater. Temporarily install a local updater
   package containing the candidate's actual CLI and source, and resolve a
   library from 1.0.0 to 1.1.0 without committing those Composer changes.
2. Invoke the installed CLI. A controlled Composer update damages the Composer
   files and removes updater/diff packages and executables before failing.
3. Exercise normal recovery with real offline Composer. Assert byte-for-byte
   restoration, unchanged Git HEAD/index and earlier work, usable updater/diff
   executables, and no project post-install script execution.
4. Separately inject a recovery install failure and an install that returns
   success but leaves a required tool missing. Both must stop before attempting
   another package.
5. Inject a Drush failure after a successful Composer command and verify that
   the existing single-argument Drush error handler is still used. This guards
   against routing that call through the new Composer recovery handler. It does
   not introduce a new database recovery or exit-status policy.

Only the failed mutation and exceptional recovery outcomes are injected. Normal
fixture installation, recovery reinstallation, Git, the CLI entrypoint and
updater methods are real. The local diff executable is a stand-in, and the
fixture updater's package manifest is reduced so test-runner dependencies can
supply Symfony. A fixture Drupal install path supports the normal root lookup;
no Drupal database or genuine package download failure is being reproduced.

To run exactly these scenarios against pristine upstream using the same test
dependencies (run from this checkout):

```sh
git worktree add --detach ../updater-recovery-base ff5a2fa
UPDATER_TEST_SOURCE="$(pwd)/../updater-recovery-base" composer test
```

The source override must be absolute. The fixture installs that source as the
candidate updater; it does not load the current branch's implementation instead.
The base is expected to fail: Git checkout loses the temporary requirement and
working dependency resolution, and it continues to later packages rather than
performing and validating reinstallation. Run without the override to test the
fix. Startup errors do not count as reproductions of the recovery bug.

The three Composer recovery scenarios fail on pristine upstream; the Drush
compatibility scenario passes there. That fourth scenario instead reproduces
an argument-count regression in the initial PR #40 commit (`fc5ad47`).
