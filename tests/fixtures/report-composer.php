<?php

$mode = getenv('PENDING_REPORT_MODE');
if ($argv[1] === 'update') { exit(0); }
if ($argv[1] === 'audit') {
  if ($mode === 'populated') { echo "Package: example/vulnerable\n"; exit(1); }
  exit(0);
}
if ($argv[1] === 'show' && in_array('--outdated', $argv)) {
  if (in_array('json', $argv)) { echo '{"locked":[]}'; exit(0); }
  if ($mode === 'failed') { fwrite(STDERR, 'REPORT_FAILED'); exit(2); }
  if ($mode === 'populated') {
    echo "example/direct 1.0.0 1.1.0\n";
    if (!in_array('--direct', $argv)) { echo "example/transitive 1.0.0 1.1.0\n"; }
  }
  else { echo " \t\n"; }
  exit(0);
}
fwrite(STDERR, 'Unexpected fixture command: ' . implode(' ', $argv));
exit(90);
