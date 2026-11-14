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
