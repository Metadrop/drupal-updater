<?php

/**
 * Reports the unsupported modules.
 */

use Drupal\update\UpdateManagerInterface;
use Symfony\Component\Console\Helper\Table;
use Drush\Drush;

$output = Drush::output();
$updateManager = \Drupal::service('update.manager');
$updateManager->refreshUpdateData();
$projectData = $updateManager->getProjects();

$available_updates = update_get_available(TRUE);
$project_data = update_calculate_project_data($available_updates);

$projects = array_filter($project_data, function (array $project) {
  return $project['status'] === UpdateManagerInterface::NOT_SUPPORTED;
});

if (!empty($projects)) {
  $projects_unsupported_data = array_map(function ($project) {

    // If the recommended version is the current one, then there aren't recommended
    // versions as the module is obsolete.
    $recommended = $project['recommended'] != $project['existing_version']  ? $project['recommended'] : 'None';

    return [
      $project['name'],
      $project['existing_version'],
      $recommended,
    ];
  }, $projects);

  $fixed_drupal_advisories_table = new Table($output);
  $fixed_drupal_advisories_table->setHeaders(['Module', 'Current version', 'Recommended version']);
  $fixed_drupal_advisories_table->setRows($projects_unsupported_data);
  $fixed_drupal_advisories_table->render();
}
else {
  $output->writeln('This project does not contain obsolete modules.');
}
