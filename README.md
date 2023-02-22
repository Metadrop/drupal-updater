# Drupal updater

Drupal updater helps you to update the drupal modules of your site.

It does an update of all your drupal modules and dependencies defined in the composer.json.

It also allows update only securities.

## Requirements

This package works with:

- Drush >=10.
- Composer 2.4 (global).

## How it works


This module will try to update your dependencies based on how they are required in the composer.json.

- Before starting to update, all the Drupal configuration is consolidated and commited into GIT.
- For each module / package updated the changes are commited:
  - For PHP packages, it commits the composer.lock
  - For drupal extensions, it applies the updates, commit the configuration changed and the modified files.

If a package has an available update and that update can't be done by running `composer update`, it won't do the upgrade. This means that not all packages will be upgraded, but most of them yes.

## Usage

Basic update:

```bash
./vendor/bin/drupal-updater update
```

Parameters allowed:

- **--security**: It will update only securities.
- **--no-dev**: It won't update dev dependencies, only the primary ones.
- **--author**: It sets the git commits author. Example: Test<test@example.com>

Examples:

- Update securities:

```bash
./vendor/bin/drupal-updater --security
```

- Update only primary packages:

```bash
./vendor/bin/drupal-updater --no-dev
```

- Update with a specific author:

```bash
./vendor/bin/drupal-updater --author=Test<test@example.com>
```
