<?php

namespace DrupalUpdater;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

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
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->runCommand('cp composer.lock composer.drupalupdater.lock');
    $this->printSummary();
    $this->printHeader1('1. Consolidating configuration');
    $this->consolidateConfiguration();
    $this->printHeader1('2. Checking outdated packages');
    $this->checkOutdatedPackages();
    $this->output->writeln('');
    $this->printHeader1('3. Updating packages');
    $this->updatePackages($this->packagesToUpdate);
    $this->output->writeln('');
    $this->printHeader1('4. Report');
    $this->report();
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
  protected function checkOutdatedPackages() {
    if ($this->onlySecurity) {
      $packages_to_update = $this->runCommand(sprintf('composer audit --locked %s --format plain 2>&1 | grep ^Package | cut -f2 -d: | sort -u', $this->getNoDevParameter()))->getOutput();
      try {
        $drupal_security_packages = $this->runCommand('./vendor/bin/drush pm:security --fields=name --format=list 2>/dev/null')
          ->getOutput();
      }
      catch (ProcessFailedException $e) {}

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
      $this->runComposer('update', [$package, '--with-dependencies']);
    }
    catch (\Exception $e) {
      $this->output->writeln("\n!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!");
      $this->output->writeln($e->getProcess()->getErrorOutput());
      $this->output->writeln('Updating package FAILED: recovering previous state.');
      $this->output->writeln('!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!');
      $this->runCommand('git checkout composer.json composer.lock');
      return;
    }

    $composer_lock_is_changed = (int) $this->runCommand('git status --porcelain composer.lock | wc -l')->getOutput() > 0;

    if (!$composer_lock_is_changed) {
      $this->output->writeln(sprintf('Package %s has not been updated', $package));
      return;
    }

    $this->output->writeln("\nUpdated packages:");
    $this->output->writeln(
      $this->runCommand('composer-lock-diff')->getOutput(),
    );

    $this->runCommand('git add composer.json composer.lock');

    if ($this->isDrupalExtension($package)) {
      $this->runCommand('git add web');
      $this->runDrushCommand('cr');
      $this->runDrushCommand('updb -y');
      $this->runDrushCommand('cex -y');
      $this->runCommand('git add config');
    }

    $this->runCommand(sprintf('git commit -m "UPDATE - %s" --author="%s" -n', $package, $this->commitAuthor));

  }

  /**
   * Shows a report.
   *
   * The report contains:
   *   - All the updated packages.
   *   - All the new packages (sub-dependencies).
   *   - Pending updates.
   */
  protected function report() {
    $this->output->writeln(
      $this->runCommand('composer-lock-diff  --from composer.lock --to  composer.drupalupdater.lock')->getOutput(),
    );

    $this->runCommand('rm composer.drupalupdater.lock');

    if ($this->onlySecurity) {
      $this->printHeader2('Not Updated Securities (Packagist):');
      $this->output->writeln(
        $this->runCommand('composer audit --locked $update_no_dev --format plain 2>&1 | grep ^Package | cut -f2 -d: | sort -u')->getOutput(),
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
