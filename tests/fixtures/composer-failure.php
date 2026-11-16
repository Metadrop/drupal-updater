<?php

/**
 * @file
 * Controlled failed mutation.
 *
 * Successful recovery invokes real offline Composer.
 */

file_put_contents('composer-calls.jsonl', json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
file_put_contents('retry-events.jsonl', json_encode(['operation' => $argv[1], 'time' => microtime(TRUE)]) . "\n", FILE_APPEND);
$mode = getenv('RECOVERY_TEST_MODE');
if ($argv[1] === 'update') {
  if ($argv[2] !== 'example/fail') {
    file_put_contents('later-package-ran', 'yes');
    exit(0);
  }
  $count = is_file('update-attempts') ? (int) file_get_contents('update-attempts') : 0;
  file_put_contents('update-attempts', (string) ++$count);
  if ($mode === 'timeout-once' && $count === 1) {
    usleep(2000000);
  }
  if (($mode === 'transient-once' && $count > 1) || ($mode === 'transient-twice' && $count > 2) || ($mode === 'timeout-once' && $count > 1)) {
    $root = json_decode(file_get_contents('composer.json'), TRUE);
    if (!isset($root['require']['metadrop/drupal-updater']) || !is_file('vendor/bin/composer-lock-diff')) {
      fwrite(STDERR, 'Pre-attempt working state and tools were not restored');
      exit(92);
    }
    exit(0);
  }
  file_put_contents('composer.json', '{}');
  file_put_contents('composer.lock', '{}');
  foreach (['vendor/metadrop/drupal-updater', 'vendor/davidrjonas/composer-lock-diff'] as $path) {
    remove_fixture($path);
  }
  foreach (['vendor/bin/drupal-updater', 'vendor/bin/composer-lock-diff'] as $path) {
    if (is_file($path) || is_link($path)) {
      unlink($path);
    }
  }
  $reasons = [
    'conflict' => 'Your requirements could not be resolved to an installable set of packages.',
    'auth' => 'HTTP/2 403 authentication failed; curl error 28 also present',
    'unknown' => 'deliberate unclassified failure',
  ];
  fwrite(STDERR, $reasons[$mode] ?? 'curl error 28: deliberate failed mutation');
  exit(1);
}
if ($argv[1] === 'show') {
  if (in_array('--name-only', $argv) && strpos($mode, 'discovery-') === 0) {
    $count = is_file('discovery-attempts') ? (int) file_get_contents('discovery-attempts') : 0;
    file_put_contents('discovery-attempts', (string) ++$count);
    if ($count === 1 || $mode === 'discovery-always') {
      fwrite(STDERR, 'HTTP/2 503 temporary failure');
      exit(1);
    }
    exit(0);
  }
  echo in_array('--outdated', $argv) ? '{"locked":[]}' : (strpos($argv[2], 'drupal/') === 0 ? "type : drupal-module\n" : "type : library\n");
  exit(0);
}
if ($argv[1] === 'audit') {
  exit(0);
}
if ($argv[1] !== 'install') {
  fwrite(STDERR, 'Unexpected fixture operation: ' . $argv[1]);
  exit(90);
}
if (getenv('RECOVERY_TEST_MODE') === 'install-fails') {
  fwrite(STDERR, 'deliberate recovery install failure');
  exit(1);
}
$process = proc_open(
  array_merge([getenv('RECOVERY_REAL_COMPOSER')], array_slice($argv, 1)),
  [0 => STDIN, 1 => STDOUT, 2 => STDERR],
  $pipes
);
if (!is_resource($process)) {
  exit(91);
}
$status = proc_close($process);
if ($status === 0 && getenv('RECOVERY_TEST_MODE') === 'missing-tool') {
  unlink('vendor/bin/drupal-updater');
}
exit($status);

/**
 * Removes a file or directory recursively.
 */
function remove_fixture(string $path): void {
  if (is_dir($path) && !is_link($path)) {
    foreach (new FilesystemIterator($path) as $file) {
      remove_fixture($file->getPathname());
    }
    rmdir($path);
  }
  else {
    unlink($path);
  }
}
