# AGENTS.md

This file provides guidance to AI coding agents working with code in this repository.

## What this is

`metadrop/drupal-updater` is a Composer library that ships a single Symfony Console command
(`drupal-updater`, default command `update`). It is installed inside a Drupal project and run from
that project's root: it updates Composer packages one by one and creates one git commit per updated
package. It is not a Drupal module; it drives a Drupal site from the outside by shelling out to
`composer`, `drush`, `git` and `composer-lock-diff`.

## Commands

```bash
composer install
composer lint      # php -l over src/ and tests/
composer phpcs     # Drupal coding standard (phpcs.xml.dist: src, tests, scripts)
composer phpcbf    # autofix phpcs issues
composer phpstan   # level 8, src/ and tests/
composer test      # phpunit (tests/ suite, failOnWarning)
vendor/bin/phpunit --filter SomeTest   # single test
```

Functional tests run the real CLI against a temporary Git project, with Composer and Drush simulated
through `tests/bin` (Git and composer-lock-diff are real). New tests extend
`tests/Functional/UpdaterTestCase.php` and declare packages and scripted answers on its scenario; never
add per-test fake executables. `tests/README.md` explains the base and how to extend the simulator.

CI (`.github/workflows/`): PHPUnit + lint on PHP 8.1–8.5, and PHPCS + PHPStan on PHP 8.4, all with
highest dependency versions. Code must stay compatible with that whole PHP range, with
`symfony/console`/`symfony/process` from 5.4 up to 8.x, Drush >= 10 and PHPUnit 9.6–13 (see the
`addCommand()`/`add()` switch in the `drupal-updater` bin script for the kind of shim this needs).

## Architecture

- `drupal-updater` (bin): locates `autoload.php` both standalone and when installed under
  `vendor/`, registers `UpdaterCommand` and makes it the default command.
- `src/UpdaterCommand.php`: the whole workflow. Every external action goes through `runCommand()`
  (`Process::fromShellCommandline`, 300 s timeout, throws `RuntimeException` on failure), so the
  logic is mostly composing shell pipelines and parsing their output. `execute()` runs:
  1. Backs up `composer.lock` to `composer.drupalupdater.lock` (used for the final report, removed
     in `cleanup()`).
  2. Configuration consolidation: per environment, `drush cr`, `cim`, `cex` and a
     `CONFIG - Consolidate ...` commit of `config/`.
  3. Package selection: `composer show --outdated` (or `composer audit` with `--security`), then
     each transitive package is mapped to the direct package that requires it via `composer why`
     (non-recursive first, then recursive with a 2 s `timeout`). If core is in the list,
     `drupal/core*` is added so core packages update together.
  4. Per package: `composer update <pkg> --with-dependencies`; if `composer.lock` changed and the
     package is a Drupal extension (type `drupal-*` except `drupal-library`), `drush cr`, `updb`,
     `cex` and stage the web root and `config/`. Then post-update processors run, and the commit
     is `UPDATE - <pkg>: package (a -> b), dependencies, configuration changes`. On failure,
     `handlePackageUpdateErrors()` restores `composer.json`/`composer.lock` with `git checkout`.
     `limit` caps successful direct-package updates and is ignored when `packages` is set.
  5. Report: lock diff against the backup, pending updates/securities and unsupported modules.
     Unsupported modules come from running `scripts/unsupported-modules.php` through
     `drush php-script` in each environment (requires the Update module).
- Environments are drush aliases (default `['@self']`); drush commands run once per alias, which is
  how multisites are kept in sync.
- `src/Config/`: `Config::createFromConfigurationFile()` reads `.drupal-updater.yml` (YAML keys:
  `author`, `noDev`, `onlySecurities`, `packages`, `limit`, `environments`,
  `consolidateConfiguration`) with per-key type validation. CLI options are mapped on top in
  `UpdaterCommand::mapInputToConfiguration()` and override the file. `.drupal-updater.yml.dist` is
  the template users copy, and the README documents the keys and options: keep both in sync when
  adding one.
- `src/PostUpdate/`: `PostUpdateInterface::execute($package, $composerLockDiff, $output)` hooks run
  after each package update, before the commit; processors are registered in the
  `UpdaterCommand` constructor. `PostUpdateDDQG` keeps `config.audit.ignore` entries of the form
  `DDQG-unsupported-drupal-<module>-<version>` in the site's `composer.json` in step with the new
  version, or drops them when the module becomes stable or abandoned.

## Conventions

- Drupal coding standard (2-space indent, `TRUE`/`FALSE`/`NULL` uppercase, full docblocks).
- Commit subjects in this repo follow `Issue #<n>: <description>` when tied to a GitHub issue
  (`Issue #0` when there is none), otherwise a short imperative sentence.
