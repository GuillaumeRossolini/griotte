<?php

header('Content-Type: text/plain; charset=utf-8', true);


if(empty($_POST['data'])) {
  syslog(LOG_ERR, sp^rintf('Missing "data" field in the request body'));
  http_response_code(400);
  die('ko');
}

$payload = json_decode($_POST['data'], true);
if(false === $payload) {
  syslog(LOG_ERR, sprintf('Input data was not JSON: %s', print_r($_POST['data'], true)));
  http_response_code(400);
  die('ko');
}

$agent_name = null;
$griotte_nb = null;
if(empty($_SERVER['HTTP_USER_AGENT'])) {
  syslog(LOG_ERR, sprintf('Missing the User-Agent header: %s', json_encode($_SERVER)));
  http_response_code(400);
  die('ko');
}

if(!preg_match('~(Griotte)/(\d+)$~', $_SERVER['HTTP_USER_AGENT'], $griotte)) {
  syslog(LOG_ERR, sprintf('Missing the User-Agent header: %s', $_SERVER['HTTP_USER_AGENT']));
  http_response_code(400);
  die('ko');
}

// syslog(LOG_DEBUG, sprintf('Response sent after %0.3fms', microtime(true)-GRIOTTE_STARTTIME));


list($agent_name, $griotte_nb) = explode('/', $_SERVER['HTTP_USER_AGENT']);

$msg = sprintf(
  '%s #%s says: %s',
  $agent_name ?: 'n/a',
  $griotte_nb ?: 'n/a',
  base64_decode($payload['msg'])
);

syslog(LOG_INFO, sprintf('Received payload: %s', $msg));


$pattern = <<<EOT
says:
\s*(\d+)[^;]+;        # pressure
\s*(\d+)[^;]+;        # humidity
\s*(\d+)[^;]+;        # temperature
\s*([0-9.]+)[^;]+     # IAQ
\s*(\d+)[^;]+;        # CO2
\s*([0-9.]+)[^;]+     # VOC
(?:\s*(\d+)[^;]+;)?   # IAQ accuracy
(?:\s*(\d+)[^;]+;)?   # free HEAP
EOT;

if(!preg_match("~$pattern~x", $msg, $readings)) {
  syslog(LOG_ERR, sprintf('Unable to match reading pattern'));
  http_response_code(400);
  die('ko');
}


$readings = array_slice($readings, 1, 6);
$griotte_nb = floatval($griotte_nb);
$readings = array_map('floatval', $readings);

http_response_code(200);
echo 'ok';


if(!file_exists(GRIOTTE_RUN)) {
  syslog(LOG_ERR, sprintf('Run folder not found: %s', GRIOTTE_RUN));
  http_response_code(500);
  die('ko');
}

if(!is_writable(GRIOTTE_RUN)) {
  syslog(LOG_ERR, sprintf('Run folder not writable: %s', GRIOTTE_RUN));
  http_response_code(500);
  die('ko');
}


$buffer_filename = sprintf('%s/buffer.csv', GRIOTTE_FOLDER);

$run_filenames = [
  'buffer' => sprintf('%s/buffer.run', GRIOTTE_RUN),
  'data' => sprintf('%s/%s.run', GRIOTTE_RUN, $griotte_nb),
];

if(!file_exists($run_filenames['data'])) {
  syslog(LOG_DEBUG, sprintf('No run data file for griotte #%s: saving data', $griotte_nb));
  goto buffer;
}

$filemtime = filemtime($run_filenames['data']);
if(false === $filemtime) {
  syslog(LOG_ERR, sprintf('Unable to get run data file stats: %s', $run_filenames['data']));
  http_response_code(500);
  die('ko');
}

if($_SERVER['REQUEST_TIME'] >= ($filemtime + GRIOTTE_NODE_DELAY)) {
  syslog(LOG_DEBUG, sprintf('Stale readings for griotte #%s (last modified at %s): saving new data', $griotte_nb, date('Y-m-d H:i:s', $filemtime)));
  goto buffer;
}


syslog(LOG_DEBUG, sprintf('Readings still valid for griotte #%s (last modified at %s): skipping new data', $griotte_nb, date('Y-m-d H:i:s', $filemtime)));

goto finish;



/**
 * This section appends the incoming readings to a temporary flat file (low resource disk writes, often called),
 * so that they can be inserted into the DBs in batches (higher resource disk writes, seldom called)
 */
buffer:

// also creates the file if it does not exist
$buffer_handle = fopen($buffer_filename, 'a');
if(!$buffer_handle) {
  syslog(LOG_ERR, sprintf('Unable to open buffer file: %s', $buffer_filename));
  http_response_code(500);
  die('ko');
}

