<?php

namespace DrupalUpdater;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;

/**
 * Updates drupal modules and packages.
 */
class UpdaterCommand extends Command {

  protected static $defaultName = 'update';

  /**
   * Prints the output of the command.
   *
   * @var \Symfony\Component\Console\Output\OutputInterface
   */
  protected OutputInterface $output;

  /**
   * If true, only security updates will be updated.
   *
   * @var bool
   */
  protected bool $onlySecurity;

  /**
   * If true, development packages won't be updated.
   *
   * @var bool
   */
  protected bool $noDev;

  /**
   * List of environments to update.
   *
   * @var array
   */
  protected array $environments;

  /**
   * Author of the commits.
   *
   * @var string
   */
  protected string $commitAuthor;

  /**
   * List of packages that will be updated.
   *
   * @var array
   */
  protected array $packagesToUpdate;

  /**
   * Full list of outdated packages.
   *
   * @var array
   */
  protected array $outdatedPackages = [];

  protected bool $showFullReport = TRUE;

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this->setHelp('Update composer packages.

Update includes:
  - Commit current configuration not exported (Drupal +8).
  - Identify updatable composer packages (outdated)
  - For each package try to update and commit it (recovers previous state if fails)');
    $this->addOption('environments', 'envs', InputOption::VALUE_REQUIRED,'List of drush aliases that are needed to update', '@self');
    $this->addOption('author', 'a', InputOption::VALUE_REQUIRED, 'Git author', 'Drupal <drupal@update-helper>');
    $this->addOption('security', 's', InputOption::VALUE_NONE, 'Only update security packages');
    $this->addOption('no-dev', 'nd', InputOption::VALUE_NONE, 'Only update main requirements');
    $this->addOption('packages', 'pl', InputOption::VALUE_OPTIONAL, 'Comma separated list of packages to update');
  }

