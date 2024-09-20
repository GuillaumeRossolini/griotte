<!doctype html>
<html lang="en-US">
  <head>
    <meta charset="utf-8" />
    <title>Griotte metrics</title>
    <script src="./chart-v4.4.4.js"></script>
  </head>
<body>
<?php

date_default_timezone_set('Europe/Paris');
error_log(E_ALL);
ini_set('display_errors', 1);

$tz_utc = new DateTimeZone('UTC');
$tz_local = new DateTimeZone(date_default_timezone_get());

$db = new PDO('sqlite:../readings.sq3');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);


$sql = <<<SQL
SELECT node
  , CASE node
    WHEN 694510667 THEN 'friends'
    WHEN 3764971370 THEN 'attic'
    WHEN 3764978189 THEN 'library'
    WHEN 1547794758 THEN 'bedroom'
    WHEN 3385449031 THEN 'kitchen'
    WHEN 3385445268 THEN 'office'
    ELSE node
  END AS node_idx
  , COUNT(1) AS nb
  , MIN(created_at) AS earliest
  , MAX(created_at) AS latest
  , ROUND(AVG(hpa/100), 2) AS avg_hpa
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

$overview = [];
$labels = [];
foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $res) {
  $overview[$res['node_idx']] = $res;
  $labels[] = sprintf('%s (%d)', $res['node_idx'], $res['nb']);
}

$datasets = [
  'iaq'  => ['label' => 'iAQ',  'color' => '#96f'],
  'eco2' => ['label' => 'eCO²', 'color' => '#ff9f40'],
  'voc'  => ['label' => 'VOC',  'color' => '#4bc0c0'],
];

$earliest = new DateTimeImmutable(min(array_column($overview, 'earliest')), $tz_utc);
$latest = new DateTimeImmutable(max(array_column($overview, 'latest')), $tz_utc);

$title = sprintf(
  'Overview between %s and %s',
  $earliest->setTimezone($tz_local)->format('Y-m-d H:i:s'),
  $latest->setTimezone($tz_local)->format('Y-m-d H:i:s')
);

$zindex = 0;
?>

<canvas id="overview"></canvas>

<script>
  new Chart(document.getElementById('overview'), {
    data: {
      labels: <?php echo json_encode($labels); ?>,
      datasets: [
        <?php foreach($datasets as $field => $config): ?>
        {
          type: 'bar',
          label: <?=json_encode($config['label'])?>,
          data: <?php echo json_encode(array_map('intval', array_column($overview, 'avg_'.$field))); ?>,
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
          label: 'Barometric (hPA)',
          data: <?php echo json_encode(array_map('intval', array_column($overview, 'avg_hpa'))); ?>,
          borderWidth: 1,
          order: <?=($zindex--)?>,
          yAxisID: 'right',
          borderColor: '#c9cbcf',
          backgroundColor: '#c9cbcf'
        },
        {
          type: 'line',
          label: 'Temperature (°C)',
          data: <?php echo json_encode(array_map('intval', array_column($overview, 'avg_temp'))); ?>,
          borderWidth: 1,
          order: <?=($zindex--)?>,
          yAxisID: 'left',
          borderColor: '#ff0000',
          backgroundColor: '#ff0000'
        },
        {
          type: 'line',
          label: 'Humidity (%)',
          data: <?php echo json_encode(array_map('intval', array_column($overview, 'avg_hum'))); ?>,
          borderWidth: 1,
          order: <?=($zindex--)?>,
          yAxisID: 'left',
          borderColor: '#0000ff',
          backgroundColor: '#0000ff'
        }
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

<?php

$sql = <<<SQL
SELECT TIME(created_at) AS created_at
  , ROUND(hpa/100, 2) AS hpa
  , ROUND(hum, 2) AS hum
  , ROUND(temp, 2) AS temp
  , ROUND(iaq*10, 2) AS iaq
  , ROUND(eco2, 2) AS eco2
  , ROUND(voc*100, 2) AS voc
FROM sensor_reading
WHERE true
  AND node = ?
  AND created_at BETWEEN '2024-09-20 00:00:00' AND '2024-09-20 23:59:59'
ORDER BY created_at
SQL;

$stmt = $db->prepare($sql);

$rooms = [];
$labels = [];
$titles = [];
foreach(array_keys($overview) as $node_key) {
  $node_id = $overview[$node_key]['node'];
  $stmt->execute([$node_id]);

  foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $res) {
    $created_at = $res['created_at'];
    unset($res['created_at']);
    $rooms[$node_key][$created_at] = array_map('intval', $res);
    $t = new DateTimeImmutable('2024-09-20 '.$created_at, $tz_utc);
    $labels[$node_key][] = $t->setTimezone($tz_local)->format('H:i:s');
  }

  $times = array_keys($rooms[$node_key]);
  natsort($times);
  $earliest = current($times);
  end($times);
  $latest = current($times);
  reset($times);

  $earliest = new DateTimeImmutable('2024-09-20 '.$earliest, $tz_utc);
  $latest = new DateTimeImmutable('2024-09-20 '.$latest, $tz_utc);

  $titles[$node_key] = sprintf(
    'Room "%s" on %s between %s and %s',
    $node_key,
    $earliest->format('Y-m-d'),
    $earliest->format('H:i:s'),
    $latest->format('H:i:s')
  );
}
// echo'<pre>';var_dump($rooms);exit;
?>

<?php foreach(array_keys($overview) as $node_key): ?>
  <?php $zindex = 0; ?>
  <canvas id="room-<?=$node_key?>"></canvas>

  <script>
    new Chart(document.getElementById('room-<?=$node_key?>'), {
      data: {
        labels: <?php echo json_encode($times); ?>,
        datasets: [
          <?php foreach($datasets as $field => $config): ?>
          {
            type: 'line',
            label: <?=json_encode($config['label'])?>,
            data: <?php echo json_encode(array_map('intval', array_column($rooms[$node_key], $field))); ?>,
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
            label: 'Barometric (hPA)',
            data: <?php echo json_encode(array_map('intval', array_column($rooms[$node_key], 'hpa'))); ?>,
            borderWidth: 1,
            order: <?=($zindex--)?>,
            yAxisID: 'right',
            borderColor: '#c9cbcf',
            backgroundColor: '#c9cbcf'
          },
          {
            type: 'line',
            label: 'Temperature (°C)',
            data: <?php echo json_encode(array_map('intval', array_column($rooms[$node_key], 'temp'))); ?>,
            borderWidth: 1,
            order: <?=($zindex--)?>,
            yAxisID: 'left',
            borderColor: '#ff0000',
            backgroundColor: '#ff0000'
          },
          {
            type: 'line',
            label: 'Humidity (%)',
            data: <?php echo json_encode(array_map('intval', array_column($rooms[$node_key], 'hum'))); ?>,
            borderWidth: 1,
            order: <?=($zindex--)?>,
            yAxisID: 'left',
            borderColor: '#0000ff',
            backgroundColor: '#0000ff'
          }
        ]
      },
      options: {
        plugins: {
          title: {
            display: true,
            text: <?php echo json_encode($titles[$node_key]); ?>
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
<?php endforeach; ?>


</body>
</html>
