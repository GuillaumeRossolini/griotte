#!/usr/bin/env sh

set -eCfu

buffer_filename="${GRIOTTE_FOLDER}/buffer.csv"

if [ -f $buffer_filename ]; then
  cat "$buffer_filename" \
    | sed "s/\t/,/g"
fi

touch "$buffer_filename"
