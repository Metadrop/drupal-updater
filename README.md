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
- **limit**: Limit the number of direct packages to update in each drupal-updater run. Can't be combined with packages.
- **environments**: Array list of environments to update.


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

- **--config**: Specify where the configuration file is located.
- **--security**: It will update only securities.
- **--no-dev**: It won't update dev dependencies, only the primary ones.
- **--author**: It sets the git commits author. Example: `Test<test@example.com>`
- **--environment**: List of sites (drush alias) to be run on Drupal Multisites. The drush alias must be local.
- **--packages**: Update only the mentioned packages.
  **--limit**: Limit the number of direct packages to update in each drupal-updater run. Can't be combined with packages.

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
