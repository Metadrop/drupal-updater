<?php

// Controlled failed mutation; successful recovery invokes real offline Composer.
file_put_contents('composer-calls.jsonl', json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
if ($argv[1] === 'update') {
  if ($argv[2] !== 'example/fail') {
    file_put_contents('later-package-ran', 'yes');
    exit(0);
  }
  file_put_contents('composer.json', '{}');
  file_put_contents('composer.lock', '{}');
  foreach (['vendor/metadrop/drupal-updater', 'vendor/davidrjonas/composer-lock-diff'] as $path) {
    removeFixture($path);
  }
  foreach (['vendor/bin/drupal-updater', 'vendor/bin/composer-lock-diff'] as $path) {
    if (is_file($path) || is_link($path)) { unlink($path); }
  }
  fwrite(STDERR, 'curl error 28: deliberate failed mutation');
  exit(1);
}
if ($argv[1] === 'show') {
  echo '{"locked":[]}';
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
if (!is_resource($process)) { exit(91); }
$status = proc_close($process);
if ($status === 0 && getenv('RECOVERY_TEST_MODE') === 'missing-tool') {
  unlink('vendor/bin/drupal-updater');
}
exit($status);

function removeFixture(string $path): void {
  if (is_dir($path) && !is_link($path)) {
    foreach (new FilesystemIterator($path) as $file) { removeFixture($file->getPathname()); }
    rmdir($path);
  }
  else { unlink($path); }
}
