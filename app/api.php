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

//syslog(LOG_ERR, sprintf('Response sent after %0.3fms', microtime(true)-GRIOTTE_STARTTIME));


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



$run_filename = sprintf('/var/run/griotte.%s.run', $griotte_nb);
$file_exists = file_exists($run_filename);

if(!$file_exists) {
  //syslog(LOG_ERR, 'No readings for this node: '.json_encode($dbg));
  goto insert;
}

$filemtime = filemtime($run_filename);
// syslog(LOG_ERR, sprintf('File "%s" was modified at %s', $run_filename, date('Y-m-d H:i:s', $filemtime)));
if(false === $filemtime) {
  syslog(LOG_ERR, sprintf('L%d: Unable to get file stats: %s', __LINE__, $run_filename));
  http_response_code(500);
  die('ko');
}

if(time() >= ($filemtime + 60*1)) {
  //syslog(LOG_ERR, 'Readings too old for this node: '.json_encode($dbg));
  goto insert;
}


goto finish;


insert:

/*
// maintenance stuff

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

$db->exec($sql);
*/


$sql = <<<SQL
INSERT INTO sensor_reading (node, hpa, hum, temp, iaq, eco2, voc)
VALUES (?, ?, ?, ?, ?, ?, ?)
SQL;

try {
  $db = new PDO('sqlite:../readings.sq3');
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $insert = $db->prepare($sql);
  $insert->execute(array_merge([$griotte_nb], $readings));
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
goto finish;


finish:
exit;

