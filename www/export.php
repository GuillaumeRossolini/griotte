<?php

$db = new PDO('sqlite:./readings.sq3');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);


$griotte_nb = 3385449031;
$created_at = 1726309664;


$sql = <<<SQL
SELECT node
  , COUNT(1), MIN(created_at), MAX(created_at)
FROM sensor_reading
GROUP BY node
SQL;

$stmt = $db->prepare($sql);
$stmt->execute();

print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
