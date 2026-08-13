<?php

if(GRIOTTE_DEBUG) {
  ini_set('display_errors', 1);
}

$config_filename = GRIOTTE_ROOT_PATH.'/app/config.ini';
$config = parse_ini_file($config_filename, true);

date_default_timezone_set($config['general']['timezone']);
ob_start('ob_gzhandler');

$date_filter = empty($_GET['d']) ? date('Y-m-d') : $_GET['d'];
if(!preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $date_filter, $_match) or !checkdate($_match[2], $_match[3], $_match[1])) {
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


$nodes_cfg = [];
foreach($config['nodes'] as $_key => $_val) {
  if(!preg_match('~^esp\.(\d+)\.(label|floor|comments)$~', $_key, $_match)) {
    continue;
  }
  list(, $_node, $_idx) = $_match;
  $nodes_cfg[$_node][$_idx] = $_val;
}
unset($date_filter, $_match, $_key, $_val, $_node, $_idx);
?>
<!doctype html>
<html lang="en-US">
  <head>
    <meta charset="utf-8" />
    <title><?php echo html('%s metrics for %s', GRIOTTE_LABEL, $start_local->format('Y-m-d')) ?></title>
    <script src="/statics/chart-v4.5.1.js"></script>
    <script src="/statics/luxon-v2.js"></script>
    <script src="/statics/chartjs-adapter-luxon-v1.3.1.js"></script>

<style type="text/css">
  h1 {
    text-align: center;
  }

  canvas {
    margin-top: 50px;
  }

  table {
    border: 1px solid black;
    margin-top: 20px;
    margin-left: auto;
    margin-right: auto;
    margin-bottom: 20px;
  }

  tfoot tr td {
    font-style: italic;
  }

  tbody tr td {
    border: 1px dotted black;
    padding-left: 10px;
    padding-right: 10px;
  }

  td[role="nb"] {
    text-align: right;
  }

  td[palette] {
    text-shadow: 0 0 1px white;
    font-weight: bold;
  }

  td[palette="purple"] {
    background-color: rgb(168 85 247 / var(--alpha));
  }

  td[palette="blue"] {
    background-color: rgb(59 130 246 / var(--alpha));
  }

  td[palette="red"] {
    background-color: rgb(239 68 68 / var(--alpha));
  }

  td[palette="green"] {
    background-color: rgb(20 184 166 / var(--alpha));
  }

  td[palette="yellow"] {
    background-color: rgb(234 179 8 / var(--alpha));
  }

</style>

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
$db_filename = db_filename($start_local);
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


/**
 * Start the age with a high-level visualization of all the nodes for the chosen date,
 * with their average readings and their health
 */

$when = [];
foreach($nodes_cfg as $_node => $_cfg) {
  $when['label'][] = sprintf("WHEN '%s' THEN '%s'", $_node, $_cfg['label']);
  $when['floor'][] = sprintf("WHEN '%s' THEN '%d'", $_node, $_cfg['floor']);
}

$sql_tpl = <<<SQL
WITH _readings AS (
  SELECT node, created_at, heap, uptime
    , hpa/100 AS hpa
    , hum
    , temp
    , (100 - CASE WHEN ROUND(iaq/5, 2) > 100 THEN 100 ELSE ROUND(iaq/5, 2) END) AS iaq
    , eco2
    , voc*100 AS voc
    , accuracy/3*100 AS accuracy
    , LAG (uptime) OVER (PARTITION BY node ORDER BY created_at) AS prev_uptime
  FROM sensor_reading
  WHERE created_at BETWEEN ? AND ?
)

SELECT
  node
  , CASE node
    %s
    ELSE node
  END AS node_lbl
  , CASE node
    %s
    ELSE 3
  END AS floor
  , MIN(created_at) AS earliest_reading
  , MAX(created_at) AS latest_reading
  , MIN(heap)/1024 AS heap_ko
  , MAX(uptime) /60/60 AS uptime_h
  , SUM(CASE WHEN uptime < prev_uptime THEN 1 ELSE 0 END) AS nb_reboots
  , COUNT(1) AS nb_readings
  , AVG(hpa) AS avg_hpa
  , AVG(hum) AS avg_hum
  , AVG(temp) AS avg_temp
  , AVG(iaq) AS avg_iaq
  , AVG(eco2) AS avg_eco2
  , AVG(voc*100) AS avg_voc
FROM _readings
GROUP BY node
ORDER BY floor DESC, node_lbl ASC
SQL;


$sql = sprintf(
  $sql_tpl,
  implode(PHP_EOL, $when['label']),
  implode(PHP_EOL, $when['floor'])
);

$stmt = $db->prepare($sql);
$stmt->execute([
  $start_utc->format('Y-m-d H:i:s'),
  $end_utc->format('Y-m-d H:i:s'),
]);

$health = [];
$comments = [];
foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $res) {
  $health[$res['node_lbl']] = $res;
  $comments[$res['node_lbl']] = isset($nodes_cfg[$res['node']]['comments']) ? trim($nodes_cfg[$res['node']]['comments']) : null;
}

