#!/usr/bin/env ash

set -eCfu

buffer_filename="${GRIOTTE_RUN_PATH}/buffer.csv"

if [ ! -f "$buffer_filename" ]; then
  exit 1
fi

if [ -f "$buffer_filename" ]; then
  cat "$buffer_filename" \
    | sed "s/\t/,/g"
fi

touch "$buffer_filename"
