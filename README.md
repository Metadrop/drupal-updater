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
- **composerAttempts**: Total attempts for eligible Composer commands, including
  the first (default `3`, range `1`–`10`). Set to `1` to disable retries.
- **composerRetryDelay**: Initial backoff in seconds (default `10`, range
  `0`–`300`). The delay doubles after each eligible failure, capped at 300
  seconds. A value of `0` disables waiting, not retries.
- **composerAdditionalPasses**: Additional passes over unresolved transient
  Composer package failures (default `1`, range `0`–`3`). Set to `0` to disable
  extra passes. Set both this to `0` and `composerAttempts` to `1` to disable
  both retry levels.

For example, allow up to 30 minutes for each Composer command:

```yaml
composerTimeout: 1800
```

This is a per-command limit, not a limit for the complete updater run. An
explicit shell limit such as `timeout 2 composer ...` still takes effect.

### Bounded Composer retries

Package updates and standalone read-only Composer commands can retry recognized
timeouts, temporary connection/download errors, and temporary HTTP failures.
The default allows three total attempts, with 10- and 20-second delays. Dependency
conflicts, authentication/permission failures and unclassified errors are not
retried. Every failed package attempt restores its pre-attempt Composer state
and reinstalls dependencies before another attempt. A failed recovery stops the
run immediately; the recovery install itself is never retried.

On POSIX, standalone Composer invocations replace the intermediate shell so
timeout signals reach Composer itself before recovery. Package discovery retains
Composer stderr for classification. Commands embedded
in legacy shell pipelines keep their existing behavior: pipelines such as the
security audit filter may hide Composer's exit status, so they are not wrapped
in this retry loop. Drush/database operations and Git commands are not retried.

The final issue report retains failure reasons and attempt counts. A successful
retry is marked resolved; exhausted failures remain unresolved. After the initial
package pass, only unresolved transient Composer failures are eligible for the
configured additional passes. Successful packages, constraints, terminal failures,
and Drush failures are excluded. A later successful dependency update can remove
a package from that queue. An unresolved Drush failure also suppresses all extra
package passes, avoiding additional work after a possibly partial Drupal operation.
The successful-package limit applies across all passes;
the outdated-package cache is refreshed before an extra pass.

Each extra pass has the same per-command attempt bound. Defaults therefore allow
up to six attempts for a repeatedly failing package: three in the initial pass
and three in one additional pass. Counts/reasons are retained in one issue entry
across passes. Existing exit codes are preserved. No timeout escalation or
whole-run deadline is introduced; each attempt uses `composerTimeout`.


## Development tests

Local test setup and a reproducible base/head functional comparison are
documented in [tests/README.md](tests/README.md).

## How it works


This module will try to update your dependencies based on how they are required in the composer.json.

- Before starting to update, all the Drupal configuration is consolidated and commited into GIT.
- For each module / package updated the changes are commited:
  - For PHP packages, it commits the composer.lock
  - For Drupal extensions, it applies the updates, commits the configuration changed and the modified files. On multisites environments it will export/commit the configuration for all environments keeping them all synchronized (see parameters).

If a package has an available update and that update can't be done by running `composer update`, it won't do the upgrade. This means that not all packages will be upgraded, but most of them yes.

Before each package update, the updater saves the current `composer.json` and
`composer.lock`, including uncommitted requirements. If the Composer update
fails, those files are restored and `composer install --no-interaction
--no-progress --no-scripts` reconciles installed dependencies. This preserves
temporarily installed updater tools and earlier changes instead of checking
out older Composer files from Git. If recovery fails or a previously available
updater executable is missing, the run stops with an error.

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

Issue reporting preserves the existing exit-code policy. A strict partial-failure
exit mode is a separate change.


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
