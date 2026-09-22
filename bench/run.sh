#!/usr/bin/env bash
#
# Runs one benchmark matrix cell and stores the result under bench/results.
#
#   bench/run.sh https://fra-octane.example.com fra-octane
#
# Every result file records the environment metadata reported by /b/info, so a
# run stays attributable to the region, runtime and commit that produced it.
set -euo pipefail

BASE_URL=${1:-}
LABEL=${2:-}

if [[ -z "$BASE_URL" || -z "$LABEL" ]]; then
    echo "usage: bench/run.sh <base-url> <label>" >&2
    exit 1
fi

cd "$(dirname "$0")/.."

RUN_ID="${LABEL}-$(date -u +%Y%m%dT%H%M%SZ)"

echo "Environment under test:"
curl -fsS "${BASE_URL%/}/b/info"
echo

k6 run \
    -e "BASE_URL=$BASE_URL" \
    -e "RUN_ID=$RUN_ID" \
    -e "RATE_SCALE=${RATE_SCALE:-1}" \
    -e "DURATION_S=${DURATION_S:-30}" \
    bench/k6/scenarios.js

echo "Written to bench/results/${RUN_ID}.json"
