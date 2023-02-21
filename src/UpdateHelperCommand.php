<?php

namespace DrupalUpdater;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class UpdateHelperCommand extends Command {

  protected static $defaultName = 'update';

  protected UpdateHelperOutput $updateHelperOutput;

  protected OutputInterface $output;

  protected bool $onlySecurity;

  protected bool $noDev;

  protected array $environments;

  protected string $commitAuthor;

  protected array $packagesToUpdate;

  protected array $updatedPackages = [];

  protected function configure()
  {
    $this->setHelp('Update composer packages.

Update includes:
  - Commit current configuration not exported (Drupal +8).
  - Identify updatable composer packages (outdated)
  - For each package try to update and commit it (recovers previous state if fails)');
    $this->addOption('environments', 'envs', InputOption::VALUE_REQUIRED,'List of drush aliases that are needed to update', '@self');
    $this->addOption('author', 'a', InputOption::VALUE_REQUIRED, 'Git author', 'Drupal <drupal@update-helper>');
    $this->addOption('security', 's', InputOption::VALUE_OPTIONAL, 'Only update security packages', 0);
    $this->addOption('no-dev', 'nd', InputOption::VALUE_OPTIONAL, 'Only update main requirements', 0);
  }

  protected function initialize(InputInterface $input, OutputInterface $output)
  {
    $this->updateHelperOutput = new UpdateHelperOutput($output);
    $this->output = $output;
    $this->environments = explode(',', $input->getOption('environments'));
    $this->commitAuthor = $input->getOption('author');
    $this->onlySecurity = (bool) $input->getOption('security');
    $this->noDev = (bool) $input->hasOption('no-dev') == 1;
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
      $this->updateHelperOutput->printSummary();
      $this->updateHelperOutput->printHeader1('1. Consolidating configuration');
      $this->consolidateConfiguration();
      $this->updateHelperOutput->printHeader1('2. Checking outdated packages');
      $this->checkOutdatedPackages();
      $this->updateHelperOutput->printHeader1('3. Updating packages');
      $this->updatePackages($this->packagesToUpdate);
      return 0;
  }

  protected function runDrushComand(string $command, array $environments = []) {
    if (empty($environments)) {
      $environments = $this->environments;
    }

    foreach ($environments as $environment) {
      $this->output->writeln(sprintf("Running drush %s on the \"%s\" environment:\n", $command, $environment));
      $this->runCommand(sprintf('drush %s %s', $environment, $command));
    }

  }

  protected function runCommand(string $command) {
    $process = Process::fromShellCommandline($command);
    $process->run();
    if (!$process->isSuccessful()) {
      throw new ProcessFailedException($process);
    }
    return $process;
  }

  protected function runComposer(string $command, array $parameters) {
    return $this->runCommand(sprintf('composer %s %s', $command, implode(' ', $parameters)));
  }

  protected function getNoDevParameter(){
    return $this->noDev ? '--no-dev' : '';
  }

  protected function consolidateConfiguration() {
    $this->runDrushComand('cr');
    $this->runDrushComand('cim -y');

    foreach ($this->environments as $environment) {
      $this->output->writeln(sprintf('Consolidating %s environment', $environment));
      $this->runDrushComand('cex -y');
      $this->runCommand(sprintf(
        'git add config && git commit -m "CONFIG - Consolidate current configuration on %s" --author="%s" -n || echo "No changes to commit"',
        $environment,
        $this->commitAuthor
      ));
    }

    $this->runDrushComand('cr');
    $this->runDrushComand('cim -y');
  }

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
    $this->packagesToUpdate = array_filter($this->packagesToUpdate, function ($package) {
      return preg_match('/^([A-Za-z0-9_-]*\/[A-Za-z0-9_-]*)/', $package);
    });
    $this->packagesToUpdate = array_map(function ($package) {
      return trim($package);
    }, $this->packagesToUpdate);

    $this->output->writeln(implode("\n", $this->packagesToUpdate));
  }

  protected function updatePackages(array $package_list) {
    foreach ($package_list as $package) {
      $this->updatePackage($package);
    }
  }

  protected function updatePackage(string $package) {
    $this->updateHelperOutput->printHeader2(sprintf('Updating: %s', $package));
    $version_from = trim($this->runCommand(sprintf("composer show --locked %s | grep versions | awk '{print $4}'", $package))->getOutput());
    try {
      $this->runComposer('update', [$package, '--with-dependencies']);
    }
    catch (ProcessFailedException $e) {
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

    $version_to = trim($this->runCommand(sprintf("composer show --locked %s | grep versions | awk '{print $4}'", $package))->getOutput());
    $this->runCommand('git add composer.json composer.lock');

    if ($this->isDrupalExtension($package)) {
      $this->runCommand('git add web');
      $this->runDrushComand('cr');
      $this->runDrushComand('updb -y');
      $this->runDrushComand('cex -y');
      $this->runCommand('git add config');
    }

    $this->runCommand(sprintf('git commit -m "UPDATE - %s" --author="%s" -n', $package, $this->commitAuthor));

    if ($version_from != $version_to) {
      $this->output->writeln(sprintf('Package %s has been updated from %s to %s', $package, $version_from, $version_to));
    }

  }

  protected function report(array $package_list) {

    if (!empty($this->updatedPackages)) {
      // @todo Composer lock diff!
    }
    else {
      $this->output->writeln('No modules / packages have been updated');
    }

    if ($this->onlySecurity) {
      $this->updateHelperOutput->printHeader2('Not Updated Securities (Packagist):');
      $this->output->writeln(
        $this->runCommand('composer audit --locked $update_no_dev --format plain 2>&1 | grep ^Package | cut -f2 -d: | sort -u')->getOutput(),
      );

      $this->updateHelperOutput->printHeader2('Not Updated Securities (Drupal):');
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
      $this->updateHelperOutput->printHeader2('Not Updated Packages (Direct):');
      $this->output->writeln(
        $this->runCommand('composer show --locked --outdated --direct')->getOutput()
      );

      $this->updateHelperOutput->printHeader2('Not Updated Securities (ALL):');
      $this->output->writeln(
        $this->runCommand('composer show --locked --outdated')->getOutput()
      );

    }
  }

  protected function isDrupalExtension(string $package) {
    $package_type = $this->runCommand(sprintf("composer show %s | grep ^type | awk '{print $3}'", $package))->getOutput();
    return $package_type != 'drupal-library' && str_starts_with($package_type, 'drupal');
  }

  protected function getPackagesToUpdate() {
    return $this->packagesToUpdate;
  }

}
