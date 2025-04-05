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


if(!preg_match('~says:\s*(\d+)[^;]+;\s*(\d+)[^;]+;\s*(\d+)[^;]+;\s*([0-9.]+)[^;]+;\s*(\d+)[^;]+;\s*([0-9.]+)[^;]+$~', $msg, $readings)) {
  syslog(LOG_ERR, sprintf('Unable to match reading pattern'));
  http_response_code(400);
  die('ko');
}


array_shift($readings);
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


$run_filename = sprintf('%s/%s.run', GRIOTTE_RUN, $griotte_nb);
$file_exists = file_exists($run_filename);

if(!$file_exists) {
  syslog(LOG_DEBUG, sprintf('No run-file for griotte #%s: saving data', $griotte_nb));
  goto insert;
}

$filemtime = filemtime($run_filename);
if(false === $filemtime) {
  syslog(LOG_ERR, sprintf('Unable to get run-file stats: %s', $run_filename));
  http_response_code(500);
  die('ko');
}

if(time() >= ($filemtime + GRIOTTE_DELAY)) {
  syslog(LOG_DEBUG, sprintf('Stale readings for griotte #%s (last modified at %s): saving new data', $griotte_nb, date('Y-m-d H:i:s', $filemtime)));
  goto insert;
}


syslog(LOG_DEBUG, sprintf('Readings still valid for griotte #%s (last modified at %s): skipping new data', $griotte_nb, date('Y-m-d H:i:s', $filemtime)));

goto finish;


insert:

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

foreach($db_filenames as $_db_filename) {
  $new_db = !file_exists($_db_filename);

  if(!file_exists(dirname($_db_filename))) {
    mkdir(dirname($_db_filename), 0775, true);
  }

  try {
    $db = new PDO('sqlite:'.$_db_filename);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  }
  catch(Exception $e) {
    syslog(LOG_ERR, sprintf('L%d: %s%s%s', __LINE__, $e->getMessage(), PHP_EOL, $e->getTraceAsString()));
    http_response_code(500);
    die('ko');
  }


  if($new_db) {
    chmod($_db_filename, 0664);

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
      syslog(LOG_DEBUG, 'Created DB: '.$_db_filename);
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
  }
  catch(Exception $e) {
    syslog(LOG_ERR, sprintf('L%d: %s%s%s', __LINE__, $e->getMessage(), PHP_EOL, $e->getTraceAsString()));
    http_response_code(500);
    die('ko');
  }

  syslog(LOG_INFO, sprintf('Data appended after %0.3fms to %s', microtime(true)-GRIOTTE_STARTTIME, basename($_db_filename)));
}

if(!touch($run_filename)) {
  syslog(LOG_ERR, sprintf('Unable to create file: %s', $run_filename));
  http_response_code(500);
  die('ko');
}

goto finish;


finish:
exit;

