<?php

/**
 * Reports the unsupported modules.
 */

use Drupal\update\UpdateManagerInterface;

$module_handler = \Drupal::moduleHandler();

if (!$module_handler->moduleExists('update')) {
  throw new Exception("Unable to report unsupported modules as Update module is not enabled in the site.");
}

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
      'project_name' => $project['name'],
      'current_version' => $project['existing_version'],
      'recommended_version' => $recommended,
    ];
  }, $projects);

}
else {

  $projects_unsupported_data = [];
}

print json_encode($projects_unsupported_data);


