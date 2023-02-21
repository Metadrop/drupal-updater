<?php

namespace DrupalUpdateHelper;

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

  protected function configure()
  {
    $this->setHelp('Update composer packages.

Update includes:
  - Commit current configuration not exported (Drupal +8).
  - Identify updatable composer packages (outdated)
  - For each package try to update and commit it (recovers previous state if fails)');
    $this->addOption('environments', 'envs', InputOption::VALUE_REQUIRED,'List of drush aliases that are needed to update', '@self');
    $this->addOption('author', 'a', InputOption::VALUE_REQUIRED, 'Git author', 'Drupal <drupal@update-helper>');
    $this->addOption('security', 's', InputOption::VALUE_OPTIONAL, 'Only update security packages');
    $this->addOption('no-dev', 'nd', InputOption::VALUE_OPTIONAL, 'Only update main requirements');
  }

  protected function initialize(InputInterface $input, OutputInterface $output)
  {
    $this->updateHelperOutput = new UpdateHelperOutput($output);
    $this->output = $output;
    $this->environments = explode(',', $input->getOption('environments'));
    $this->commitAuthor = $input->getOption('author');
    $this->onlySecurity = $input->hasOption('security');
    $this->noDev = $input->hasOption('no-dev');
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
      $this->updateHelperOutput->printSummary();
      $this->updateHelperOutput->printHeader1('1. Consolidating configuration');
      $this->consolidateConfiguration();
      $this->updateHelperOutput->printHeader1('2. Checking outdated packages');
      $this->checkOutdatedPackages();
      return 0;
  }

  protected function consolidateConfiguration() {
    $this->runDrushComand('cr');
    $this->runDrushComand('cim -y');

    foreach ($this->environments as $environment) {
      $this->runDrushComand('cex -y');
      $process = $this->runCommand(sprintf(
        'git add config && git commit -m "CONFIG - Consolidate current configuration on %s" --author="%s" -n || echo "No changes to commit"',
        $environment,
        $this->commitAuthor
      ));
    }

    $this->runDrushComand('cr');
    $this->runDrushComand('cim -y');
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
    $process_output = $process->getOutput();
    $this->output->writeln($process_output);
    return $process_output;
  }

  protected function runComposer(string $command, array $parameters) {
    return $this->runCommand(sprintf('composer %s %s', $command, implode(' ', $parameters)));
  }

  protected function getNoDevParamter(){
    return $this->noDev ? '--no-dev' : '';
  }

  protected function checkOutdatedPackages() {
    if ($this->onlySecurity) {
      $packages_to_update = $this->runCommand(sprintf('composer audit --locked %s --format plain 2>&1 | grep ^Package | cut -f2 -d: | sort -u', $this->getNoDevParamter()));
      try {
        $drupal_security_packages = $this->runCommand('./vendor/bin/drush pm:security --fields=name --format=list 2>/dev/null');
      }
      catch (ProcessFailedException $e) {}

      $packages_to_update = sprintf("%s\n%s", $packages_to_update, $drupal_security_packages);
    }
    else {
      $packages_to_update = $this->runCommand('composer show --locked --direct --name-only $update_no_dev 2>/dev/null');
    }

    $packages_to_update_list = explode("\n", $packages_to_update);
    $packages_to_update_list = array_filter($packages_to_update_list, function ($package) {
      return preg_match('/^([A-Z0-9_-]*\/[A-Z0-9_-]*)/', $package);
    });

    $this->output->writeln($packages_to_update_list);
  }


}
