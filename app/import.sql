CREATE TABLE IF NOT EXISTS sensor_reading (
    id INTEGER PRIMARY KEY,
    created_at TEXT,
    node INTEGER,
    hpa INTEGER,
    hum INTEGER,
    temp INTEGER,
    iaq REAL,
    eco2 INTEGER,
    voc REAL,
    accuracy INTEGER,
    heap INTEGER,
    ip INTEGER,
    subs INTEGER,
    stability INTEGER,
    uptime INTEGER
);

CREATE TEMPORARY TABLE IF NOT EXISTS csv_import (
    created_at INTEGER,
    node INTEGER,
    hpa INTEGER,
    hum INTEGER,
    temp INTEGER,
    iaq REAL,
    eco2 INTEGER,
    voc REAL,
    accuracy INTEGER,
    heap INTEGER,
    ip INTEGER,
    subs INTEGER,
    stability INTEGER,
    uptime INTEGER
);

.mode csv
.headers off
.nullvalue NULL

-- run the script to import the raw data into a temporary table
.import |csv2sqlite csv_import

SELECT 'Importing ' || COUNT(1) || ' rows...' FROM csv_import;

-- normalize & copy the raw data into the actual table
INSERT INTO sensor_reading (created_at, node, hpa, hum, temp, iaq, eco2, voc, accuracy, heap, ip, subs, stability, uptime)
SELECT datetime(created_at, 'unixepoch'), node, hpa, hum, temp, iaq, eco2, voc, accuracy, heap, ip, subs, stability, uptime
FROM csv_import
ORDER BY created_at, node;

-- delete the temporary data
DELETE FROM csv_import;
