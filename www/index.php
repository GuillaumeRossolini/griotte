<?php

define('GRIOTTE_STARTTIME', microtime(true));

register_shutdown_function(function() {
  error_log(sprintf('Script finished after %0.3fms', microtime(true)-GRIOTTE_STARTTIME));
});


if(empty($_POST['data'])) {
  header('Content-Type: text/plain', true, 400);
  error_log('L%d: Missing "data" field in the request body', __LINE__);
  exit;
}

$payload = json_decode($_POST['data'], true);
if(false === $payload) {
  header('Content-Type: text/plain', true, 400);
  error_log('L%d: Input data was not JSON: %s', __LINE__, print_r($_POST['data'], true));
  exit;
}

$agent_name = null;
$griotte_nb = null;
if(empty($_SERVER['HTTP_USER_AGENT'])) {
  header('Content-Type: text/plain', true, 400);
  error_log(sprintf('L%d: Missing the User-Agent header: %s', __LINE__, json_encode($_SERVER)));
  exit;
}

if(!preg_match('~(Griotte)/(\d+)$~', $_SERVER['HTTP_USER_AGENT'], $griotte)) {
  header('Content-Type: text/plain', true, 400);
  error_log(sprintf('L%d: Missing the User-Agent header: %s', __LINE__, $_SERVER['HTTP_USER_AGENT']));
  exit;
}

list($agent_name, $griotte_nb) = $griotte;
http_response_code(200);
echo 'ok';

//error_log(sprintf('Response sent after %0.3fms', microtime(true)-GRIOTTE_STARTTIME));


list($agent_name, $griotte_nb) = explode('/', $_SERVER['HTTP_USER_AGENT']);

$msg = sprintf(
  '%s #%s says: %s',
  $agent_name ?: 'n/a',
  $griotte_nb ?: 'n/a',
  base64_decode($payload['msg'])
);

error_log(sprintf('Received payload: %s', $msg));


if(!preg_match('~says:\s*(\d+)[^;]+;\s*(\d+)[^;]+;\s*(\d+)[^;]+;\s*([0-9.]+)[^;]+;\s*(\d+)[^;]+;\s*([0-9.]+)[^;]+$~', $msg, $readings)) {
  header('Content-Type: text/plain', true, 400);
  error_log(sprintf('L%d: Unable to match reading pattern', __LINE__));
  exit;
}


$db = new PDO('sqlite:./readings.sq3');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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


//$db->exec("DELETE FROM sensor_reading WHERE node IN (101740, 101742, 101744)");


$run_filename = sprintf('/var/run/griotte/%d.run', $griotte_nb);
$file_exists = file_exists($run_filename);

if(!$file_exists) {
  if(!touch($run_filename)) {
    header('Content-Type: text/plain', true, 400);
    error_log(sprintf('L%d: Unable to create file: %s', __LINE__, $run_filename));
    exit;
  }

  //error_log('No readings for this node: '.json_encode($dbg));
  goto insert;
}

$filemtime = filemtime($run_filename);
// error_log(sprintf('File "%s" was modified at %s', $run_filename, date('Y-m-d H:i:s', $filemtime)));
if(false === $filemtime) {
  header('Content-Type: text/plain', true, 400);
  error_log(sprintf('L%d: Unable to get file stats: %s', __LINE__, $run_filename));
  exit;
}

if(time() > ($filemtime + 60*1)) {
  //error_log('Readings too old for this node: '.json_encode($dbg));
  goto insert;
}


goto finish;


insert:

$sql = <<<SQL
INSERT INTO sensor_reading (node, hpa, hum, temp, iaq, eco2, voc)
VALUES (?, ?, ?, ?, ?, ?, ?)
SQL;

array_shift($readings);
$insert = $db->prepare($sql);
$insert->execute(array_merge([$griotte_nb], $readings));

if(!touch($run_filename)) {
  header('Content-Type: text/plain', true, 400);
  error_log(sprintf('L%d: Unable to create file: %s', __LINE__, $run_filename));
  exit;
}

error_log(sprintf('Data appended after %0.3fms', microtime(true)-GRIOTTE_STARTTIME));
//exit;

goto finish;


finish:
exit;  // querying the database takes too long on an SDCard
