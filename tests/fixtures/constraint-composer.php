<?php

file_put_contents('composer-calls.jsonl', json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
$mode = getenv('CONSTRAINT_TEST_MODE');
if ($argv[1] === 'update') {
  if ($mode === 'updated') {
    $lock = json_decode(file_get_contents('composer.lock'), TRUE);
    $lock['packages'][0]['version'] = '1.1.0';
    file_put_contents('composer.lock', json_encode($lock));
  }
  exit(0);
}
if ($argv[1] === 'show') {
  if (in_array('--outdated', $argv)) {
    $latest = $mode === 'current' ? '1.0.0' : ($mode === 'updated' ? '1.1.0' : $mode);
    echo json_encode(['locked' => $mode === 'absent' ? [] : [['name' => 'example/noop', 'version' => '1.0.0', 'latest' => $latest]]]);
  }
  else { echo "type : library\n"; }
  exit(0);
}
fwrite(STDERR, 'Unexpected fixture command: ' . implode(' ', $argv));
exit(90);
