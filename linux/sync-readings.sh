#!/usr/bin/env bash

set +e

GRIOT_HOST="pi.griot.local"
REMOTE_PATH="/home/pi/griotte/volumes/db"
BACKUP_FOLDER="/mnt/c/Users/IoT/Documents/bme680-readings"

time scp \
	"${GRIOT_HOST}:${REMOTE_PATH}/daily/*/*/*.sq3" \
	"${BACKUP_FOLDER}/daily/"

time scp \
	"${GRIOT_HOST}:${REMOTE_PATH}/readings.sq3" \
	"${BACKUP_FOLDER}/big_$(date +%Y-%m-%dT%H-%M-%S).sq3"

find \
	$BACKUP_FOLDER \
	-type f \
	-name "*.sq3" \
	-printf "%CY-%Cm-%Cd %CT\t%u\t%M\t%kK\t%h\t%f\n"
