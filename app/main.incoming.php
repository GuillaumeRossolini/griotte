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

if(empty($_SERVER['HTTP_USER_AGENT'])) {
  trace(LOG_ERR, 'Missing the User-Agent header: %s', json_encode($_SERVER));
  http(400, 'ko');
  exit;
}

$regexp = sprintf('~(%s)/(\d+)~', preg_quote(GRIOTTE_LABEL));
if(!preg_match($regexp, $_SERVER['HTTP_USER_AGENT'], $griotte_agent)) {
  trace(LOG_ERR, 'User-Agent "%s" does not match pattern: %s', $_SERVER['HTTP_USER_AGENT'], $regexp);
  http(400, 'ko');
  exit;
}

dbg(__FILE__, __LINE__, 'identified griotte %s', $griotte_agent[0]);
$agent_name = null;
$griotte_nb = null;
list(, $agent_name, $griotte_nb) = $griotte_agent;
$griotte_nb = floatval($griotte_nb);

if(empty($_POST['struct'])) {
  trace(
    LOG_ERR,
    'Missing "struct" field from #%d: %s',
    $griotte_nb,
    json_encode(array_keys($_POST)) ?: 'n/a'
  );

  http(400, 'ko');
  exit;
}

if(!in_array($_POST['struct'], GRIOTTE_STRUCT_ALLOWLIST, true)) {
  trace(
    LOG_ERR,
    'Payload from #%d was %s but can only be one of (%d): %s',
    $griotte_nb,
    json_encode($_POST['struct']),
    count(GRIOTTE_STRUCT_ALLOWLIST),
    implode(', ', GRIOTTE_STRUCT_ALLOWLIST)
  );

  http(400, 'ko');
  exit;
}

$payload_struct = $_POST['struct']; // this appears both as a value and as a field
if(!array_key_exists($payload_struct, $_POST)) {
  trace(LOG_ERR, 'Missing "%s" field from #%d: %s', $payload_struct, $griotte_nb, json_encode(array_keys($_POST)) ?: 'n/a');
  http(400, 'ko');
  exit;
}

$payload = json_decode($_POST[$payload_struct], true);
if(false === $payload) {
  trace(LOG_ERR, 'Input data from #% was not JSON: %s', $griotte_nb, json_encode($_POST[$payload_struct]));
  http(400, 'ko');
  exit;
}

if(empty($payload['msg'])) {
  trace(LOG_ERR, 'Empty payload msg from #%: %s', $griotte_nb, json_encode($payload));
  http(400, 'ko');
  exit;
}

