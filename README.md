# Drupal updater

Drupal updater helps you to update the drupal modules of your site.

It does an update of all your drupal modules and dependencies defined in the composer.json.

It also allows update only securities.

## Requirements

This package works with:

- Drush >=10.
- Composer 2.4 (global).

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


## How it works


This module will try to update your dependencies based on how they are required in the composer.json.

- Before starting to update, all the Drupal configuration is consolidated and commited into GIT.
- For each module / package updated the changes are commited:
  - For PHP packages, it commits the composer.lock
  - For Drupal extensions, it applies the updates, commits the configuration changed and the modified files. On multisites environments it will export/commit the configuration for all environments keeping them all synchronized (see parameters).

If a package has an available update and that update can't be done by running `composer update`, it won't do the upgrade. This means that not all packages will be upgraded, but most of them yes.

## Usage

Basic update:

```bash
./vendor/bin/drupal-updater update
```

Parameters allowed:

- **--security**: It will update only securities.
- **--no-dev**: It won't update dev dependencies, only the primary ones.
- **--author**: It sets the git commits author. Example: `Test<test@example.com>`
- **--environment**: List of sites (drush alias) to be run on Drupal Multisites. The drush alias must be local.

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
