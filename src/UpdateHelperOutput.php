<?php

namespace DrupalUpdateHelper;

use Symfony\Component\Console\Output\OutputInterface;

class UpdateHelperOutput
{

  protected OutputInterface $output;

  public function __construct(OutputInterface $output)
  {
    $this->output = $output;
  }

  public function printSummary() {
    $this->printHeader1('Summary');
    $this->output->writeln('1. Consolidating configuration');
    $this->output->writeln('2. Checking packages');
    $this->output->writeln('3. Updating packages');
    $this->output->writeln('4. Report');
    $this->output->writeln('');
  }

  public function printHeader1(string $text) {
    $this->output->writeln(sprintf("// %s //\n", strtoupper($text)));
  }

  public function printHeader2(string $text) {
    $this->output->writeln(sprintf("/// %s ///\n", $text));
  }

}