  /**
   * {@inheritdoc}
   */
  protected function initialize(InputInterface $input, OutputInterface $output) {
    $this->output = $output;
    $this->printHeader1('SETUP');
    $this->output->writeln(sprintf('Environments: %s', $input->getOption('environments')));
    $this->environments = explode(',', $input->getOption('environments'));
    $this->commitAuthor = $input->getOption('author');
    $this->output->writeln(sprintf('GIT author will be overriden with: %s', $this->commitAuthor));
    $this->onlySecurity = (bool) $input->getOption('security');
    if ($this->onlySecurity) {
      $this->output->writeln('Only security updates will be done');
    }
    $this->noDev = (bool) $input->getOption('no-dev');
    if ($this->noDev) {
      $this->output->writeln("Dev packages won't be updated");
    }
    $this->output->writeln('');

    $packages_to_update = $input->getOption('packages') ?? '';
    if (!empty($packages_to_update)) {
      $this->packagesToUpdate = explode(',', filter_var($packages_to_update, FILTER_SANITIZE_ADD_SLASHES));
      $this->showFullReport = FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->runCommand('cp composer.lock composer.drupalupdater.lock');
    $this->printSummary();
    $this->printHeader1('1. Consolidating configuration');
    $this->consolidateConfiguration();
    $this->printHeader1('2. Checking packages');
    if (!isset($this->packagesToUpdate) || empty($this->packagesToUpdate)) {
      $this->checkPackages();
    }
    else {
      $this->output->writeln(sprintf('Packages to update:'));
      $this->output->writeln(implode("\n", $this->packagesToUpdate));
    }
    $this->output->writeln('');
    $this->printHeader1('3. Updating packages');
    $this->updatePackages($this->packagesToUpdate);
    $this->output->writeln('');
    $this->printHeader1('4. Report');
    $this->showUpdatedPackages();

    if ($this->showFullReport) {
      $this->showPendingUpdates();
      $this->showObsoleteDrupalModules();
    }

    $this->cleanup();

    return 0;
  }

  /**
   * Run a drush command.
   *
   * @param string $command
   *   Command to execute.
   * @param array $environments
   *   Environments where the command needs to be executed.
   *   If empty, it will be executed in the environments passed to the command.
   */
  protected function runDrushCommand(string $command, array $environments = []) {
    if (empty($environments)) {
      $environments = $this->environments;
    }

    foreach ($environments as $environment) {
      $this->output->writeln(sprintf("Running drush %s on the \"%s\" environment:\n", $command, $environment));
      $this->runCommand(sprintf('drush %s %s', $environment, $command));
    }
  }

  /**
   * Runs a shell command.
   *
   * @param string $command
   *   Command.
   *
   * @return Process
   *   It can be used to obtain the command output if needed.
   *
   * @throws ProcessFailedException
   *   When the command fails.
   */
  protected function runCommand(string $command) {
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(300);
    $process->run();
    if (!$process->isSuccessful()) {
      throw new ProcessFailedException($process);
    }
    return $process;
  }

  /**
   * Run a composer command.
   *
   * @param string $command
   *   Composer command.
   * @param array $parameters
   *   List of parameters the command needs.
   *
   * @return Process
   *   Process result.
   */
  protected function runComposer(string $command, array $parameters) {
    return $this->runCommand(sprintf('composer %s %s', $command, implode(' ', $parameters)));
  }

  /**
   * Get the no dev parameter.
   *
   * No dev parameter is only added if the --no-dev
   * argument is passed to the command.
   *
   * @return string
   */
  protected function getNoDevParameter(){
    return $this->noDev ? '--no-dev' : '';
  }

  /**
   * Prints a summary listing what will be done in the script.
   */
  protected function printSummary() {
    $this->printHeader1('Summary');
    $this->output->writeln('1. Consolidating configuration');
    $this->output->writeln('2. Checking packages');
    $this->output->writeln('3. Updating packages');
    $this->output->writeln('4. Report');
    $this->output->writeln('');
  }

  /**
   * Consolidate configuration for all the environments.
   *
   * All the configuration that is changed is commited,
   * doing one commit per environment. This implies that
   * configuration must be consistent before running the command.
   */
  protected function consolidateConfiguration() {
    $this->runDrushCommand('cr');
    $this->runDrushCommand('cim -y');

    foreach ($this->environments as $environment) {
      $this->output->writeln(sprintf('Consolidating %s environment', $environment));
      $this->runDrushCommand('cex -y', [$environment]);

      $changes = trim($this->runCommand('git status config -s')->getOutput());
      if (!empty($changes)) {
        $this->output->writeln("\nChanges done:\n");
        $git_status_output = trim($this->runCommand('git status config')->getOutput());
        $this->output->writeln("$git_status_output\n");
      }

      $this->runCommand(sprintf(
        'git add config && git commit -m "CONFIG - Consolidate current configuration on %s" --author="%s" -n || echo "No changes to commit"',
        $environment,
        $this->commitAuthor
      ));
    }

    $this->runDrushCommand('cr');
    $this->runDrushCommand('cim -y');
  }

  /**
   * Check the packages that needs update.
   *
   * By default, all direct packages will be updated.
   * If security parameter is set, only security packages
   * will be updated.
   */
  protected function checkPackages() {
    if ($this->onlySecurity) {
      $packages_to_update = $this->runCommand(sprintf('composer audit --locked %s --format plain 2>&1 | grep ^Package | cut -f2 -d: | sort -u', $this->getNoDevParameter()))->getOutput();

      try {
        $drupal_security_packages = $this->runCommand('./vendor/bin/drush pm:security --fields=name --format=list 2>/dev/null')
          ->getOutput();
      }
      catch (ProcessFailedException $e) {
        $drupal_security_packages = $e->getProcess()->getOutput();
      }

      $packages_to_update = sprintf("%s\n%s", $packages_to_update, $drupal_security_packages);
    }
    else {
      $packages_to_update = $this->runCommand(sprintf('composer show --locked --direct --name-only %s 2>/dev/null', $this->getNoDevParameter()))
        ->getOutput();
    }

    $this->packagesToUpdate = explode("\n", $packages_to_update);
    $this->packagesToUpdate = array_map(function ($package) {
      return trim($package);
    }, $this->packagesToUpdate);
    $this->packagesToUpdate = array_filter($this->packagesToUpdate, function ($package) {
      return preg_match('/^([A-Za-z0-9_-]*\/[A-Za-z0-9_-]*)/', $package);
    });

    $this->output->writeln(implode("\n", $this->packagesToUpdate));
  }

  /**
   * Updates the packages.
   *
   * @param array $package_list
   *   List of packages to update.
   */
  protected function updatePackages(array $package_list) {
    foreach ($package_list as $package) {
      $this->updatePackage($package);
    }
  }

  /**
   * Gets the list of outdated packages.
   *
   * It calculates the outdated packages only the first time.
   *
   * @return array
   *   List of all outdated packages.
   */
  protected function getAllOutdatedPackages() {
    if (empty($this->outdatedPackages)) {
      $this->outdatedPackages = json_decode($this->runCommand('composer show --locked --outdated --format json')->getOutput())->locked;
    }
    return $this->outdatedPackages;
  }

  /**
   * Get an available update of a specific module.
   *
   * @param string $package_name
   *   Package name.
   *
   * @return object|null
   *   Available update information for the specific package.
   */
  protected function getAvailableUpdate(string $package_name) {
    $outdated_packages = $this->getAllOutdatedPackages();
    foreach ($outdated_packages as $package) {
      if ($package->name == $package_name && $package->version != $package->latest) {
        return $package;
      }
    }
    return NULL;
  }

  /**
   * Updates a specific package.
   *
   * After the command, all the modified files will be commited.
   *
   * When the package is a drupal module, the updates will be applied
   * and the configuration will be exported and commited.
   *
   * @param string $package
   *   PAckage to update.
   */
  protected function updatePackage(string $package) {
    $this->printHeader2(sprintf('Updating: %s', $package));
    try {
      $result = $this->runComposer('update', [$package, '--with-dependencies']);
    }
    catch (\Exception $e) {
      $this->handlePackageUpdateErrors($e);
      return;
    }

    $composer_lock_is_changed = (int) $this->runCommand('git status --porcelain composer.lock | wc -l')->getOutput() > 0;

    $available_update = $this->getAvailableUpdate($package);
    if (!empty($available_update) && !empty($available_update->latest) && !$composer_lock_is_changed) {
      $this->output->writeln(sprintf("Package %s has an update available to %s version. Due to composer.json constraints, it hasn't been updated.\n", $package, $available_update->latest));

      $error_output = trim($result->getOutput());
      $valid_errors = [
        'but it conflicts with your root composer.json require',
        'Your requirements could not be resolved to an installable set of packages.',
      ];

      foreach ($valid_errors as $error) {
        if (str_contains($error_output, $error)) {
          $this->output->writeln("\n$error_output");
        }
      }

    }

    if (!$composer_lock_is_changed) {
      if (empty($available_update)) {
        $this->output->writeln(sprintf("There aren't available updates for %s package.\n", $package));
      }
      return;
    }

    $this->runCommand('git add composer.json composer.lock');

    if ($this->isDrupalExtension($package)) {
      try {
        $this->runCommand('git add web');
        $this->runDrushCommand('cr');
        $this->runDrushCommand('updb -y');
        $this->runDrushCommand('cex -y');
        $this->runCommand('git add config');
      }
      catch (\Exception $e) {
        $this->handlePackageUpdateErrors($e);
        return;
      }

    }

    $this->output->writeln("\nUpdated packages:");
    $updated_packages = trim($this->runCommand('composer-lock-diff')->getOutput());
    $this->output->writeln($updated_packages . "\n");

    $this->runCommand(sprintf('git commit -m "UPDATE - %s with dependencies" -m "%s" --author="%s" -n', $package, $updated_packages, $this->commitAuthor));

  }

  /**
   * Handle errors produced in a update.
   *
   * There are errors either in composer update or drush updb, in those
   * case all the possible changes are reverted and the error message is shown.
   *
   * @param \Exception $e
   *   Exception.
   */
  protected function handlePackageUpdateErrors(\Exception $e) {
    $this->output->writeln("\n!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!");
    $this->output->writeln($e->getProcess()->getErrorOutput());
    $this->output->writeln('Updating package FAILED: recovering previous state.');
    $this->output->writeln('!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!');
    $this->runCommand('git checkout composer.json composer.lock');
  }

  /**
   * Shows all the pending updates
   */
  protected function showPendingUpdates() {

    if ($this->onlySecurity) {
      $this->printHeader2('Not Updated Securities (Packagist):');
      $this->output->writeln(
        $this->runCommand('composer audit --locked --format plain 2>&1 | grep ^Package | cut -f2 -d: | sort -u')->getOutput(),
      );

      $this->printHeader2('Not Updated Securities (Drupal):');
      try {
        $this->output->writeln(
          $this->runCommand('./vendor/bin/drush pm:security --fields=name --format=list 2>/dev/null')->getOutput(),
        );
      }
      catch (ProcessFailedException $e) {
        $this->output->writeln($e->getProcess()->getErrorOutput());
      }

    }
    else {
      $this->printHeader2('Not Updated Packages (Direct):');
      $this->output->writeln(
        $this->runCommand('composer show --locked --outdated --direct')->getOutput()
      );

      $this->output->writeln('');
      $this->printHeader2('Not Updated Packages (ALL):');
      $this->output->writeln(
        $this->runCommand('composer show --locked --outdated')->getOutput()
      );

      $this->output->writeln('');
      $this->printHeader2('Not Updated Securities (ALL):');
      $this->output->writeln(
        $this->runCommand('composer audit --locked')->getOutput()
      );

      $this->output->writeln(
        $this->runCommand('./vendor/bin/drush pm:security --fields=name --format=list 2>/dev/null')->getOutput(),
      );

    }
  }

  /**
   * Show updated packages.
   */
  protected function showUpdatedPackages() {
    $updated_packages = $this->runCommand('composer-lock-diff  --from composer.drupalupdater.lock --to composer.lock')->getOutput();
    if (!empty($updated_packages)) {
      $this->output->writeln(
        $updated_packages,
      );
    }
    else {
      $this->output->writeln("No packages have been updated\n");
    }
  }

  /**
   * Shows all the drupal modules that are obsolete.
   */
  protected function showObsoleteDrupalModules() {
    $this->printHeader2('Unsupported Drupal modules:');

    $unsupported_modules_list = [];
    foreach ($this->environments as $environment) {
      try {
        $unsupported_modules = json_decode(trim($this
          ->runCommand(sprintf('drush %s php-script %s/../scripts/unsupported-modules.php', $environment, __DIR__))
          ->getOutput()));
        foreach ($unsupported_modules as $unsupported_module) {
          $unsupported_module = (array) $unsupported_module;
          if (!isset($unsupported_modules_list[$unsupported_module['project_name']])) {
            $unsupported_modules_list[$unsupported_module['project_name']] = $unsupported_module;
          }
          $unsupported_modules_list[$unsupported_module['project_name']]['environments'][] = $environment;
        }
      }
      catch (ProcessFailedException $exception) {
        $this->output->writeln('');
        $this->output->write($exception->getMessage());
      }
    }

    $unsupported_modules_list = array_values(array_map (function ($unsupported_module) {
      $unsupported_module['environments'] = implode("\n", $unsupported_module['environments']);
      return array_values($unsupported_module);
    }, $unsupported_modules_list));

    if (!empty($unsupported_modules_list)) {
      $unsupported_modules_list_table_rows = [];
      foreach ($unsupported_modules_list as $unsupported_module_info) {
        $unsupported_modules_list_table_rows[] = $unsupported_module_info;
        $unsupported_modules_list_table_rows[] = new TableSeparator();
      }
      $fixed_drupal_advisories_table = new Table($this->output);
      $fixed_drupal_advisories_table->setHeaders(['Module', 'Current version', 'Recommended version', 'Environment(s)']);

      array_pop($unsupported_modules_list_table_rows);
      $fixed_drupal_advisories_table->setRows($unsupported_modules_list_table_rows);
      $fixed_drupal_advisories_table->render();
    }
    else {
      $this->output->writeln('No obsolete modules have been found. Perhaps Update module is not installed?');
    }
  }

  /**
   * Cleanup the residual files.
   */
  protected function cleanup() {
    $this->runCommand('rm composer.drupalupdater.lock');
  }

  /**
   * Checks that the package is a drupal extension.
   *
   * By drupal extension we mean:
   *   - Module
   *   - Theme
   *   - Library
   *   - Drush command package.
   *
   * @param string $package
   *   Package.
   *
   * @return bool
   *   TRUE when the package is a drupal extension.
   */
  protected function isDrupalExtension(string $package) {
    $package_type = $this->runCommand(sprintf("composer show %s | grep ^type | awk '{print $3}'", $package))->getOutput();
    return $package_type != 'drupal-library' && str_starts_with($package_type, 'drupal');
  }

  /**
   * Get the list of packages to update.
   *
   * @return array
   *   List of packages to update.
   */
  protected function getPackagesToUpdate() {
    return $this->packagesToUpdate;
  }

  /**
   * Print a primary header.
   *
   * @param string $text
   *   Header text.
   */
  protected function printHeader1(string $text) {
    $this->output->writeln(sprintf("// %s //\n", strtoupper($text)));
  }

  /**
   * Prints a secondary header.
   *
   * @param string $text
   *   Header text.
   */
  protected function printHeader2(string $text) {
    $this->output->writeln(sprintf("/// %s ///\n", $text));
  }

}