if(!$health) {
  syslog(LOG_ERR, sprintf('No data for %s to %s in %s', $start_utc->format('Y-m-d H:i:s'), $end_utc->format('Y-m-d H:i:s'), $db_filename));
  ?><h1><?php echo html('No data for %s', $start_local->format('Y-m-d')) ?></h1><?php
  return;
}

$earliest_times = array_filter(array_column($health, 'earliest_reading'));
natsort($earliest_times);
$earliest = (new DateTimeImmutable(reset($earliest_times), $tz_utc))
  ->setTimezone($tz_local);

$latest_times = array_filter(array_column($health, 'latest_reading'));
natsort($latest_times);
$latest = (new DateTimeImmutable(end($latest_times), $tz_utc))
  ->setTimezone($tz_local);

$title = sprintf(
  'Overview on %s between %s and %s',
  $earliest->format('Y-m-d'),
  $earliest->format('H:i:s'),
  $latest->format('H:i:s')
);

$nb_nodes = count(array_column($health, 'node'));
$nb_reboots = array_sum(array_column($health, 'nb_reboots'));
$nb_readings = array_sum(array_column($health, 'nb_readings'));
$nb_comments = count(array_filter($comments));
?>

<h1><?php echo html($title) ?></h1>

<table>
  <thead>
    <tr>
      <th>Node</th>
      <th>Floor</th>
      <?php if($nb_comments): ?>
        <th>Comments</th>
      <?php endif; ?>
      <th>Earliest</th>
      <th>Latest</th>
      <th>Readings</th>
      <th>Free heap</th>
      <th>Reboots</th>
      <th>Uptime</th>
      <th>AVG Atmo</th>
      <th>AVG Hum</th>
      <th>AVG Temp</th>
      <th>AVG Quality</th>
    </tr>
  </thead>

  <tfoot>
    <tr>
      <td role="nb"><?php echo html('%d nodes', $nb_nodes)?></td>
      <td></td>
      <?php if($nb_comments): ?>
        <td></td>
      <?php endif; ?>
      <td></td>
      <td></td>
      <td role="nb"><?php echo html('%d total', $nb_readings)?></td>
      <td></td>
      <td role="nb"><?php echo html('%d total', $nb_reboots)?></td>
      <td></td>
      <td></td>
      <td></td>
      <td></td>
      <td></td>
    </tr>
  </tfoot>

  <tbody>
    <?php foreach($health as $node_idx => $res): ?>
      <?php
      $earliest = (new DateTimeImmutable($res['earliest_reading'], $tz_utc))
        ->setTimezone($tz_local);
      $latest = (new DateTimeImmutable($res['latest_reading'], $tz_utc))
        ->setTimezone($tz_local);
      ?>

      <tr>
        <td><acronym title="<?php echo html('Node #%s', $res['node'])?>"><?php echo html($res['node_lbl']);?></acronym></td>
        <td role="nb"><?php echo html($res['floor']);?></td>
        <?php if($nb_comments): ?>
          <td><?php echo html($nodes_cfg[$res['node']]['comments'] ?: '')?></td>
        <?php endif; ?>
        <td role="nb"><?php echo html($earliest->format('H:i:s'))?></td>
        <td role="nb"><?php echo html($latest->format('H:i:s'))?></td>
        <td role="nb"><?php echo html($res['nb_readings'])?></td>
        <td role="nb"><?php echo html('%d Ko', $res['heap_ko'])?></td>
        <td role="nb"><?php echo html($res['nb_reboots'])?></td>
        <td role="nb"><?php echo html('%d h', $res['uptime_h'])?></td>
        <td role="nb"><?php echo html('%d hPa', floor($res['avg_hpa']))?></td>
        <td role="nb" palette="blue"
          style="<?php echo html('--alpha: %d%%', round($res['avg_hum'], 0))?>"
          ><?php echo html('%d %%', round($res['avg_hum'], 0))?></td>
        <td role="nb" palette="red"
          style="<?php echo html('--alpha: %d%%', round(2*$res['avg_temp'], 0))?>"
          ><?php echo html('%d °C', round($res['avg_temp'], 0))?></td>
        <td role="nb" palette="green"
          style="<?php echo html('--alpha: %d%%', round($res['avg_iaq'], 0))?>"
          ><?php echo html('%d %%', round($res['avg_iaq'], 0))?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>

</table>



<?php

/**
 * Now we present each node individually
 * with detailed readings for the chosen date
 */

