-- Get readings since a specific date, grouped by date and node
-- With a CTE over LAG() to identify reboot count and last date

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
--  WHERE created_at BETWEEN ? AND ?
)

SELECT
  strftime('%Y-%m', MIN(created_at)) AS "month"
  , node
  , TIME(MIN(created_at)) AS earliest
  , TIME(MAX(created_at)) AS latest
  , MIN(heap)/1024 AS heap_ko
  , MAX(uptime) /60/60 AS uptime_h
  , SUM(CASE WHEN uptime < prev_uptime THEN 1 ELSE 0 END) AS reboots
  , COUNT(1) AS readings
  , ROUND(AVG(hpa), 1) AS avg_hpa
  , ROUND(AVG(hum), 1) AS avg_hum
  , ROUND(AVG(temp), 1) AS avg_temp
  , ROUND(AVG(iaq), 1) AS avg_iaq
  , ROUND(AVG(eco2), 1) AS avg_eco2
  , ROUND(AVG(voc*100), 1) AS avg_voc
FROM _readings
GROUP BY strftime('%Y-%m', created_at), node
ORDER BY strftime('%Y-%m', MIN(created_at)) DESC, node ASC;
