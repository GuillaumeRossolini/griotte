<?php

if(empty($_POST['data'])) {
  header('Content-Type: text/plain', true, 400);
  echo 'missing "data" field';
  exit;
}

$data = json_decode($_POST['data'], true);

$agent_name = null;
$griotte_nb = null;
if(preg_match('~#(Griotte)/(\d+)$~', $_SERVER['HTTP_USER_AGENT'], $griotte)) {
  list($agent_name, $griotte_nb) = $griotte;
}

list($agent_name, $griotte_nb) = explode('/', $_SERVER['HTTP_USER_AGENT']);

$msg = sprintf(
  '%s #%s says: %s',
  $agent_name ?: 'n/a',
  $griotte_nb ?: 'n/a',
  base64_decode($data['msg'])
);

//error_log(json_encode($_SERVER));
error_log($msg);

//error_log(json_encode([$agent_name, $griotte_nb]));

//header('x-test: foo', true, 201);
echo 'ok';


if(!preg_match('~says:\s*(\d+)[^;]+;\s*(\d+)[^;]+;\s*(\d+)[^;]+;\s*([0-9.]+)[^;]+;\s*(\d+)[^;]+;\s*([0-9.]+)[^;]+$~', $msg, $readings)) {
  error_log('unable to match readings');
  exit;
}


$db = new PDO('sqlite:./readings.sq3');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/*
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


$sql = <<<SQL
SELECT COUNT(1) AS nb
FROM sensor_reading
WHERE node = ?
SQL;

$stmt = $db->prepare($sql);
$stmt->execute([$griotte_nb]);
$dbg = $stmt->fetchAll(PDO::FETCH_ASSOC);
if(empty($dbg[0]['nb'])) {
  //error_log('No readings for this node: '.json_encode($dbg));
  goto insert;
}


$sql = <<<SQL
SELECT COUNT(1)
FROM sensor_reading
WHERE node = ?
GROUP BY node
HAVING MAX(created_at) < datetime(CURRENT_TIMESTAMP, '-00:00:01')
SQL;

$stmt = $db->prepare($sql);
$stmt->execute([$griotte_nb]);
$dbg = $stmt->fetchAll(PDO::FETCH_ASSOC);
if($dbg) {
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
//exit;

goto finish;


finish:
exit;
$stmt = $db->query('SELECT COUNT(1) AS nb FROM sensor_reading', PDO::FETCH_ASSOC);
error_log(sprintf('DB now holds %d readings', $stmt->fetchAll()[0]['nb']));