$sql = <<<SQL
WITH _readings AS (
  SELECT created_at
    , AVG(hpa) AS hpa
    , AVG(hum) AS hum
    , AVG(temp) AS temp
    , CASE WHEN AVG(voc) = 0 THEN NULL ELSE AVG(iaq) END AS iaq
    , CASE WHEN AVG(voc) = 0 THEN NULL ELSE AVG(eco2) END AS eco2
    , CASE WHEN AVG(voc) = 0 THEN NULL ELSE AVG(voc) END AS voc
    , CASE WHEN AVG(voc) = 0 THEN NULL ELSE AVG(accuracy) END AS accuracy
    , AVG(heap) AS heap
  FROM sensor_reading
  WHERE true
    AND node = ?
    AND created_at BETWEEN ? AND ?
  GROUP BY created_at
)

SELECT created_at
  , CASE WHEN hpa <= 0 THEN 0 ELSE ROUND(hpa/100, 2)-920 END AS hpa
  , ROUND(hum, 2) AS hum
  , ROUND(temp, 2) AS temp
  , (100 - CASE WHEN ROUND(iaq/5, 2) > 100 THEN 100 ELSE ROUND(iaq/5, 2) END) AS iaq
  , ROUND(eco2, 2) AS eco2
  , ROUND(voc*100, 2) AS voc
  , ROUND(accuracy/3*100, 2) AS accuracy
  , ROUND(heap, 2) AS heap
FROM _readings
ORDER BY created_at ASC
SQL;

$stmt = $db->prepare($sql);

$sensors = [];
$labels = [];
$titles = [];
foreach($health as $node_key => $node_average) {
  $sensors[$node_key] = [];

  $stmt->execute([
    $node_average['node'],
    $start_utc->format('Y-m-d H:i:s'),
    $end_utc->format('Y-m-d H:i:s'),
  ]);

  foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $res) {
    $created_at = (new DateTimeImmutable($res['created_at'], $tz_utc))
      ->setTimezone($tz_local)
      ->format(DateTimeInterface::ATOM);
    unset($res['created_at']);
    $sensors[$node_key][$created_at] = array_map('intval', $res);
  }

  if(empty($sensors[$node_key])) {
    continue;
  }

  $labels[$node_key] = array_keys($sensors[$node_key]);

  reset($sensors[$node_key]);
  $start_at = key($sensors[$node_key]);
  $earliest = (new DateTimeImmutable($start_at, $tz_utc))
    ->setTimezone($tz_local);

  end($sensors[$node_key]);
  $end_at = key($sensors[$node_key]);
  $latest = (new DateTimeImmutable($end_at, $tz_utc))
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

$datasets_left = [
  'hpa'  => ['label' => 'Barometric [hPa-920]', 'color' => '#c9cbcf'],
  'temp' => ['label' => 'Temperature [°C]', 'color' => '#ef4444'],
  'hum'  => ['label' => 'Humidity [%]', 'color' => '#3b82f6'],
  'iaq'  => ['label' => 'IAQ [%]', 'color' => '#14b8a6'],
];
$datasets_right = [
  'eco2' => ['label' => 'eCO2 [ppm]', 'color' => '#eab308'],
  'voc'  => ['label' => 'VOC [ppm]',  'color' => '#a855f7'],
];
?>

<?php foreach($health as $node_key => $node_average): ?>
  <?php
  $canvas_idx = sprintf('room_%s', md5($node_key));
  $zindex = 0;
  $datasets = [];

  foreach($datasets_right as $field => $_ds) {
    $datasets[] = [
      'type' => 'line',
      'label' => $_ds['label'],
      'data' => array_map('datapoints', array_keys($sensors[$node_key]), array_column($sensors[$node_key], $field)),
      'borderWidth' => 1,
      'weight' => 1,
      'order' => $zindex--,
      'yAxisID' => 'right',
      'borderColor' => $_ds['color'],
      'backgroundColor' => $_ds['color'],
      'spanGaps' => 'false',
    ];
  }
  foreach($datasets_left as $field => $_ds) {
    $datasets[] = [
      'type' => 'line',
      'label' => $_ds['label'],
      'data' => array_map('datapoints', array_keys($sensors[$node_key]), array_column($sensors[$node_key], $field)),
      'borderWidth' => 1,
      'order' => $zindex--,
      'yAxisID' => 'left',
      'borderColor' => $_ds['color'],
      'backgroundColor' => $_ds['color'],
      'spanGaps' => 'false',
    ];
  }
  ?>

  <canvas id="<?php echo html($canvas_idx) ?>"></canvas>

  <script>
    const <?php echo $canvas_idx ?> = document.getElementById(<?php echo json_encode($canvas_idx) ?>);

    new Chart(<?php echo $canvas_idx ?>, {
      data: {
        datasets: <?php echo json_encode($datasets); ?>
      },
      options: {
        responsive: true,
        plugins: {
          title: {
            display: true,
            text: <?php echo json_encode($titles[$node_key]) ?>
          }
        },
        scales: {
          x: {
            type: 'time',
            adapters: {
              date: {
                zone: <?php echo json_encode(date_default_timezone_get()); ?>
              }
            },
            time: {
              tooltipFormat: 'HH:mm:ss',
              displayFormats: {
                second: 'HH:mm:ss',
                minute: 'HH:mm'
              }
            }
          },
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
