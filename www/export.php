<?php

date_default_timezone_set('Europe/Paris');
header('Content-Type: text/html; charset=utf-8');

$db = new PDO('sqlite:./readings.sq3');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/*
$sql = <<<SQL
SELECT CASE node
    WHEN 694510667 THEN 'friends'
    WHEN 3764971370 THEN 'attic'
    WHEN 3764978189 THEN 'library'
    WHEN 1547794758 THEN 'bedroom'
    WHEN 3385449031 THEN 'kitchen'
    WHEN 3385445268 THEN 'office'
    ELSE node
  END AS node
  , COUNT(1), MIN(created_at), MAX(created_at)
  , ROUND(AVG(hpa), 2), MIN(hpa), MAX(hpa)
  , ROUND(AVG(hum), 2), MIN(hum), MAX(hum)
  , ROUND(AVG(temp), 2), MIN(temp), MAX(temp)
  , ROUND(AVG(iaq), 2), MIN(iaq), MAX(iaq)
  , ROUND(AVG(eco2), 2), MIN(eco2), MAX(eco2)
  , ROUND(AVG(voc), 2), MIN(voc), MAX(voc)
FROM sensor_reading
WHERE node IN (?, ?)
GROUP BY node, DATE(created_at)
ORDER BY node, DATE(created_at)
SQL;

$stmt = $db->prepare($sql);
$stmt->execute([3385449031, 3385445268]);

$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

print_r($results);
*/


$sql = <<<SQL
SELECT CASE node
    WHEN 694510667 THEN 'friends'
    WHEN 3764971370 THEN 'attic'
    WHEN 3764978189 THEN 'library'
    WHEN 1547794758 THEN 'bedroom'
    WHEN 3385449031 THEN 'kitchen'
    WHEN 3385445268 THEN 'office'
    ELSE node
  END AS node
  , COUNT(1) AS nb
  , MIN(created_at) AS earliest
  , MAX(created_at) AS latest
  , ROUND(AVG(hum), 2) AS avg_hum
  , ROUND(AVG(temp), 2) AS avg_temp
  , ROUND(AVG(iaq*10), 2) AS avg_iaq
  , ROUND(AVG(eco2), 2) AS avg_eco2
  , ROUND(AVG(voc*100), 2) AS avg_voc
FROM sensor_reading
WHERE created_at
  BETWEEN datetime(CURRENT_TIMESTAMP, '-03:00:00')
  AND datetime(CURRENT_TIMESTAMP, '00:00:00')
GROUP BY node
ORDER BY node
SQL;

$stmt = $db->prepare($sql);
$stmt->execute();

$results = [];
$labels = [];
foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $res) {
  $results[$res['node']] = $res;
  $labels[] = sprintf('%s (%d)', $res['node'], $res['nb']);
}
//print_r($results);

/*
header('Content-Type: text/plain; charset=utf-8');
echo json_encode(array_column($results, 'node'));
echo json_encode(array_map('intval', array_column($results, 'temp')));
exit;
*/

$datasets = [
  'iaq'  => ['label' => 'iAQ',  'color' => '#96f'],
  'eco2' => ['label' => 'eCO²', 'color' => '#ff9f40'],
  'voc'  => ['label' => 'VOC',  'color' => '#4bc0c0'],
];

$tz_utc = new DateTimeZone('UTC');
$earliest = new DateTimeImmutable(min(array_column($results, 'earliest')), $tz_utc);
$latest = new DateTimeImmutable(min(array_column($results, 'latest')), $tz_utc);

$tz_local = new DateTimeZone(date_default_timezone_get());
$title = sprintf(
  'Overview between %s and %s',
  $earliest->setTimezone($tz_local)->format('Y-m-d H:i:s'),
  $latest->setTimezone($tz_local)->format('Y-m-d H:i:s')
);

$zindex = 0;
?>

<script src="./chart-v4.4.4.js"></script>


<canvas id="overview"></canvas>

<script>
  new Chart(document.getElementById('overview'), {
    data: {
      labels: <?php echo json_encode($labels); ?>,
      datasets: [
        <?php foreach($datasets as $field => $config): ?>
        {
          type: 'bar',
          label: '<?=addslashes($config['label'])?>',
          data: <?php echo json_encode(array_map('intval', array_column($results, 'avg_'.$field))); ?>,
          borderWidth: 1,
          weight: 1,
          order: <?=($zindex--)?>,
          yAxisID: 'right',
          borderColor: '<?=$config['color']?>',
          backgroundColor: '<?=$config['color']?>'
        },
        <?php endforeach; ?>
        {
          type: 'line',
          label: 'Temperature (°C)',
          data: <?php echo json_encode(array_map('intval', array_column($results, 'avg_temp'))); ?>,
          borderWidth: 1,
          order: <?=($zindex--)?>,
          yAxisID: 'left',
          borderColor: '#ff0000',
          backgroundColor: '#ff0000'
        },
        {
          type: 'line',
          label: 'Humidity (%)',
          data: <?php echo json_encode(array_map('intval', array_column($results, 'avg_hum'))); ?>,
          borderWidth: 1,
          order: <?=($zindex--)?>,
          yAxisID: 'left',
          borderColor: '#0000ff',
          backgroundColor: '#0000ff'
        },
      ]
    },
    options: {
      plugins: {
        title: {
          display: true,
          text: <?php echo json_encode($title); ?>
        }
      },
      scales: {
        right: {
          beginAtZero: true,
          position: 'right'
        },
        left: {
          beginAtZero: true,
          position: 'left',
          suggestedMin: 0,
          suggestedMax: 100,
          grid: {
            display: false
          }
        }
      }
    }
  });
</script>
