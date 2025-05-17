<?php

header('Content-Type: text/plain; charset=utf-8', true);


if(empty($_POST['data'])) {
  trace(LOG_ERR, 'Missing "data" field in the request body');
  http(400, 'ko');
  exit;
}

$payload = json_decode($_POST['data'], true);
if(false === $payload) {
  trace(LOG_ERR, 'Input data was not JSON: %s', print_r($_POST['data'], true));
  http(400, 'ko');
  exit;
}

$agent_name = null;
$griotte_nb = null;
if(empty($_SERVER['HTTP_USER_AGENT'])) {
  trace(LOG_ERR, 'Missing the User-Agent header: %s', json_encode($_SERVER));
  http(400, 'ko');
  exit;
}

$regexp = sprintf('~(%s)/(\d+)$~', preg_quote(GRIOTTE_LABEL));
if(!preg_match($regexp, $_SERVER['HTTP_USER_AGENT'], $griotte)) {
  trace(LOG_ERR, 'Incorrect User-Agent header: %s (%s)', $_SERVER['HTTP_USER_AGENT'], $regexp);
  http(400, 'ko');
  exit;
}

// trace(LOG_DEBUG, 'Response sent after %0.3fms', microtime(true)-GRIOTTE_STARTTIME);


list($agent_name, $griotte_nb) = explode('/', $_SERVER['HTTP_USER_AGENT']);

$msg = sprintf(
  '%s #%s says: %s',
  $agent_name ?: 'n/a',
  $griotte_nb ?: 'n/a',
  base64_decode($payload['msg'])
);

trace(LOG_INFO, 'Received payload: %s', $msg);


$pattern = <<<EOT
says:
\s*(\d+)\s+[^;]+        # pressure
;\s*(\d+).\s+[^;]+      # humidity
;\s*(\d+)\s+[^;]+       # temperature
;\s*([0-9.]+)\s+[^;]+   # IAQ
;\s*(\d+)\s+[^;]+       # eCO2
;\s*([0-9.]+)\s[^;]+    # VOC
(?:;\s*(\d+)\s+[^;]+)?  # IAQ accuracy
(?:;\s*(\d+)\s+[^;]+)?  # free HEAP
EOT;

if(!preg_match("~$pattern~x", $msg, $readings)) {
  trace(LOG_ERR, 'Unable to match reading pattern');
  http(400, 'ko');
  exit;
}


$readings = array_slice($readings, 1, 8);
$readings = array_map('floatval', $readings);
$readings = array_pad($readings, 8, 'NULL');
$griotte_nb = floatval($griotte_nb);

http(200, 'ok');


if(!file_exists(GRIOTTE_RUN)) {
  trace(LOG_ERR, 'Run folder not found: %s', GRIOTTE_RUN);
  http(500, 'ko');
  exit;
}

if(!is_writable(GRIOTTE_RUN)) {
  trace(LOG_ERR, 'Run folder not writable: %s', GRIOTTE_RUN);
  http(500, 'ko');
  exit;
}


$buffer_filename = sprintf('%s/buffer.csv', GRIOTTE_FOLDER);

$run_filenames = [
  'buffer' => sprintf('%s/buffer.run', GRIOTTE_RUN),
  'data' => sprintf('%s/%s.run', GRIOTTE_RUN, $griotte_nb),
];

if(!file_exists($run_filenames['data'])) {
  trace(LOG_DEBUG, 'No run data file for node #%s: saving data', $griotte_nb);
  goto buffer;
}

$filemtime = filemtime($run_filenames['data']);
if(false === $filemtime) {
  trace(LOG_ERR, 'Unable to get run data file stats: %s', $run_filenames['data']);
  http(500, 'ko');
  exit;
}

if($_SERVER['REQUEST_TIME'] >= ($filemtime + GRIOTTE_NODE_DELAY)) {
  trace(LOG_DEBUG, 'Stale readings for node #%s (last modified at %s): saving new data', $griotte_nb, date('Y-m-d H:i:s', $filemtime));
  goto buffer;
}


