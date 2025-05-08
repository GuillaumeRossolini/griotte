<?php

error_log(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Europe/Paris');
ob_start("ob_gzhandler");

$date_filter = empty($_GET['d']) ? date('Y-m-d') : $_GET['d'];
if(!preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $date_filter, $match) or !checkdate($match[2], $match[3], $match[1])) {
  throw new Exception('Invalid date format');
}

$tz_utc = new DateTimeZone('UTC');
$tz_local = new DateTimeZone(date_default_timezone_get());

$start_local = new DateTimeImmutable($date_filter, $tz_local);

$start_utc = $start_local
  ->setTimezone($tz_utc);

$end_utc = $start_local
  ->add(new DateInterval('P1D'))
  ->sub(new DateInterval('PT1S'))
  ->setTimezone($tz_utc);

unset($date_filter, $match);
?>
<!doctype html>
<html lang="en-US">
  <head>
    <meta charset="utf-8" />
    <title><?php echo html('Griotte metrics for %s', $start_local->format('Y-m-d')) ?></title>
    <script src="./?script=chart-v4.4.4.js"></script>
  </head>
<body>

<form method="get">
  <input type="date" name="d" value="<?php echo html($start_local->format('Y-m-d')) ?>"/>
</form>

<script>
  const dateForm = document.querySelector('form');
  const dateInput = dateForm.querySelector('input[type=date]');
  dateInput.addEventListener('change', function(e) {
    dateForm.submit();
  });
</script>

<?php
$db_filename = sprintf(GRIOTTE_FOLDER.'/db/v1/%d/%s/%s.sq3', $start_local->format('Y'), $start_local->format('m-F'), $start_local->format('Y-m-d'));
if(!file_exists($db_filename)) {
  syslog(LOG_ERR, sprintf('DB file does not exist: %s', $db_filename));
  echo html('DB file does not exist: %s', basename($db_filename));
  return;
}

$db = new PDO('sqlite:'.$db_filename);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

$config_filename = GRIOTTE_FOLDER.'/app/config.ini';
$config = parse_ini_file($config_filename, true);

$when_nodes = [];
foreach($config['node_labels'] as $_idx => $_val) {
  $_idx = explode('_', $_idx);
  array_shift($_idx);
  $when_nodes[] = sprintf("WHEN '%s' THEN '%s'", implode(' ', $_idx), $_val);
}
$when_floors = [];
foreach($config['node_floors'] as $_idx => $_val) {
  $_idx = explode('_', $_idx);
  array_shift($_idx);
  $when_floors[] = sprintf("WHEN '%s' THEN %d", implode(' ', $_idx), $_val);
}

$sql_tpl = <<<SQL
SELECT node
  , CASE node
    %s
    ELSE node
  END AS node_lbl
  , CASE node
    %s
    ELSE 3
  END AS floor
  , COUNT(1) AS nb
  , MIN(created_at) AS earliest
  , MAX(created_at) AS latest
  , ROUND(AVG(hpa/100), 2) AS avg_hpa
  , ROUND(AVG(hum), 2) AS avg_hum
  , ROUND(AVG(temp), 2) AS avg_temp
  , CASE WHEN ROUND(AVG(iaq/5), 2) > 100 THEN 100 ELSE ROUND(AVG(iaq/5), 2) END AS avg_iaq
  , ROUND(AVG(eco2), 2) AS avg_eco2
  , ROUND(AVG(voc*100), 2) AS avg_voc
FROM sensor_reading
WHERE true
  AND created_at BETWEEN ? AND ?
GROUP BY node_lbl
ORDER BY floor, node_lbl
SQL;

$sql = sprintf($sql_tpl, implode(PHP_EOL, $when_nodes), implode(PHP_EOL, $when_floors));

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
  syslog(LOG_ERR, sprintf('No data for %s to %s in %s', $start_utc->format('Y-m-d H:i:s'), $end_utc->format('Y-m-d H:i:s'), $db_filename));
  ?><h1><?php echo html('No data for %s', $start_local->format('Y-m-d')) ?></h1><?php
  return;
}

