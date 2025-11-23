<?php

/**
 * How to read this script: follow the goto's
 */

header('Content-Type: text/plain; charset=utf-8', true); // default response type

goto selftest;



/**
 * This section guards agains permission and payload structure errors
 */
selftest:

if(empty($_POST['struct'])) {
  trace(LOG_ERR, 'Missing "struct" field in the request body: %s', json_encode(array_keys($_POST)) ?: 'n/a');
  http(400, 'ko');
  exit;
}

if(!in_array($_POST['struct'], GRIOTTE_STRUCT_ALLOWLIST, true)) {
  trace(
    LOG_ERR,
    'Payload was %s but can only be one of (%d): %s',
    json_encode($_POST['struct']),
    count(GRIOTTE_STRUCT_ALLOWLIST),
    implode(', ', GRIOTTE_STRUCT_ALLOWLIST)
  );

  http(400, 'ko');
  exit;
}

$payload_struct = $_POST['struct']; // this appears both as a value and as a field
if(empty($_POST[$payload_struct])) {
  trace(LOG_ERR, 'Missing "%s" field in the request body: %s', $payload_struct, json_encode(array_keys($_POST)) ?: 'n/a');
  http(400, 'ko');
  exit;
}

$payload = json_decode($_POST[$payload_struct], true);
if(false === $payload) {
  trace(LOG_ERR, 'Input data was not JSON: %s', json_encode($_POST[$payload_struct]));
  http(400, 'ko');
  exit;
}

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

foreach(['GRIOTTE_RUN', 'GRIOTTE_DB'] as $_constant) {
  if(!file_exists(constant($_constant))) {
    trace(LOG_ERR, 'Folder %s not found: %s', $_constant, constant($_constant));
    http(500, 'ko');
    exit;
  }

  if(!is_writable(constant($_constant))) {
    trace(LOG_ERR, 'Folder %s not writable: %s', $_constant, constant($_constant));
    http(500, 'ko');
    exit;
  }
}

goto receive;



/**
 * This section decodes the payload
 */
receive:

$gateway_uptime = null;
if(!empty($_POST['uptime'])) {
  $gateway_uptime = (int) $_POST['uptime'];
}

$gateway_signal = null;
if(!empty($_POST['signal'])) {
  $gateway_signal = (int) $_POST['signal'];
}

$agent_name = null;
$griotte_nb = null;
list($agent_name, $griotte_nb) = explode('/', $_SERVER['HTTP_USER_AGENT']);

$msg = sprintf(
  '%s #%s (%d dB) says: %s',
  $agent_name ?: 'n/a',
  $griotte_nb ?: 'n/a',
  $gateway_signal ?: 'n/a',
  base64_decode($payload['msg'])
);

trace(LOG_INFO, 'Received %s payload: %s', $payload_struct, $msg);


switch($payload_struct) {
  case 'bme680';
    goto bme680;
    break;

  default: // shoudn't happen: already handled in the self-test
    trace(LOG_ERR, 'Payload type "%s" is not handled yet', $payload_struct);
    http(400, 'ko');
    exit;

}





/**
 * This section parses the bme680 payload into fields
 */
bme680:

$nb_fields = 12; // hardcoded from the regexp below

$pattern = <<<EOT
says:\s*
(\d+)        # pressure
;(\d+)       # humidity
;(\d+)       # temperature
;([0-9.]+)   # IAQ
;(\d+)       # eCO2
;([0-9.]+)   # VOC
;(\d+)       # IAQ accuracy
;(\d+)       # free HEAP
;([0-9.]+)   # mesh IP
;(\d+)       # mesh nb subs
;(\d+)       # mesh stability
;(\d+)       # uptime
EOT;

if(!preg_match("~$pattern~x", $msg, $readings)) {
  trace(LOG_ERR, 'Unable to match reading pattern');
  http(400, 'ko');
  exit;
}