$buffer_data = array_merge([$_SERVER['REQUEST_TIME'], $griotte_nb], $readings);
if(!fwrite($buffer_handle, implode("\t", $buffer_data).PHP_EOL)) {
  syslog(LOG_ERR, sprintf('Unable to write to buffer file: %s', $buffer_filename));
  http_response_code(500);
  die('ko');
}

unset($buffer_data);
fclose($buffer_handle);
$filemtime = filemtime($run_filenames['buffer']);

if(false === $filemtime) {
  if(!touch($run_filenames['data'], $_SERVER['REQUEST_TIME'])) {
    syslog(LOG_ERR, sprintf('Unable to create run data file: %s', $run_filenames['data']));
    http_response_code(500);
    die('ko');
  }
}

if($_SERVER['REQUEST_TIME'] >= ($filemtime + GRIOTTE_BUFFER_DELAY)) {
  syslog(LOG_DEBUG, sprintf('Buffer is ready (last modified at %s): committing data', date('Y-m-d H:i:s', $filemtime)));
  goto commit;
}

if(!touch($run_filenames['data'], $_SERVER['REQUEST_TIME'])) {
  syslog(LOG_ERR, sprintf('Unable to create run data file: %s', $run_filenames['data']));
  http_response_code(500);
  die('ko');
}

goto finish;



/**
 * This section reads the buffer flat file, copies its contents into SQL DBs and clears the buffer file
 */
commit:

$buffer_handle = fopen($buffer_filename, 'r');
if(!filesize($buffer_filename)) {
  syslog(LOG_ERR, sprintf('Buffer file is empty: %s', $buffer_filename));
  goto finish;
}

// let's save every reading in a faily database, as well as a giant all-time database
// and also in the databases from the previous and the next day to avoid timezone issues

$yesterday = strtotime('yesterday');
$tomorrow = strtotime('tomorrow');

$db_filenames = [
  sprintf('%s/db/v1/%d/%s/%s.sq3', GRIOTTE_FOLDER, date('Y', $yesterday), date('m-F', $yesterday), date('Y-m-d', $yesterday)),
  sprintf('%s/db/v1/%d/%s/%s.sq3', GRIOTTE_FOLDER, date('Y'), date('m-F'), date('Y-m-d')),
  sprintf('%s/db/v1/%d/%s/%s.sq3', GRIOTTE_FOLDER, date('Y', $tomorrow), date('m-F', $tomorrow), date('Y-m-d', $tomorrow)),
  sprintf('%s/readings.sq3', GRIOTTE_FOLDER),
];

$sql_import = sprintf(
  file_get_contents(__DIR__.'/import.sql'),
  $buffer_filename
);

// prepare the DB file handles and SQL statements
foreach($db_filenames as $_db_idx => $_db_filename) {
  $new_db = !file_exists($_db_filename);

  $_dirname = dirname($_db_filename);
  if(!file_exists($_dirname)) {
    if(!mkdir($_dirname, 0775, true)) {
      syslog(LOG_ERR, sprintf('Unable to create DB folder structure: %s', $_dirname));
      http_response_code(500);
      die('ko');
    }
  }

  if($new_db) {
    chmod($_db_filename, 0664);
  }

  $shellcmd = sprintf(
    'echo %s | sqlite3 %s',
    escapeshellarg($sql_import),
    escapeshellarg($_db_filename)
  );

  // syslog(LOG_DEBUG, sprintf('%s:L%d: command: %s', __FILE__, __LINE__, $shellcmd));

  $output = null;
  $res = null;
  exec($shellcmd, $output, $res);
  syslog(LOG_DEBUG, sprintf('Import result to %s was: exit %d, output: %s', $_db_filename, $res, json_encode($output)));
  if(0 !== $res) {
    syslog(LOG_ERR, sprintf('Unable to import data into %s: %s', $_db_filename, json_encode($output)));
    http_response_code(500);
    die('ko');
  }
}

fclose($buffer_handle);
if(!fopen($buffer_filename, 'w')) {
  syslog(LOG_ERR, sprintf('Unable to truncate buffer file: %s', $buffer_filename));
  http_response_code(500);
  die('ko');
}

if(!touch($run_filenames['buffer'], $_SERVER['REQUEST_TIME'])) {
  syslog(LOG_ERR, sprintf('Unable to create run buffer file: %s', $run_filenames['data']));
  http_response_code(500);
  die('ko');
}

if(!touch($run_filenames['data'], $_SERVER['REQUEST_TIME'])) {
  syslog(LOG_ERR, sprintf('Unable to create run data file: %s', $run_filenames['data']));
  http_response_code(500);
  die('ko');
}

goto finish;



/**
 * End of the script
 */
finish:
exit;

