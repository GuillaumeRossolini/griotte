#!/usr/bin/env bash

set -e

TMP_REQUEST="$(mktemp)"
TMP_HEADERS="$(mktemp)"
TMP_RESPONSE="$(mktemp)"
TMP_TIMINGS="$(mktemp)"

function cleanup() {
  rm $TMP_REQUEST $TMP_HEADERS $TMP_RESPONSE $TMP_TIMINGS
}

trap cleanup EXIT

SERVER_ADDR="${1:-127.0.0.1:8081}"
APP_NAME="${2:-Griotte}"
SERIAL_NB="${3:-123}"

if [ ! -z "$4" ]; then
  MESSAGE="$4"
else
  MESSAGE=$(jq -n -c --arg nodeId "$SERIAL_NB" '{"nodeId": $nodeId, "subs": [], "root": true}' | base64)
fi

PAYLOAD=$(jq -n -c --arg msg "$MESSAGE" '{"msg": $msg}')

echo "curl --silent \
  --data 'struct=typology&signal=1&typology=$PAYLOAD' \
  --header \"User-Agent: ${APP_NAME}/${SERIAL_NB}\" \
  --header \"Content-Type: application/x-www-form-urlencoded\" \
  --write-out 'response_code=%{response_code}
namelookup=%{time_namelookup}
connect=%{time_connect}
appconnect=%{time_appconnect}
pretransfer=%{time_pretransfer}
starttransfer=%{time_starttransfer}
total=%{time_total}' \
  --dump-header \"${TMP_HEADERS}\" \
  --output \"${TMP_RESPONSE}\" \
  http://${SERVER_ADDR}" > ${TMP_REQUEST}

bash $TMP_REQUEST > $TMP_TIMINGS
response_code="$(cat $TMP_TIMINGS | head -n1 | cut -d'=' -f2)"

if [[ 200 -ne $response_code || "ok" != "$(cat $TMP_RESPONSE)" ]]; then
  >&2 echo "Failed:"
  cat $TMP_REQUEST >&2
  >&2 echo ""
  cat $TMP_HEADERS >&2
  cat $TMP_RESPONSE >&2
  >&2 echo ""
  cat $TMP_TIMINGS >&2
  >&2 echo ""
  exit 1
fi

cat $TMP_REQUEST >&2
echo "" >&2
cat $TMP_HEADERS >&2
echo "" >&2
echo Success
echo "" >&2
cat $TMP_TIMINGS >&2
echo "" >&2
