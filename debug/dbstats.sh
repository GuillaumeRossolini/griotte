#!/usr/bin/env bash

set -e

GRIOTTE_ROOT_PATH="${GRIOTTE_ROOT_PATH:-/home/pi/griotte}"
GRIOTTE_DB_PATH="${GRIOTTE_DB_PATH:-/home/pi/griotte/volumes/db}"

# open a socket as a custom command that outputs an SQL file as a single line
exec 3< <(sed -E 's/(.*)--.+/\1/g' "${GRIOTTE_ROOT_PATH}/debug/stats.sql" | tr -s "\n" | tr "\n" "\t")

# read that SQL command to the sqlite program
sqlite3 -table -readonly "${GRIOTTE_DB_PATH}/readings.sq3" <&3

# close the socket
exec 3<&-
