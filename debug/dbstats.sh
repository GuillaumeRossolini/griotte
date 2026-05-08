#!/usr/bin/env sh

set -e

GRIOTTE_ROOT_PATH="${GRIOTTE_ROOT_PATH:-/home/pi/griotte}"
GRIOTTE_DB_PATH="${GRIOTTE_DB_PATH:-/home/pi/griotte/volumes/db}"

exec 3< <(sed -E 's/(.*)--.+/\1/g' "${GRIOTTE_ROOT_PATH}/debug/stats.sql" | tr -s "\n" | tr "\n" "\t")

sqlite3 -table -readonly "${GRIOTTE_DB_PATH}/readings.sq3" <&3

exec 3<&-
