<?php

define('GRIOTTE_STARTTIME', microtime(true));
header('Content-Type: text/plain; charset=utf-8', true);

register_shutdown_function(function() {
  syslog(LOG_DEBUG, sprintf('Script finished after %0.3fms', microtime(true)-GRIOTTE_STARTTIME));
});


if(empty($_POST['data'])) {
  syslog(LOG_ERR, sp^rintf('L%d: Missing "data" field in the request body', __LINE__));
  http_response_code(400);
  die('ko');
}

$payload = json_decode($_POST['data'], true);
if(false === $payload) {
  syslog(LOG_ERR, sprintf('L%d: Input data was not JSON: %s', __LINE__, print_r($_POST['data'], true)));
  http_response_code(400);
  die('ko');
}

$agent_name = null;
$griotte_nb = null;
if(empty($_SERVER['HTTP_USER_AGENT'])) {
  syslog(LOG_ERR, sprintf('L%d: Missing the User-Agent header: %s', __LINE__, json_encode($_SERVER)));
  http_response_code(400);
  die('ko');
}

if(!preg_match('~(Griotte)/(\d+)$~', $_SERVER['HTTP_USER_AGENT'], $griotte)) {
  syslog(LOG_ERR, sprintf('L%d: Missing the User-Agent header: %s', __LINE__, $_SERVER['HTTP_USER_AGENT']));
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


if(!preg_match('~says:\s*(\d+)[^;]+;\s*(\d+)[^;]+;\s*(\d+)[^;]+;\s*([0-9.]+)[^;]+;\s*(\d+)[^;]+;\s*([0-9.]+)[^;]+$~', $msg, $readings)) {
  syslog(LOG_ERR, sprintf('L%d: Unable to match reading pattern', __LINE__));
  http_response_code(400);
  die('ko');
}


array_shift($readings);
http_response_code(200);
echo 'ok';



$nb_inserts = 0;

$run_filename = sprintf('/var/run/griotte/%s.run', $griotte_nb);
$file_exists = file_exists($run_filename);

if(!$file_exists) {
  // syslog(LOG_DEBUG, 'No readings for this node: '.json_encode($dbg));
  goto insert;
}

$filemtime = filemtime($run_filename);
// syslog(LOG_DEBUG, sprintf('File "%s" was modified at %s', $run_filename, date('Y-m-d H:i:s', $filemtime)));
if(false === $filemtime) {
  syslog(LOG_ERR, sprintf('L%d: Unable to get file stats: %s', __LINE__, $run_filename));
  http_response_code(500);
  die('ko');
}

if(time() >= ($filemtime + 60*1)) {
  // syslog(LOG_DEBUG, 'Readings too old for this node: '.json_encode($dbg));
  goto insert;
}


goto finish;


insert:

$db_filename = ($nb_inserts == 1)
  ? sprintf('/home/pi/griotte/db/v1/%d/%s/%s.sq3', date('Y'), date('m-F'), date('Y-m-d'))
  : '/home/pi/griotte/readings.sq3';

$new_db = !file_exists($db_filename);

if($new_db) {
  if(!file_exists(dirname($db_filename))) {
    mkdir(dirname($db_filename), 0775, true);
  }

  if(!touch($db_filename)) {
    syslog(LOG_ERR, sprintf('L%d: %s "%s"', __LINE__, 'Unable to create the DB file', $db_filename));
    http_response_code(500);
    die('ko');
  }

  chmod($db_filename, 0664);
}

try {
  $db = new PDO('sqlite:'.$db_filename);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}
catch(Exception $e) {
  syslog(LOG_ERR, sprintf('L%d: %s%s%s', __LINE__, $e->getMessage(), PHP_EOL, $e->getTraceAsString()));
  http_response_code(500);
  die('ko');
}


if($new_db) {
  $sql = <<<SQL
  CREATE TABLE IF NOT EXISTS sensor_reading (
    id INTEGER PRIMARY KEY,
    created_at INTEGER DEFAULT CURRENT_TIMESTAMP,
    node INTEGER,
    hpa INTEGER,
    hum INTEGER,
    temp INTEGER,
    iaq REAL,
    eco2 INTEGER,
    voc INTEGER
  );
  SQL;

  try {
    $db->exec($sql);
    syslog(LOG_DEBUG, 'Created DB: '.$db_filename);
  }
  catch(Exception $e) {
    syslog(LOG_ERR, sprintf('L%d: %s%s%s', __LINE__, $e->getMessage(), PHP_EOL, $e->getTraceAsString()));
    http_response_code(500);
    die('ko');
  }
}


$sql = <<<SQL
INSERT INTO sensor_reading (node, hpa, hum, temp, iaq, eco2, voc)
VALUES (?, ?, ?, ?, ?, ?, ?)
SQL;

try {
  $insert = $db->prepare($sql);
  $insert->execute(array_merge([$griotte_nb], $readings));
  $nb_inserts += $insert->rowCount();
}
catch(Exception $e) {
  syslog(LOG_ERR, sprintf('L%d: %s%s%s', __LINE__, $e->getMessage(), PHP_EOL, $e->getTraceAsString()));
  http_response_code(500);
  die('ko');
}

if(!touch($run_filename)) {
  syslog(LOG_ERR, sprintf('L%d: Unable to create file: %s', __LINE__, $run_filename));
  http_response_code(500);
  die('ko');
}

syslog(LOG_INFO, sprintf('Data appended after %0.3fms', microtime(true)-GRIOTTE_STARTTIME));

if($nb_inserts >= 2) {
  goto finish;
}
else {
  goto insert;
}


finish:
exit;

