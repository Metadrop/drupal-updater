<?php

if (getenv('REPORT_TEST_MODE') === 'fatal-report' && in_array('--from', $argv)) {
  fwrite(STDERR, 'deliberate final report failure');
  exit(1);
}
$process = proc_open(
  array_merge([getenv('REPORT_REAL_DIFF')], array_slice($argv, 1)),
  [0 => STDIN, 1 => STDOUT, 2 => STDERR],
  $pipes
);
if (!is_resource($process)) { exit(91); }
exit(proc_close($process));
