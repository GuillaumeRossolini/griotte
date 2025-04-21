#!/usr/bin/env bash

cat /home/pi/griotte/buffer.csv \
    | sed "s/\t/,/g"

touch /home/pi/griotte/buffer.csv
