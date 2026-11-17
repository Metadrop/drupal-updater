<?php

namespace DrupalUpdater\Tests\Simulator;

/**
 * Simulates the Drush commands the updater runs, which succeed by default.
 *
 * Calls look like "drush <alias> <command> ..."; there is no Drupal site, so
 * "php-script" of the unsupported modules script reports none.
 */
final class DrushSimulator extends ToolSimulator {

  /**
   * Commands that succeed without output.
   */
  private const SILENT_COMMANDS = [
    'cr',
    'cache:rebuild',
    'updb',
    'updatedb',
    'cim',
    'config:import',
    'cex',
    'config:export',
  ];

  /**
   * {@inheritdoc}
   */
  protected function simulate(Invocation $invocation): int {
    $command = $invocation->arguments()[1] ?? '';
    if (in_array($command, self::SILENT_COMMANDS, TRUE)) {
      return 0;
    }
    if (in_array($command, ['php-script', 'scr'], TRUE) && str_ends_with($invocation->arguments()[2] ?? '', 'unsupported-modules.php')) {
      $invocation->out("[]\n");
      return 0;
    }
    return $this->unsupported($invocation);
  }

}
