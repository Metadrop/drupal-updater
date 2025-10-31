<?php

namespace DrupalUpdater;

use Composer\InstalledVersions;
use DrupalUpdater\Config\Config;
use DrupalUpdater\Config\ConfigInterface;
use DrupalUpdater\PostUpdate\PostUpdateDDQG;
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
  /**
   * Prints the output of the command.
   *
   * @var \Symfony\Component\Console\Output\OutputInterface
   */
  protected OutputInterface $output;

  protected ConfigInterface $config;

  /**
   * List of packages to update.
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
   * Total number of direct packages successfully updated.
   *
   * @var int
   */
  protected int $updatedPackages = 0;

  /**
   * List of post-update processors.
   *
   * @var \DrupalUpdater\PostUpdate\PostUpdateInterface[]
   */
  protected array $postUpdateProcessors = [];

  /**
   * {@inheritdoc}
   */
  public function __construct(?string $name = null)
  {
    if (empty($name)) {
      $name = 'update';
    }
    parent::__construct($name);

    $this->postUpdateProcessors[] = new PostUpdateDDQG();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this->setHelp('Update composer packages.

Update includes:
  - Commit current configuration not exported (Drupal +8).
  - Identify updatable composer packages (outdated)
  - For each package try to update and commit it (recovers previous state if fails)');
    $this->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Configuration file', '.drupal-updater.yml');
    $this->addOption('environments', 'envs', InputOption::VALUE_OPTIONAL, 'List of drush aliases that are needed to update');
    $this->addOption('author', 'a', InputOption::VALUE_OPTIONAL, 'Git author');
    $this->addOption('security', 's', InputOption::VALUE_NEGATABLE, 'Choose to update only security packages');
    $this->addOption('dev', 'nd', InputOption::VALUE_NEGATABLE, 'Choose to update dev requirements.');
    $this->addOption('packages', 'pl', InputOption::VALUE_OPTIONAL, 'Comma separated list of packages to update');
    $this->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Maximum number of direct packages to update. It must be integer positive, set to 0 for no limit.');
    $this->addOption('consolidate-configuration', 'cc', InputOption::VALUE_NEGATABLE, 'If false, configuration will not be consolidated.');
  }

  /**
   * {@inheritdoc}
   */
  protected function initialize(InputInterface $input, OutputInterface $output) {
    $this->output = $output;
    $this->setupConfig($input->getOption('config'));
    $this->mapInputToConfiguration($input);
    $this->getConfiguration()->validate();
    $this->logConfiguration();
  }

  /**
   * Maps all the input data provided to the configuration.
   *
   * Anything passed to input can override configuration.
   *
   * @param InputInterface $input
   *   Input.
   */
  protected function mapInputToConfiguration(InputInterface $input) {
    if (!empty($input->getOption('environments'))) {
      $this->getConfiguration()->setEnvironments(explode(',', $input->getOption('environments')));
    }

    if (!empty($input->getOption('author'))) {
      $this->getConfiguration()->setAuthor($input->getOption('author'));
    }

    if (!empty($input->getOption('security'))) {
      $this->getConfiguration()->setOnlySecurities(true);
    }
    elseif (!empty($input->getOption('no-security'))) {
      $this->getConfiguration()->setOnlySecurities(false);
    }

    if (!empty($input->getOption('dev'))) {
      $this->getConfiguration()->setNoDev(false);
    }
    elseif (!empty($input->getOption('no-dev'))) {
      $this->getConfiguration()->setNoDev(true);
    }

    if (!empty($input->getOption('consolidate-configuration'))) {
      $this->getConfiguration()->setConsolidateConfiguration(true);
    }
    elseif (!empty($input->getOption('no-consolidate-configuration'))) {
      $this->getConfiguration()->setConsolidateConfiguration(false);
    }

    $packages_to_update_parameter = $input->getOption('packages') ?? '';
    if (!empty($packages_to_update_parameter)) {
      $this->getConfiguration()->setPackages(explode(',', filter_var($packages_to_update_parameter, FILTER_SANITIZE_ADD_SLASHES)));
      $this->showFullReport = FALSE;
    }

    if (!empty($input->getOption('limit'))) {
      $limit = (int) $input->getOption('limit');
      if ($limit != $input->getOption('limit')) {
        throw new \InvalidArgumentException('Invalid limit ' . $input->getOption('limit') . '. It must be integer positive');
      }

      $this->getConfiguration()->setLimit($limit);
    }

    if (!empty($this->getConfiguration()->getPackages())) {
      $this->packagesToUpdate = $this->getConfiguration()->getPackages();
    }

  }

  /**
   * Show users what config will be applied to the current update.
   */
  protected function logConfiguration() {
    $this->printHeader1('SETUP');
    $this->output->writeln('Drupal web root found at ' . $this->findDrupalWebRoot());
    $this->output->writeln(sprintf('Environments: %s', implode(', ', $this->getConfiguration()->getEnvironments())));
    $this->output->writeln(sprintf('GIT author will be overriden with: %s', $this->getConfiguration()->getAuthor()));

    if ($this->getConfiguration()->onlyUpdateSecurities()) {
      $this->output->writeln('Only security updates will be done');
    }

    if ($this->getConfiguration()->noDev()) {
      $this->output->writeln("Dev packages won't be updated");
    }

    if (!empty($this->getConfiguration()->getPackages())) {
      $this->output->writeln(sprintf('Selected packages: %s', implode(', ', $this->getConfiguration()->getPackages())));
    }

    $this->output->writeln('');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) : int {
    $this->runCommand('cp composer.lock composer.drupalupdater.lock');
    $this->printSummary();
    $this->printHeader1('1. Consolidating configuration');

    if ($this->config->getConsolidateConfiguration()) {
      $this->consolidateConfiguration();
    }
    else {
      $this->output->writeln('Configuration consolidation skipped');
      $this->output->writeln('');
    }

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
    $this->printHeader1('4. Report');
    $this->showUpdatedPackages();

    if ($this->showFullReport) {
      $this->showPendingUpdates();
      $this->showObsoleteDrupalModules();
    }

    $this->cleanup();

    return 0;
  }

  protected function setupConfig(string $configuration_filepath) {
    $this->output->writeln(sprintf('Selected configuration file: %s', $configuration_filepath));

    if (file_exists($configuration_filepath)) {
      $this->output->writeln(sprintf('Configuration file found at %s', $configuration_filepath));
      $config = Config::createFromConfigurationFile($configuration_filepath);
    }
    else {
      $this->output->writeln(sprintf('No configuration file found at %s. Using command line parameters.', $configuration_filepath));
      $config = new Config();
    }

    $this->setConfiguration($config);
  }

  protected function setConfiguration(ConfigInterface $config) {
    $this->config = $config;
  }

  protected function getConfiguration() {
    return $this->config;
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
      $environments = $this->getConfiguration()->getEnvironments();
    }

    foreach ($environments as $environment) {
      $this->output->writeln(sprintf("Running drush %s on the \"%s\" environment.", $command, $environment));
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
   * @throws \RuntimeException
   *   When the command fails.
   */
  protected function runCommand(string $command) {
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(300);
    $process->run();
    if (!$process->isSuccessful()) {
      throw new \RuntimeException(sprintf('Error running "%s" command: %s', $command, $process->getErrorOutput()));
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
    return $this->getConfiguration()->noDev() ? '--no-dev' : '';
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
    $this->output->writeln('');
    $this->output->writeln('');

    foreach ($this->getConfiguration()->getEnvironments() as $environment) {
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
        $this->getConfiguration()->getAuthor(),
      ));
      $this->output->writeln('');
    }

    $this->runDrushCommand('cr');
    $this->runDrushCommand('cim -y');
    $this->output->writeln('');
  }

  /**
   * Check the packages that needs update.
   *
   * By default, all direct packages will be updated.
   * If security parameter is set, only security packages
   * will be updated.
   */
  protected function checkPackages() {
    if ($this->getConfiguration()->onlyUpdateSecurities()) {
      $package_list = $this
        ->runCommand(sprintf('composer audit --locked %s --format plain 2>&1 | grep ^Package | cut -f2 -d: | sort -u', $this->getNoDevParameter()))
        ->getOutput();
    }
    else {
      $package_list = $this
        ->runCommand(sprintf('composer show --locked --outdated --name-only %s 2>/dev/null', $this->getNoDevParameter()))
        ->getOutput();
    }
    $package_list_massaged = $this->massagePackageList($package_list);
    $this->packagesToUpdate = $this->findDirectPackagesFromList($package_list_massaged);

    $this->output->writeln(implode("\n", $this->packagesToUpdate));
  }

  /**
   * Given a list of packages , find its direct packages.
   *
   * @see UpdaterCommand::findDirectPackage()
   *
   * @param array $packages_list
   *   Packages list.
   * @return array
   *   List of direct packages.
   */
  protected function findDirectPackagesFromList(array $packages_list) {
    if (empty($packages_list)) {
      return [];
    }

    $direct_packages = $this->massagePackageList($this
      ->runCommand(sprintf('composer show --locked --direct --name-only %s 2>/dev/null', $this->getNoDevParameter()))
      ->getOutput());

    $direct_packages_found = array_intersect($packages_list, $direct_packages);

    $not_direct_packages = array_diff($packages_list, $direct_packages);

    foreach ($not_direct_packages as $package) {
      $direct_packages_found[] = $this->findDirectPackage($package, $direct_packages);
    }

    return array_unique($direct_packages_found);
  }

  /**
   * Find the direct package for a specific not direct dependency.
   *
   * If no direct package is found, consider the self package as direct. It is a extreme use
   * case where a too deep dependency is not found. For module convenience, it is needed to consider
   * package as direct so it can be updated.
   *
   * @param string $package
   *   Package.
   * @param array $direct_packages
   *   List of direct packages.
   *
   * @return string
   *   The direct package.
   */
  protected function findDirectPackage(string $package, array $direct_packages) {
    $composer_why_recursive_timeout = 2;
    $commands = [
      sprintf("composer why %s --locked | awk '{print $1}'", $package),
      sprintf("timeout %s composer why %s --locked -r | awk '{print $1}'", $composer_why_recursive_timeout, $package),
    ];

    foreach ($commands as $command) {
      $direct_package = $this->findPackageInPackageListCommand($command, $direct_packages);
      if (!empty($direct_package)) {
        return $direct_package;
      }
    }

    return $package;
  }

  /**
   * Finds a package from a command that is present in a package list command.
   *
   * This is used to get direct apckages from not direct packages.
   *
   * @see UpdaterCommand::findDirectPackage()
   *
   * @param string $command
   *   List that return packages list.
   * @param array $package_list
   *   List of packages we want to look for.
   *
   * @return string|null
   *   FIrst package from command that is present in package list.
   */
  protected function findPackageInPackageListCommand(string $command, array $package_list) {
    $package_list_output = array_filter(explode("\n", (string) trim($this->runCommand($command)
      ->getOutput())));
    foreach ($package_list_output as $package) {
      if (in_array($package, $package_list)) {
        return $package;
      }
    }

    return null;
  }

  /**
   * Masssages a list of packages.
   *
   * It converts a package list bash output into a package list array,
   * removing any element that is not a package and removing spaces.
   *
   * @param string $package_list
   *   List of packages coming from a bash command (s.e.: composer show --names-only).
   *
   * @return array
   *   List of packages. Example:
   *    - metadrop/drupal-updater
   *    - metadrop/drupal-artifact-builder
   */
  protected function massagePackageList(string $package_list) {
    $package_list = explode("\n", $package_list);
    $package_list = array_map(function ($package) {
      return trim($package);
    }, $package_list);
    return array_filter($package_list, function ($package) {
      return preg_match('/^([A-Za-z0-9_-]*\/[A-Za-z0-9_-]*)/', $package);
    });
  }

  /**
   * Updates the packages.
   *
   * @param array $package_list
   *   List of packages to update.
   */
  protected function updatePackages(array $package_list) {
    foreach ($package_list as $package) {
      if (!empty($this->getConfiguration()->getLimit()) && $this->getConfiguration()->getLimit() > 0 && $this->updatedPackages == $this->getConfiguration()->getLimit()) {
        $this->output->writeln(sprintf("Reached limit of %d direct packages, finishing...", $this->getConfiguration()->getLimit()));
        $this->output->writeln('');
        break;
      }

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
        $this->runCommand(sprintf('git add %s', $this->findDrupalWebRoot()));
        $this->runDrushCommand('cr');
        $this->runDrushCommand('updb -y');
        if ($this->config->getConsolidateConfiguration()) {
          $this->runDrushCommand('cex -y');
          $this->output->writeln('');
          $this->runCommand('git add config');
        }
      }
      catch (\Exception $e) {
        $this->handlePackageUpdateErrors($e);
        return;
      }

    }

    $composerLockDiff = $this->getComposerLockDiffJsonDecoded();
    foreach ($this->postUpdateProcessors as $processor) {
      $processor->execute($package, $composerLockDiff, $this->output);
    }

    $updated_packages = trim($this->runCommand('composer-lock-diff')->getOutput());
    if (!empty($updated_packages)) {
      $this->output->writeln("Updated packages:");
      $this->output->writeln("$updated_packages\n");
    }

    $commit_message = $this->calculateModuleUpdateCommitMessage($package);

    $this->runCommand(sprintf('git commit -m "%s" -m "%s" --author="%s" -n', $commit_message, $updated_packages, $this->getConfiguration()->getAuthor()));
    $this->updatedPackages++;

  }

  /**
   * Finds the Drupal root.
   *
   * Drupal root standard is web, but there are other projects where the path is docroot,
   * and another complex structures that requires changing the root folder.
   *
   * @return string|void
   *   Drupal root.
   */
  protected function findDrupalWebRoot() {
    $core = InstalledVersions::getInstallPath('drupal/core') . '/../';

    if (!empty($core)) {
      return realpath($core);
    }

    foreach (['web', 'docroot', 'public_html'] as $folder) {
      if (!is_link($folder) && is_dir($folder)) {
        return $folder;
      }
    }
  }

  /**
   * Calculate the commit message.
   *
   * Commit message is different depending on what have changed
   * so that at a first glance developers may know what
   * happened to the module.
   *
   * Changes can be:
   *   - Package.
   *   - Dependencies.
   *   - Configuration.
   *
   * @param string $package
   *   Package name.
   *
   * @return string
   *   Format: UPDATE - <package>: (package)(, dependencies)(, configuration changes).
   */
  protected function calculateModuleUpdateCommitMessage(string $package) {

    $changes = [];
    $composer_lock_diff = $this->getComposerLockDiffJsonDecoded();
    if ($this->isPackageUpdated($package, $composer_lock_diff)) {
      [$package_update_from, $package_update_to] = $this->getPackageUpdate($package, $composer_lock_diff);
      $changes[] = sprintf('package (%s -> %s)', $package_update_from, $package_update_to);
    }

    if ($this->areDependenciesUpdated($package, $composer_lock_diff)) {
      $changes[] = 'dependencies';
    }

    if ($this->isConfigurationChanged()) {
      $changes[] = 'configuration changes';
    }

    if (empty($changes)) {
      $changes[] = 'other';
    }

    return sprintf('UPDATE - %s: %s', $package, implode(', ', $changes));
  }

  /**
   * Gets package update information.
   *
   * @param string $package_name
   *   Package name.
   * @param array $composer_lock_diff
   *   Composer lock diff.
   *
   * @return array
   *   Data indicating what has been updated.
   */
  protected function getPackageUpdate(string $package_name, array $composer_lock_diff) {
    return $composer_lock_diff['changes'][$package_name] ?? $composer_lock_diff['changes-dev'][$package_name] ?? [];
  }

  /**
   * Check package has been updated.
   *
   * @param string $package_name
   *   Package name.
   * @param array $composer_lock_diff
   *   Composer lock diff.
   *
   * @return bool
   *   TRUE when the package is updated.
   */
  protected function isPackageUpdated(string $package_name, array $composer_lock_diff) {
    return !empty($this->getPackageUpdate($package_name, $composer_lock_diff));
  }

  /**
   * Check package dependencies has been updated.
   *
   * @param string $package_name
   *   Package name.
   * @param array $composer_lock_diff
   *   Composer lock diff.
   *
   * @return bool
   *   TRUE when any dependency that isn't the package has changed.
   */
  protected function areDependenciesUpdated(string $package_name, array $composer_lock_diff) {
    if (isset($composer_lock_diff['changes'][$package_name])) {
      unset($composer_lock_diff['changes'][$package_name]);
    }

    if (isset($composer_lock_diff['changes-dev'][$package_name])) {
      unset($composer_lock_diff['changes-dev'][$package_name]);
    }
    return !empty($composer_lock_diff['changes']) || !empty($composer_lock_diff['changes-dev']);
  }

  /**
   * Checks that the configuration has changed.
   *
   * @return bool
   *   TRUE when the configuration has changed.
   */
  protected function isConfigurationChanged() {
    return ((int) trim($this->runCommand('git status -s config | wc -l')->getOutput())) > 0;
  }

  /**
   * Get composer lock diff decoded.
   *
   * @return array
   *   Associative composer lock diff.
   */
  protected function getComposerLockDiffJsonDecoded() {
    return json_decode(
      trim($this->runCommand('composer-lock-diff --json')->getOutput()),
      TRUE,
    );
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
    $error_ouput = $e instanceof ProcessFailedException ? $e->getProcess()->getErrorOutput() : $e->getMessage();
    $this->output->writeln($error_ouput);
    $this->output->writeln('Updating package FAILED: recovering previous state.');
    $this->output->writeln('!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!');
    $this->runCommand('git checkout composer.json composer.lock');
  }

  /**
   * Shows all the pending updates
   */
  protected function showPendingUpdates() {

    if ($this->getConfiguration()->onlyUpdateSecurities()) {
      $this->printHeader2('Not Updated Securities:');
      $this->output->writeln(
        $this->runCommand('composer audit --locked --format plain 2>&1 | grep ^Package | cut -f2 -d: | sort -u')->getOutput(),
      );

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
        trim($this->runCommand('composer audit --locked --format plain 2>&1 | grep ^Package | cut -f2 -d: | sort -u')->getOutput())
      );
      $this->output->writeln("");
    }
  }

  /**
   * Show updated packages.
   */
  protected function showUpdatedPackages() {
    $updated_packages = $this->runCommand('composer-lock-diff  --from composer.drupalupdater.lock --to composer.lock')->getOutput();
    if (!empty($updated_packages)) {
      $this->output->writeln(
        trim($updated_packages),
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
    foreach ($this->getConfiguration()->getEnvironments() as $environment) {
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
      catch (\RuntimeException $exception) {
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
