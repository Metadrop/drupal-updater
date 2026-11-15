# Final package issue reporting tests

These local PHPUnit tests exercise the real updater CLI, Git commits and the
installed `composer-lock-diff` executable. Composer and Drush outcomes are
controlled executables; no Drupal database, actual dependency download failure,
or real package resolution is exercised. A fixture install path supports the
CLI's normal Drupal root lookup. No updater methods are replaced.

PHP, Composer, Git and a POSIX shell are required. The test setup matches the
initial PHPUnit foundation in #39/#40; this branch is independently based on
upstream `1.x`, without their timeout or recovery changes.

The tested combination is PHP 8.4.27, PHPUnit 11.5.58, Symfony Console 7.4.23,
Process 7.4.22, YAML 7.4.23, Drush 13.8.1 and composer-lock-diff 1.7.1:

```sh
composer update --with="symfony/console:7.4.*" --with="symfony/process:7.4.*" \
  --with="symfony/yaml:7.4.*" --with="drush/drush:^13" \
  --prefer-stable --no-interaction --no-progress
composer test
composer lint
```

The temporary setup constraints do not change runtime requirements. An
unconstrained development install may expose the separate Symfony 8 CLI startup
issue. The tests themselves run without network access or hosted CI.

## Scenarios

- Successful, failed, conflicted and unchanged-but-outdated packages in one run;
  reasons and classifications must appear in the final report.
- A later successful dependency update resolves earlier Composer issues while
  retaining their original reasons.
- An unrelated update cannot resolve an earlier failure.
- Stale cached outdated data must not reopen a resolved constraint.
- Recorded reasons survive a fatal error in the normal final-report stage.
- Drush failures include their operation and reason, without changing the
  existing Drush recovery or exit policy.
- A successful run does not print an empty issue report.

Each case verifies the explicit package attempts: no automatic retries or extra
passes are introduced. Exit-status checks preserve current behavior. Fixtures
use committed Composer files, avoiding a dependency on the separate recovery fix.

## Reproduce against the base

Run from this checkout with dependencies installed:

```sh
git worktree add --detach ../updater-report-base ff5a2fa
ln -s "$(pwd)/vendor" ../updater-report-base/vendor
UPDATER_TEST_SOURCE="$(pwd)/../updater-report-base" composer test
```

The source override must be absolute. The CLI and application classes come from
that source while using the same dependencies. Six reporting regressions are
expected to fail on the base because the final issue report is absent; the
successful-run control passes. Run without the override to test the fix.
Startup errors are not valid reproductions of the missing-report behavior.
