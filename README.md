# Drupal updater

Drupal updater helps you to update the drupal modules of your site.

It does an update of all your drupal modules and dependencies defined in the composer.json.

It also allows update only securities.

## Requirements

This package works with:

- Drush >=10.
- Composer >=2.4 installed as a global package [^1].

Or alternatively, you can run it inside tools like [ddev](https://ddev.com).

## Installation

Before doing the installation, make sure your environment has composer 2.4 or higher installed locally.

```bash
composer require metadrop/drupal-updater
```

Or, if you are using `ddev`:
```bash
ddev composer require metadrop/drupal-updater
```

## Configuration

Configuration helps automating update workflows. All the parameters that are repeated through updates
can be added to a configuration file to just launch `drupal-updater`, saving time adding the parameters manually,
or doing custom helpers in local / ci environments.

There is a template with configuration ready to use at **vendor/metadrop/drupal-updater/drupal-updater.yml.dist**, to use it just copy it to the root:

```
cp vendor/metadrop/drupal-updater/.drupal-updater.yml.dist .drupal-updater.yml
```

The file .drupal-updater.yml at root is the default path, but it is possible to override configuration path by using the **--config** parameter:

```
drupal-updater --config .drupal-updater.securities.yml
```

Edit .drupal-updater.yml to setup custom parameters when needed.

### Configuration variables

The following variables can be setup through .drupal-updater.yml

- **author**: Commits author
- **noDev**: Set to true to only update packages deployed in production.
- **onlySecurities**: Set to true to only update securities.
- **packages**: Allows specify which packages will be updated.
- **limit**: Limit the number of direct packages to update in each drupal-updater run. Ignored when packages is provided.
- **environments**: Array list of environments to update.
- **composerTimeout**: Positive integer timeout in seconds for each Composer
  command (default `300`). Sets both the Symfony Process timeout and the child
  `COMPOSER_PROCESS_TIMEOUT`. Other commands retain their 300-second timeout.

For example, allow up to 30 minutes for each Composer command:

```yaml
composerTimeout: 1800
```

This is a per-command limit, not a limit for the complete updater run. An
explicit shell limit such as `timeout 2 composer ...` still takes effect.


## Development tests

How to run the tests, and how to write new ones with the Composer and Drush
simulator, is documented in [tests/README.md](tests/README.md).

## How it works


This module will try to update your dependencies based on how they are required in the composer.json.

- Before starting to update, all the Drupal configuration is consolidated and commited into GIT.
- For each module / package updated the changes are commited:
  - For PHP packages, it commits the composer.lock
  - For Drupal extensions, it applies the updates, commits the configuration changed and the modified files. On multisites environments it will export/commit the configuration for all environments keeping them all synchronized (see parameters).

If a package has an available update and that update can't be done by running `composer update`, it won't do the upgrade. This means that not all packages will be upgraded, but most of them yes.

Before each package update, the updater saves the current `composer.json` and
`composer.lock`, including uncommitted requirements. If the Composer update
fails, those files are restored, so earlier changes are kept instead of
checking out older Composer files from Git. When the failed update had already
changed the vendor folder, `composer install --no-interaction --no-progress
--no-scripts` reinstalls the dependencies of the restored lock and the updater
checks that `vendor/bin/composer-lock-diff` is still there. If any of that
fails, the run stops with an error.

Recovery skips project scripts; Composer plugins can still execute. It restores
Composer state and dependencies, not database changes or arbitrary project files.

### Final package issue report

When package updates encounter problems, a final report lists the affected
package, operation, and original reason:

- **execution**: A Composer or Drush operation failed.
- **constraint**: Composer reported a dependency conflict, or an available
  update produced no compatible change under the current requirements.
- **resolved**: A later successful package update changed a package involved
  in an earlier Composer issue. The original reason is retained for context.

An unrelated successful update does not clear a failure, and a Composer package
change does not clear a Drush failure. Recorded issues are also printed if a
later stage terminates with an exception. No empty report is printed for a run
without package issues.

This reporting change preserves the existing recovery behavior and exit codes.
It does not add retries or a strict partial-failure exit mode.

## Development tests

How to run the tests, and how to write new ones with the Composer and Drush
simulator, is documented in [tests/README.md](tests/README.md).

## Usage

Basic update:

```bash
./vendor/bin/drupal-updater update
```

Parameters allowed:

- **--config**: Specify where the configuration file is located.
- **--security**: It will update only securities.
- **--no-dev**: It won't update dev dependencies, only the primary ones.
- **--author**: It sets the git commits author. Example: `Test<test@example.com>`
- **--environment**: List of sites (drush alias) to be run on Drupal Multisites. The drush alias must be local.
- **--packages**: Update only the mentioned packages.
  **--limit**: Limit the number of direct packages to update in each drupal-updater run. Ignored when packages is provided.

Examples:

- Update securities:

  ```bash
  ./vendor/bin/drupal-updater --security
  ```

- Update only primary packages:

  ```bash
  ./vendor/bin/drupal-updater --no-dev
  ```

- Update specific packages:

  ```bash
  ./vendor/bin/drupal-updater --packages=drupal/core-recommended,drupal/core-dev
  ```

- Update with a specific author:

  ```bash
  ./vendor/bin/drupal-updater --author=Test<test@example.com>
  ```

- Update on multiple sites (Drupal Multisite):

  ```bash
  ./vendor/bin/drupal-updater --environments=@site1.local,@site2.local,@site3.local,@site4.local
  ```

### DDEV

If you are using `ddev`, you can just run the commands above prepending `ddev exec`.

Example:

  ```bash
  ddev exec ./vendor/bin/drupal-updater --security
  ```


[^1]: Using local composer (vendor/bin/composer) is discouraged. The drupal-updater command may update the self composer library and break the process with a Fatal error. Plus, generally, the composer binary is designed to be used globally.

The final pending-update report prints an explicit message when a package or
security category has no results, rather than leaving that category blank.

When an available package update leaves the lock file unchanged, the updater
suggests a `composer require vendor/package:version -W --dry-run` command to
investigate dependency constraints. The suggested version is the reported
latest version. The updater prints this diagnostic command without executing it.