trace(LOG_DEBUG, 'Readings still valid for node #%s (last modified at %s): skipping new data', $griotte_nb, date('Y-m-d H:i:s', $filemtime));

goto finish;



/**
 * This section appends the incoming readings to a temporary flat file (low resource disk writes, often called),
 * so that they can be inserted into the DBs in batches (higher resource disk writes, seldom called)
 */
buffer:

// also creates the file if it does not exist
$buffer_handle = fopen($buffer_filename, 'a');
if(!$buffer_handle) {
  trace(LOG_ERR, 'Unable to open buffer file: %s', $buffer_filename);
  http(500, 'ko');
  exit;
}

$buffer_data = array_merge([$_SERVER['REQUEST_TIME'], $griotte_nb], $readings);
if(!fwrite($buffer_handle, implode("\t", $buffer_data).PHP_EOL)) {
  trace(LOG_ERR, 'Unable to write to buffer file: %s', $buffer_filename);
  http(500, 'ko');
  exit;
}

unset($buffer_data);
fclose($buffer_handle);
$filemtime = filemtime($run_filenames['buffer']);

if(false === $filemtime) {
  if(!touch($run_filenames['data'], $_SERVER['REQUEST_TIME'])) {
    trace(LOG_ERR, 'Unable to create run data file: %s', $run_filenames['data']);
    http(500, 'ko');
    exit;
  }
}

if($_SERVER['REQUEST_TIME'] >= ($filemtime + GRIOTTE_BUFFER_DELAY)) {
  trace(LOG_DEBUG, 'Buffer is ready (last modified at %s): committing data', date('Y-m-d H:i:s', $filemtime));
  goto commit;
}

if(!touch($run_filenames['data'], $_SERVER['REQUEST_TIME'])) {
  trace(LOG_ERR, 'Unable to create run data file: %s', $run_filenames['data']);
  http(500, 'ko');
  exit;
}

goto finish;



/**
 * This section reads the buffer flat file, copies its contents into SQL DBs and clears the buffer file
 */
commit:

$buffer_handle = fopen($buffer_filename, 'r');
if(!filesize($buffer_filename)) {
  trace(LOG_ERR, 'Buffer file is empty: %s', $buffer_filename);
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
      trace(LOG_ERR, 'Unable to create DB folder structure: %s', $_dirname);
      http(500, 'ko');
      exit;
    }
  }

  if($new_db) {
    touch($_db_filename);
    chmod($_db_filename, 0664);
  }

  $shellcmd = sprintf(
    'echo %s | sqlite3 %s',
    escapeshellarg($sql_import),
    escapeshellarg($_db_filename)
  );

  // trace(LOG_DEBUG, '%s:L%d: command: %s', __FILE__, __LINE__, $shellcmd);

  $output = null;
  $res = null;
  exec($shellcmd, $output, $res);
  trace(LOG_DEBUG, 'Import result to %s was: exit %d, output: %s', $_db_filename, $res, json_encode($output));
  if(0 !== $res) {
    trace(LOG_ERR, 'Unable to import data into %s: %s', $_db_filename, json_encode($output));
    http(500, 'ko');
    exit;
  }
}

fclose($buffer_handle);
if(!fopen($buffer_filename, 'w')) {
  trace(LOG_ERR, 'Unable to truncate buffer file: %s', $buffer_filename);
  http(500, 'ko');
  exit;
}

if(!touch($run_filenames['buffer'], $_SERVER['REQUEST_TIME'])) {
  trace(LOG_ERR, 'Unable to create run buffer file: %s', $run_filenames['data']);
  http(500, 'ko');
  exit;
}

if(!touch($run_filenames['data'], $_SERVER['REQUEST_TIME'])) {
  trace(LOG_ERR, 'Unable to create run data file: %s', $run_filenames['data']);
  http(500, 'ko');
  exit;
}

goto finish;



/**
 * End of the script
 */
finish:
exit;