$datasets = [
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

<canvas id="overview"></canvas>

<script>
  new Chart(document.getElementById('overview'), {
    data: {
      labels: <?php echo json_encode($labels) ?>,
      datasets: [
        <?php foreach($datasets as $field => $ds): ?>
        {
          type: 'bar',
          label: <?php echo json_encode($ds['label']) ?>,
          data: <?php echo json_encode(array_map('intval', array_column($overview, 'avg_'.$field))) ?>,
          borderWidth: 1,
          weight: 1,
          order: <?php echo json_encode($zindex--) ?>,
          yAxisID: 'right',
          borderColor: <?php echo json_encode($ds['color']) ?>,
          backgroundColor: <?php echo json_encode($ds['color']) ?>
        },
        <?php endforeach; ?>
        {
          type: 'line',
          label: 'Barometric [hPA]',
          data: <?php echo json_encode(array_map('intval', array_column($overview, 'avg_hpa'))) ?>,
          borderWidth: 1,
          order: <?php echo json_encode($zindex--) ?>,
          yAxisID: 'right',
          borderColor: '#c9cbcf',
          backgroundColor: '#c9cbcf'
        },
        {
          type: 'line',
          label: 'Temperature [°C]',
          data: <?php echo json_encode(array_map('intval', array_column($overview, 'avg_temp'))) ?>,
          borderWidth: 1,
          order: <?php echo json_encode($zindex--) ?>,
          yAxisID: 'left',
          borderColor: '#ff0000',
          backgroundColor: '#ff0000'
        },
        {
          type: 'line',
          label: 'Humidity [%]',
          data: <?php echo json_encode(array_map('intval', array_column($overview, 'avg_hum'))) ?>,
          borderWidth: 1,
          order: <?php echo json_encode($zindex--) ?>,
          yAxisID: 'left',
          borderColor: '#0000ff',
          backgroundColor: '#0000ff'
        },
        {
          type: 'line',
          label: 'IAQ [%]',
          data: <?php echo json_encode(array_map('intval', array_column($overview, 'avg_iaq'))) ?>,
          borderWidth: 1,
          order: <?php echo json_encode($zindex--) ?>,
          yAxisID: 'left',
          borderColor: '#96f',
          backgroundColor: '#96f'
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
  , CASE WHEN hpa <= 0 THEN 0 ELSE ROUND(hpa/100, 2)-920 END AS hpa
  , ROUND(hum, 2) AS hum
  , ROUND(temp, 2) AS temp
  , CASE WHEN ROUND(iaq/5, 2) > 100 THEN 100 ELSE ROUND(iaq/5, 2) END AS iaq
  , ROUND(eco2, 2) AS eco2
  , ROUND(voc*100, 2) AS voc
FROM sensor_reading
WHERE true
  AND node = ?
  AND created_at BETWEEN ? AND ?
ORDER BY created_at
SQL;

$stmt = $db->prepare($sql);

$sensors = [];
$labels = [];
$titles = [];
foreach($overview as $node_key => $node_average) {
  $sensors[$node_key] = [];

  $stmt->execute([
    $node_average['node'],
    $start_utc->format('Y-m-d H:i:s'),
    $end_utc->format('Y-m-d H:i:s'),
  ]);

  foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $res) {
    $created_at = $res['created_at'];
    unset($res['created_at']);
    $sensors[$node_key][$created_at] = array_map('intval', $res);
    $t = new DateTimeImmutable($created_at, $tz_utc);
    $labels[$node_key][] = $t->setTimezone($tz_local)->format('H:i:s');
  }

  if(empty($sensors[$node_key])) {
    continue;
  }

  reset($sensors[$node_key]);
  $earliest = (new DateTimeImmutable(key($sensors[$node_key]), $tz_utc))
    ->setTimezone($tz_local);

  end($sensors[$node_key]);
  $latest = (new DateTimeImmutable(key($sensors[$node_key]), $tz_utc))
    ->setTimezone($tz_local);

  reset($sensors[$node_key]);

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
  <canvas id="<?php echo html('room-%s', $node_key) ?>"></canvas>

  <script>
    new Chart(document.getElementById(<?php echo json_encode(sprintf('room-%s', $node_key)) ?>), {
      data: {
        labels: <?php echo json_encode($labels[$node_key]) ?>,
        datasets: [
          <?php foreach($datasets as $field => $ds): ?>
          {
            type: 'line',
            label: <?php echo json_encode($ds['label']) ?>,
            data: <?php echo json_encode(array_map('intval', array_column($sensors[$node_key], $field))) ?>,
            borderWidth: 1,
            weight: 1,
            order: <?php echo json_encode($zindex--) ?>,
            yAxisID: 'right',
            borderColor: <?php echo json_encode($ds['color']) ?>,
            backgroundColor: <?php echo json_encode($ds['color']) ?>
          },
          <?php endforeach; ?>
          {
            type: 'line',
            label: 'Barometric [hPA-920]',
            data: <?php echo json_encode(array_map('intval', array_column($sensors[$node_key], 'hpa'))) ?>,
            borderWidth: 1,
            order: <?php echo json_encode($zindex--) ?>,
            yAxisID: 'left',
            borderColor: '#c9cbcf',
            backgroundColor: '#c9cbcf'
          },
          {
            type: 'line',
            label: 'Temperature [°C]',
            data: <?php echo json_encode(array_map('intval', array_column($sensors[$node_key], 'temp'))) ?>,
            borderWidth: 1,
            order: <?php echo json_encode($zindex--) ?>,
            yAxisID: 'left',
            borderColor: '#ff0000',
            backgroundColor: '#ff0000'
          },
          {
            type: 'line',
            label: 'Humidity [%]',
            data: <?php echo json_encode(array_map('intval', array_column($sensors[$node_key], 'hum'))) ?>,
            borderWidth: 1,
            order: <?php echo json_encode($zindex--) ?>,
            yAxisID: 'left',
            borderColor: '#0000ff',
            backgroundColor: '#0000ff'
          },
          {
            type: 'line',
            label: 'IAQ [%]',
            data: <?php echo json_encode(array_map('intval', array_column($sensors[$node_key], 'iaq'))) ?>,
            borderWidth: 1,
            order: <?php echo json_encode($zindex--) ?>,
            yAxisID: 'left',
            borderColor: '#96f',
            backgroundColor: '#96f'
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