$readings = array_pad($readings, $nb_fields, 'NULL');
$readings = array_slice($readings, 1, $nb_fields);
if(!empty($readings[8])) {
  $readings[8] = ip2long($readings[8]);
}

$readings = array_map('floatval', $readings);
$griotte_nb = floatval($griotte_nb);

http(200); // presume OK until told otherwise



$run_filenames = [
  'buffer' => sprintf('%s/buffer.run', GRIOTTE_RUN),
  'bme680' => sprintf('%s/%s.run', GRIOTTE_RUN, $griotte_nb),
];

if(!file_exists($run_filenames['bme680'])) {
  trace(LOG_DEBUG, 'No run bme680 file for node #%s: saving data', $griotte_nb);
  goto buffer;
}

$filemtime = filemtime($run_filenames['bme680']);
if(false === $filemtime) {
  trace(LOG_ERR, 'Unable to get run bme680 file stats: %s', $run_filenames['bme680']);
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

$buffer_filename = sprintf('%s/buffer.csv', GRIOTTE_RUN);

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
  if(!touch($run_filenames['bme680'], $_SERVER['REQUEST_TIME'])) {
    trace(LOG_ERR, 'Unable to create run bme680 file: %s', $run_filenames['bme680']);
    http(500, 'ko');
    exit;
  }
}

if($_SERVER['REQUEST_TIME'] >= ($filemtime + GRIOTTE_BUFFER_DELAY)) {
  trace(LOG_DEBUG, 'Buffer is ready (last modified at %s): committing data', date('Y-m-d H:i:s', $filemtime));
  goto commit;
}

if(!touch($run_filenames['bme680'], $_SERVER['REQUEST_TIME'])) {
  trace(LOG_ERR, 'Unable to create run bme680 file: %s', $run_filenames['bme680']);
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

// let's save every reading in a daily database, as well as a giant all-time database
// and also in the databases from the previous and the next day to avoid timezone issues

$yesterday = strtotime('yesterday');
$tomorrow = strtotime('tomorrow');

$db_filenames = [
  sprintf(GRIOTTE_DAILY_DB_TPL, GRIOTTE_DB, date('Y', $yesterday), date('m-F', $yesterday), date('Y-m-d', $yesterday)),
  sprintf(GRIOTTE_DAILY_DB_TPL, GRIOTTE_DB, date('Y'), date('m-F'), date('Y-m-d')),
  sprintf(GRIOTTE_DAILY_DB_TPL, GRIOTTE_DB, date('Y', $tomorrow), date('m-F', $tomorrow), date('Y-m-d', $tomorrow)),
  sprintf('%s/readings.sq3', GRIOTTE_DB),
];

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
    'cat %s | sqlite3 %s',
    escapeshellarg(__DIR__.'/import.sql'),
    escapeshellarg($_db_filename)
  );

  // trace(LOG_DEBUG, '%s:L%d: command: %s', __FILE__, __LINE__, $shellcmd);

  $output = null;
  $res = null;
  exec($shellcmd, $output, $res);
  trace(LOG_DEBUG, 'Import result to %s was: exit %d, output: %s', $_db_filename, $res, json_encode($output));
  if(0 !== $res) {
    trace(LOG_ERR, 'Unable to import data into %s: %s; cmd was: %s', $_db_filename, json_encode($output), $shellcmd);

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
  trace(LOG_ERR, 'Unable to create run buffer file: %s', $run_filenames['bme680']);
  http(500, 'ko');
  exit;
}

if(!touch($run_filenames['bme680'], $_SERVER['REQUEST_TIME'])) {
  trace(LOG_ERR, 'Unable to create run data file: %s', $run_filenames['bme680']);
  http(500, 'ko');
  exit;
}

goto finish;



/**
 * End of the script
 */
finish:
// trace(LOG_DEBUG, 'Response sent after %0.3fms', microtime(true)-GRIOTTE_STARTTIME);
http(201, 'ok');
exit;
