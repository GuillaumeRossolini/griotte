<?php
error_log(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Europe/Paris');
ob_start("ob_gzhandler");
?>
<!doctype html>
<html lang="en-US">
  <head>
    <meta charset="utf-8" />
    <title>Griotte metrics</title>
    <script src="./chart-v4.4.4.js"></script>
  </head>
<body>

<?php
$tz_utc = new DateTimeZone('UTC');
$tz_local = new DateTimeZone(date_default_timezone_get());

$date_filter = empty($_GET['d']) ? date('Y-m-d') : $_GET['d'];
if(!preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $date_filter, $match) or !checkdate($match[2], $match[3], $match[1])) {
  throw new Exception('Invalid date format');
}

$start_local = new DateTimeImmutable($date_filter, $tz_local);

$start_utc = $start_local
  ->setTimezone($tz_utc);

$end_utc = $start_local
  ->add(new DateInterval('P1D'))
  ->sub(new DateInterval('PT1S'))
  ->setTimezone($tz_utc);


$db = new PDO('sqlite:../readings.sq3');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/*
$sql = <<<SQL
UPDATE sensor_reading SET node = ? WHERE node = ?;
SQL;

$db->prepare($sql)->execute([
  3385105714,
  3764971370,
]);
*/

// The IAQ scale ranges from 0 (clean air) to 500 (heavily polluted air)
// IAQ=50 corresponds to typical good air and IAQ=200 indicates typical polluted air

// typical hPa levels:
// 1000+: tropical depression
// 990-999: tropical storm
// 980-989: cat1 hurricane
// 965-979: cat2 hurricane
// 945-964: cat3 hurricane
// 920-944: cat4 hurricane
// 900-919: cat5 hurricane
// 850-899: tornado

// attic OLD: 3764971370

$sql = <<<SQL
SELECT node
  , CASE node
    WHEN 1364776260 THEN 'cellar'
    WHEN 3764979380 THEN 'heater'
    WHEN 3385449031 THEN 'kitchen'
    WHEN 695062617 THEN 'salon'
    WHEN 1547794758 THEN 'bedroom'
    WHEN 694510667 THEN 'guest room'
    WHEN 695063509 THEN 'dressing'
    WHEN 3764978189 THEN 'library'
    WHEN 3385105714 THEN 'attic'
    WHEN 3385445268 THEN 'office'
    WHEN 3385445319 THEN 'playroom'
    ELSE node
  END AS node_lbl
  , CASE node
    WHEN 1364776260 THEN -1
    WHEN 3764979380 THEN 0
    WHEN 3385449031 THEN 0
    WHEN 695062617 THEN 0
    WHEN 1547794758 THEN 1
    WHEN 694510667 THEN 1
    WHEN 695063509 THEN 1
    WHEN 3764978189 THEN 1
    WHEN 3385105714 THEN 2
    WHEN 3385445268 THEN 2
    WHEN 3385445319 THEN 2
    ELSE 3
  END AS floor
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
WHERE true
  AND created_at BETWEEN ? AND ?
GROUP BY node_lbl
ORDER BY floor, node_lbl
SQL;

$stmt = $db->prepare($sql);
$stmt->execute([
  $start_utc->format('Y-m-d H:i:s'),
  $end_utc->format('Y-m-d H:i:s'),
]);

$overview = [];
$labels = [];
foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $res) {
  $overview[$res['node_lbl']] = $res;
  $labels[] = sprintf('%s@F%d (%d)', $res['node_lbl'], $res['floor'], $res['nb']);
}

if(!$overview) {
  throw new Exception('No data');
}

$datasets = [
  'iaq'  => ['label' => 'IAQ',  'color' => '#96f'],
  'eco2' => ['label' => 'eCO²', 'color' => '#ff9f40'],
  'voc'  => ['label' => 'VOC',  'color' => '#4bc0c0'],
];

$earliest_raw = min(array_column($overview, 'earliest'));
$latest_raw = max(array_column($overview, 'latest'));

$earliest = (new DateTimeImmutable($earliest_raw, $tz_utc))
  ->setTimezone($tz_local);

$latest = (new DateTimeImmutable($latest_raw, $tz_utc))
  ->setTimezone($tz_local);

$title = sprintf(
  'Overview on %s between %s and %s',
  $earliest->format('Y-m-d'),
  $earliest->format('H:i:s'),
  $latest->format('H:i:s')
);

$zindex = 0;
?>

<form method="get">
  <input type="date" name="d" value="<?php echo htmlspecialchars($date_filter, ENT_QUOTES) ?>"/>
</form>

<script>
  const dateForm = document.querySelector('form');
  const dateInput = dateForm.querySelector('input[type=date]');
  dateInput.addEventListener('change', function(e) {
    dateForm.submit();
  });
</script>

<canvas id="overview"></canvas>

