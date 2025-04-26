CREATE TABLE IF NOT EXISTS sensor_reading (
    id INTEGER PRIMARY KEY,
    created_at TEXT,
    node INTEGER,
    hpa INTEGER,
    hum INTEGER,
    temp INTEGER,
    iaq REAL,
    eco2 INTEGER,
    voc INTEGER
);

CREATE TEMPORARY TABLE IF NOT EXISTS csv_import (
    created_at INTEGER,
    node INTEGER,
    hpa INTEGER,
    hum INTEGER,
    temp INTEGER,
    iaq REAL,
    eco2 INTEGER,
    voc INTEGER
);

DELETE FROM csv_import;

.headers off
.mode csv
.nullvalue NULL

.import |csv2sqlite csv_import

INSERT INTO sensor_reading (created_at, node, hpa, hum, temp, iaq, eco2, voc)
SELECT datetime(created_at, 'unixepoch'), node, hpa, hum, temp, iaq, eco2, voc
FROM csv_import
ORDER BY node, created_at;
