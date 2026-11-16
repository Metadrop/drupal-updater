<?php

/**
 * @file
 * Fake Composer with a controlled outcome for each package.
 */

file_put_contents('composer-calls.jsonl', json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
if ($argv[1] === 'show') {
  if (getenv('REPORT_TEST_MODE') === 'limit-discovery' && in_array('--name-only', $argv)) {
    echo "example/fail\nexample/success\n";
    exit(0);
  }
  if (in_array('--outdated', $argv)) {
    echo json_encode(['locked' => [['name' => 'example/noop', 'version' => '1.0.0', 'latest' => '1.1.0']]]);
  }
  else {
    echo strpos($argv[2], 'drupal/') === 0 ? "type : drupal-module\n" : "type : library\n";
  }
  exit(0);
}
if ($argv[1] === 'install') { exit(0); }
if ($argv[1] === 'audit') { exit(0); }
if ($argv[1] !== 'update') { fwrite(STDERR, 'Unexpected fixture operation'); exit(90); }
$package = $argv[2];
if ($package === 'example/fail') {
  fwrite(STDERR, 'curl error 28: deliberate <error>network outage</error>');
  exit(1);
}
if ($package === 'example/constraint') {
  fwrite(STDERR, 'Your requirements could not be resolved to an installable set of packages.');
  exit(2);
}
if ($package === 'example/noop') {
  exit(0);
}
$lock = json_decode(file_get_contents('composer.lock'), TRUE);
$changed = $package === 'example/later' ? [$package, 'example/fail', 'example/constraint'] : [$package];
if ($package === 'example/later' && getenv('REPORT_TEST_MODE') === 'resolve-noop') {
  $changed[] = 'example/noop';
}
foreach ($lock['packages'] as &$locked) {
  if (in_array($locked['name'], $changed)) {
    $locked['version'] = '1.1.0';
  }
}
unset($locked);
file_put_contents('composer.lock', json_encode($lock, JSON_PRETTY_PRINT));
