#!/usr/bin/env sh

set -e

SERVER_ADDR="127.0.0.1:8081"
if [ ! -z "$1" ]; then
  SERVER_ADDR="$1"
fi

APP_NAME="Griotte"
if [ ! -z "$2" ]; then
  APP_NAME="$2"
fi

SERIAL_NB="123"
if [ ! -z "$3" ]; then
  SERIAL_NB="$3"
fi

TMP_REQUEST="$(tempfile)"
TMP_HEADERS="$(tempfile)"
TMP_RESPONSE="$(tempfile)"

function cleanup() {
  rm $TMP_REQUEST
  rm $TMP_HEADERS
  rm $TMP_RESPONSE
}

trap cleanup EXIT

echo "curl --silent \
  --data 'struct=typology&signal=1&typology={\"msg\":\"azerty\"}' \
  --header \"User-Agent: ${APP_NAME}/${SERIAL_NB}\" \
  --header \"Content-Type: application/x-www-form-urlencoded\" \
  --write-out \"%{response_code}\" \
  --dump-header \"${TMP_HEADERS}\" \
  --output \"${TMP_RESPONSE}\" \
  http://${SERVER_ADDR}" > ${TMP_REQUEST}

response_code=$(bash $TMP_REQUEST)

if [[ 200 -ne $response_code || "thanks" != "$(cat $TMP_RESPONSE)" ]]; then
  >&2 echo "Failed:"
  cat $TMP_REQUEST >&2
  >&2 echo ""
  cat $TMP_HEADERS >&2
  cat $TMP_RESPONSE >&2
  >&2 echo ""
  exit 1
fi

cat $TMP_REQUEST >&2
echo Success
