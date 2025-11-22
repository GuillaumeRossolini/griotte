-- Get readings since a specific date, grouped by date and node
-- With a CTE over LAG() to identify reboot count and last date

WITH _readings AS (
  SELECT node, created_at, heap, uptime
    , LAG (uptime) OVER (PARTITION BY node ORDER BY created_at) AS lag
  FROM sensor_reading
--  WHERE DATE(created_at) = '2025-06-08'
)
, _reboots AS (
  SELECT node, created_at, lag
  FROM _readings
  WHERE uptime < lag
)

SELECT
  DATE(MIN(created_at)) AS day,
  node,
  TIME(MIN(created_at)) AS earliest_reading,
  TIME(MAX(created_at)) AS latest_reading,
  MIN(heap)/1024 AS heap_ko,
  MAX(uptime) /60/60 AS uptime_h,
  COUNT(_reboots.created_at) AS reboots,
  COUNT(1) AS readings
FROM _readings
LEFT JOIN _reboots USING (node, created_at)
GROUP BY DATE(created_at), node
ORDER BY DATE(created_at) DESC, node ASC;
