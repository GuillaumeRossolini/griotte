#!/usr/bin/env bash

GRIOTTE_FOLDER="/home/pi/griotte"
GRIOTTE_DB="/home/pi/griotte/volumes/db"

exec 3< <(sed -E 's/(.*)--.+/\1/g' "${GRIOTTE_FOLDER}/app/stats.sql" | tr -s "\n" | tr "\n" "\t")

sqlite3 -table -readonly "${GRIOTTE_DB}/readings.sq3" <&3

exec 3<&-