foreach(['GRIOTTE_RUN_PATH', 'GRIOTTE_DB_PATH'] as $_constant) {
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

$gateway_signal = null;
if(!empty($_POST['signal'])) {
  $gateway_signal = (int) $_POST['signal'];
}

define('GRIOTTE_SERIAL_NB', $griotte_nb);
define('GRIOTTE_PAYLOAD_STRUCT', $payload_struct);
define('GRIOTTE_PAYLOAD_RAW', base64_decode($payload['msg']));

$msg = sprintf(
  '%s #%s (%d dB) says: %s',
  $agent_name ?: 'n/a',
  GRIOTTE_SERIAL_NB ?: 'n/a',
  $gateway_signal ?: 'n/a',
  GRIOTTE_PAYLOAD_RAW
);

trace(LOG_INFO, 'Received %s payload: %s', GRIOTTE_PAYLOAD_STRUCT, $msg);
dbg(__FILE__, __LINE__, 'Received %s payload: %s', GRIOTTE_PAYLOAD_STRUCT, $msg);


switch(GRIOTTE_PAYLOAD_STRUCT) {
  case 'bme680':
    goto bme680;
    break;

  case 'typology':
    goto typology;
    break;

  default: // shouldn't happen: already handled by the selftest
    finish(400, 'never mind');
}




/**
 * This section parses the typology payload into fields
 */
typology:

$typology = json_decode(GRIOTTE_PAYLOAD_RAW, true);
if(false === $typology) {
  trace(LOG_ERR, 'Unable to parse mesh typology');
  http(400, 'ko');
  exit;
}

trace(LOG_ERR, 'Ignoring incoming mesh typology payload');
finish(200, 'thanks');



/**
 * This section parses the bme680 payload into fields
 */
bme680:

$nb_fields = 12; // hardcoded from the regexp below

$pattern = <<<EOT
^(\d+)        # pressure
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

if(!preg_match("~$pattern~x", GRIOTTE_PAYLOAD_RAW, $readings)) {
  trace(LOG_ERR, 'Unable to match readings pattern');
  http(400, 'ko');
  exit;
}

dbg(__FILE__, __LINE__, 'split payload: %s', json_encode($readings));

$readings = array_pad($readings, $nb_fields, 'NULL');
$readings = array_slice($readings, 1, $nb_fields);
if(!empty($readings[8])) {
  $readings[8] = ip2long($readings[8]);
}

$readings = array_map('floatval', $readings);
dbg(__FILE__, __LINE__, 'padded payload: %s', json_encode($readings));

http(200); // presume OK until told otherwise


$run_filenames = [
  'buffer' => sprintf(GRIOTTE_DB_PATH, GRIOTTE_RUN_PATH, GRIOTTE_PAYLOAD_STRUCT),
  'bme680' => sprintf(GRIOTTE_RUNFILE_DATA_PATH_TPL, GRIOTTE_RUN_PATH, GRIOTTE_PAYLOAD_STRUCT, GRIOTTE_SERIAL_NB),
];

if(!file_exists($run_filenames['bme680'])) {
  trace(LOG_DEBUG, 'No bme680 run file for node #%s: saving data', GRIOTTE_SERIAL_NB);
  goto buffer;
}

$filemtime = filemtime($run_filenames['bme680']);
if(false === $filemtime) {
  trace(LOG_ERR, 'Unable to get bme680 run file stats: %s', $run_filenames['bme680']);
  http(500, 'ko');
  exit;
}

if($_SERVER['REQUEST_TIME'] >= ($filemtime + GRIOTTE_NODE_DELAY)) {
  trace(LOG_DEBUG, 'Stale readings for node #%s (last modified at %s): saving new data', GRIOTTE_SERIAL_NB, date('Y-m-d H:i:s', $filemtime));
  goto buffer;
}


trace(LOG_DEBUG, 'Readings still valid for node #%s (last modified at %s): skipping new data', GRIOTTE_SERIAL_NB, date('Y-m-d H:i:s', $filemtime));

finish(200);



/**
 * This section appends the incoming readings to a temporary flat file (low resource disk writes, often called),
 * so that they can be inserted into the DBs in batches (higher resource disk writes, seldom called)
 */
buffer:

$buffer_filename = sprintf('%s/buffer.csv', GRIOTTE_RUN_PATH);
dbg(__FILE__, __LINE__, 'looking at file: %s', $buffer_filename);

// also creates the file if it does not exist
$buffer_handle = fopen($buffer_filename, 'a');
if(!$buffer_handle) {
  trace(LOG_ERR, 'Unable to open buffer file: %s', $buffer_filename);
  http(500, 'ko');
  exit;
}

$buffer_data = array_merge([$_SERVER['REQUEST_TIME'], GRIOTTE_SERIAL_NB], $readings);
if(!fwrite($buffer_handle, implode("\t", $buffer_data).PHP_EOL)) {
  trace(LOG_ERR, 'Unable to write to buffer file: %s', $buffer_filename);
  http(500, 'ko');
  exit;
}

unset($buffer_data);
fclose($buffer_handle);
dbg(__FILE__, __LINE__, 'written to file: %s', $buffer_filename);

$filemtime = filemtime($run_filenames['buffer']);
if(false === $filemtime) {
  if(!truncate($run_filenames[GRIOTTE_PAYLOAD_STRUCT], false, $_SERVER['REQUEST_TIME'])) {
    http(500, 'ko');
    exit;
  }
}

if($_SERVER['REQUEST_TIME'] >= ($filemtime + GRIOTTE_BUFFER_DELAY)) {
  trace(LOG_DEBUG, 'Buffer is ready (last modified at %s): committing data', date('Y-m-d H:i:s', $filemtime));
  goto commit;
}

if(!truncate($run_filenames[GRIOTTE_PAYLOAD_STRUCT], false, $_SERVER['REQUEST_TIME'])) {
  http(500, 'ko');
  exit;
}

finish(200);



/**
 * This section reads the buffer flat file, copies its contents into SQL DBs and clears the buffer file
 */
commit:

$buffer_handle = fopen($buffer_filename, 'r');
dbg(__FILE__, __LINE__, 'opening file: %s', $buffer_filename);

if(!$buffer_handle) {
  trace(LOG_ERR, 'Unable to open file: %s', $buffer_filename);
  http(500, 'ko');
  exit;
}
if(!filesize($buffer_filename)) {
  trace(LOG_ERR, 'Buffer file is empty, nothing to commit: %s', $buffer_filename);
  finish(200);
}
fclose($buffer_handle);
dbg(__FILE__, __LINE__, 'closed file: %s', $buffer_filename);

// let's save every reading in a daily database, as well as a giant all-time database
// and also in the databases from the previous and the next day to avoid timezone issues

$yesterday = strtotime('yesterday');
$tomorrow = strtotime('tomorrow');

$db_filenames = [
  sprintf(GRIOTTE_DAILY_DB_PATH_TPL, GRIOTTE_DB_PATH, date('Y', $yesterday), date('m-F', $yesterday), date('Y-m-d', $yesterday)),
  sprintf(GRIOTTE_DAILY_DB_PATH_TPL, GRIOTTE_DB_PATH, date('Y'), date('m-F'), date('Y-m-d')),
  sprintf(GRIOTTE_DAILY_DB_PATH_TPL, GRIOTTE_DB_PATH, date('Y', $tomorrow), date('m-F', $tomorrow), date('Y-m-d', $tomorrow)),
  sprintf('%s/readings.sq3', GRIOTTE_DB_PATH),
];

// prepare the DB file handles and SQL statements
foreach($db_filenames as $_db_idx => $_db_filename) {
  dbg(__FILE__, __LINE__, 'trying db file: %s', $_db_filename);
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
    truncate($_db_filename, true);
    chmod($_db_filename, 0664);
  }

  $shellcmd = sprintf(
    'cat %s | sqlite3 %s',
    escapeshellarg(__DIR__.'/import.sql'),
    escapeshellarg($_db_filename)
  );

  // trace(LOG_DEBUG, '%s:L%d: command: %s', __FILE__, __LINE__, $shellcmd);
  dbg(__FILE__, __LINE__, 'executing import');

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
dbg(__FILE__, __LINE__, 'finished import');


if(!truncate($buffer_filename, true, $_SERVER['REQUEST_TIME'])) {
  trace(LOG_ERR, 'Unable to truncate buffer file: %s', $buffer_filename);
  http(500, 'ko');
  exit;
}
dbg(__FILE__, __LINE__, 'truncated file: %s', $buffer_filename);

if(!truncate($run_filenames[GRIOTTE_PAYLOAD_STRUCT], true, $_SERVER['REQUEST_TIME'])) {
  trace(LOG_ERR, 'Unable to create %s run data file: %s', GRIOTTE_PAYLOAD_STRUCT, $run_filenames[GRIOTTE_PAYLOAD_STRUCT]);
  http(500, 'ko');
  exit;
}
dbg(__FILE__, __LINE__, 'truncated file: %s', $run_filenames[GRIOTTE_PAYLOAD_STRUCT]);

finish(201);

