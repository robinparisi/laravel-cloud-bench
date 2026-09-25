#!/usr/bin/env bash
#
# Collects cf-ray pairs for a support ticket: the one the client is given, and
# the one the origin received, for the same request. A mismatch means the
# request was forwarded through another data centre on the way in.
#
#   bench/ray-pairs.sh https://your-app.laravel.cloud [urls]
#
# The app is warmed first, so a cold start or a cold path does not end up in
# the table as if it were the phenomenon.
set -euo pipefail

BASE_URL=${1:-}
URLS=${2:-10}

if [[ -z "$BASE_URL" ]]; then
    echo "usage: bench/ray-pairs.sh <base-url> [urls]" >&2
    exit 1
fi

BASE_URL=${BASE_URL%/}
export LC_ALL=C

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

for ((i = 0; i < 2; i++)); do
    for ((n = 1; n <= URLS; n++)); do
        curl -sS -o /dev/null "$BASE_URL/b/peer?x=$n"
    done
done

printf '%-22s %-16s %-8s %-7s %-24s %-24s\n' \
    "timestamp (UTC)" "url" "ttfb_ms" "detour" "cf-ray, client" "cf-ray, origin"

for ((n = 1; n <= URLS; n++)); do
    timing=$(curl -sS -D "$work/headers" -o "$work/body" \
        -w '%{time_appconnect} %{time_starttransfer}' "$BASE_URL/b/peer?x=$n")

    stamp=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    ms=$(awk -v t="$timing" 'BEGIN {split(t, p, " "); printf "%.1f", (p[2] - p[1]) * 1000}')
    client=$(tr -d '\r' < "$work/headers" | awk 'tolower($1) == "cf-ray:" {print $2}')
    origin=$(sed -E 's/.*"cf_ray":"([^"]+)".*/\1/' "$work/body")

    detour=$([[ "${client##*-}" == "${origin##*-}" ]] && echo "no" || echo "YES")

    printf '%-22s %-16s %-8s %-7s %-24s %-24s\n' \
        "$stamp" "/b/peer?x=$n" "$ms" "$detour" "$client" "$origin"
done