<script>
  new Chart(document.getElementById('overview'), {
    data: {
      labels: <?php echo json_encode($labels) ?>,
      datasets: [
        <?php foreach($datasets as $field => $config): ?>
        {
          type: 'bar',
          label: <?php echo json_encode($config['label']) ?>,
          data: <?php echo json_encode(array_map('intval', array_column($overview, 'avg_'.$field))) ?>,
          borderWidth: 1,
          weight: 1,
          order: <?php echo json_encode($zindex--) ?>,
          yAxisID: 'right',
          borderColor: <?php echo json_encode($config['color']) ?>,
          backgroundColor: <?php echo json_encode($config['color']) ?>
        },
        <?php endforeach; ?>
        {
          type: 'line',
          label: 'Barometric (hPA)',
          data: <?php echo json_encode(array_map('intval', array_column($overview, 'avg_hpa'))) ?>,
          borderWidth: 1,
          order: <?php echo json_encode($zindex--) ?>,
          yAxisID: 'right',
          borderColor: '#c9cbcf',
          backgroundColor: '#c9cbcf'
        },
        {
          type: 'line',
          label: 'Temperature (°C)',
          data: <?php echo json_encode(array_map('intval', array_column($overview, 'avg_temp'))) ?>,
          borderWidth: 1,
          order: <?php echo json_encode($zindex--) ?>,
          yAxisID: 'left',
          borderColor: '#ff0000',
          backgroundColor: '#ff0000'
        },
        {
          type: 'line',
          label: 'Humidity (%)',
          data: <?php echo json_encode(array_map('intval', array_column($overview, 'avg_hum'))) ?>,
          borderWidth: 1,
          order: <?php echo json_encode($zindex--) ?>,
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
          text: <?php echo json_encode($title) ?>
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
SELECT created_at
  , ROUND(hpa/100, 2)-920 AS hpa
  , ROUND(hum, 2) AS hum
  , ROUND(temp, 2) AS temp
  , ROUND(iaq*10, 2) AS iaq
  , ROUND(eco2, 2) AS eco2
  , ROUND(voc*100, 2) AS voc
FROM sensor_reading
WHERE true
  AND node = ?
  AND created_at BETWEEN ? AND ?
ORDER BY created_at
SQL;

$stmt = $db->prepare($sql);

$rooms = [];
$labels = [];
$titles = [];
foreach($overview as $node_key => $node_average) {
  $stmt->execute([
    $node_average['node'],
    $start_utc->format('Y-m-d H:i:s'),
    $end_utc->format('Y-m-d H:i:s'),
  ]);

  foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $res) {
    $created_at = $res['created_at'];
    unset($res['created_at']);
    $rooms[$node_key][$created_at] = array_map('intval', $res);
    $t = new DateTimeImmutable($created_at, $tz_utc);
    $labels[$node_key][] = $t->setTimezone($tz_local)->format('H:i:s');
  }

  reset($rooms[$node_key]);
  $earliest = (new DateTimeImmutable(key($rooms[$node_key]), $tz_utc))
    ->setTimezone($tz_local);

  end($rooms[$node_key]);
  $latest = (new DateTimeImmutable(key($rooms[$node_key]), $tz_utc))
    ->setTimezone($tz_local);

  reset($rooms[$node_key]);

  $titles[$node_key] = sprintf(
    'Room "%s" on %s between %s and %s',
    $node_key,
    $earliest->format('Y-m-d'),
    $earliest->format('H:i:s'),
    $latest->format('H:i:s'),
  );
}
?>

<?php foreach($overview as $node_key => $node_average): ?>
  <?php $zindex = 0; ?>
  <canvas id="<?php echo htmlspecialchars(sprintf('room-%s', $node_key), ENT_QUOTES) ?>"></canvas>

  <script>
    new Chart(document.getElementById(<?php echo json_encode(sprintf('room-%s', $node_key)) ?>), {
      data: {
        labels: <?php echo json_encode($labels[$node_key]) ?>,
        datasets: [
          <?php foreach($datasets as $field => $config): ?>
          {
            type: 'line',
            label: <?php echo json_encode($config['label']) ?>,
            data: <?php echo json_encode(array_map('intval', array_column($rooms[$node_key], $field))) ?>,
            borderWidth: 1,
            weight: 1,
            order: <?php echo json_encode($zindex--) ?>,
            yAxisID: 'right',
            borderColor: <?php echo json_encode($config['color']) ?>,
            backgroundColor: <?php echo json_encode($config['color']) ?>
          },
          <?php endforeach; ?>
          {
            type: 'line',
            label: 'Barometric (hPA-920)',
            data: <?php echo json_encode(array_map('intval', array_column($rooms[$node_key], 'hpa'))) ?>,
            borderWidth: 1,
            order: <?php echo json_encode($zindex--) ?>,
            yAxisID: 'left',
            borderColor: '#c9cbcf',
            backgroundColor: '#c9cbcf'
          },
          {
            type: 'line',
            label: 'Temperature (°C)',
            data: <?php echo json_encode(array_map('intval', array_column($rooms[$node_key], 'temp'))) ?>,
            borderWidth: 1,
            order: <?php echo json_encode($zindex--) ?>,
            yAxisID: 'left',
            borderColor: '#ff0000',
            backgroundColor: '#ff0000'
          },
          {
            type: 'line',
            label: 'Humidity (%)',
            data: <?php echo json_encode(array_map('intval', array_column($rooms[$node_key], 'hum'))) ?>,
            borderWidth: 1,
            order: <?php echo json_encode($zindex--) ?>,
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
            text: <?php echo json_encode($titles[$node_key]) ?>
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
