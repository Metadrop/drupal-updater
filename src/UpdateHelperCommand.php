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
    $this->addOption('author', 'a', InputOption::VALUE_REQUIRED, 'Git author', 'drupal@update-helper');
    $this->addOption('security', 's', InputOption::VALUE_OPTIONAL, 'Only update security packages');
    $this->addOption('no-dev', 'nd', InputOption::VALUE_OPTIONAL, 'Only update main requirements');
  }

  protected function initialize(InputInterface $input, OutputInterface $output)
  {
    $this->updateHelperOutput = new UpdateHelperOutput($output);
    $this->output = $output;
    $this->environments = explode(',', $input->getOption('environments'));
    $this->commitAuthor = $input->getOption('author');
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
      $this->updateHelperOutput->printSummary();
      $this->updateHelperOutput->printHeader1('1. Consolidating configuration');
      $this->consolidateConfiguration();
      return Command::SUCCESS;
  }

  protected function consolidateConfiguration() {
    $this->runDrushComand('cr');
    $this->runDrushComand('cim -y');

    foreach ($this->environments as $environment) {
      $this->runDrushComand('cex -y');
      $this->runCommand([
        sprintf(
          'git add config && git commit -m "CONFIG - Consolidate current configuration on %s" --author="%s" -n  || echo "No changes to commit"',
          $environment,
          $this->commitAuthor
        )]);
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
      $this->runCommand(['drush', $environment, $command]);
    }
  }

  protected function runCommand(array $command) {
    $process = new Process($command);
    $process->run();
    if (!$process->isSuccessful()) {
      throw new ProcessFailedException($process);
    }
    $this->output->writeln($process->getOutput());
  }

}
