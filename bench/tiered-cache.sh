#!/usr/bin/env bash
#
# Shows which Cloudflare data centre each URL is routed through, and what it
# costs. The client always sees the entry data centre in cf-ray, so the detour
# is only visible from the origin: /b/peer echoes the cf-ray it received.
#
#   bench/tiered-cache.sh https://your-app.laravel.cloud [urls] [samples]
set -euo pipefail

BASE_URL=${1:-}
URLS=${2:-10}
SAMPLES=${3:-7}

if [[ -z "$BASE_URL" ]]; then
    echo "usage: bench/tiered-cache.sh <base-url> [urls] [samples]" >&2
    exit 1
fi

BASE_URL=${BASE_URL%/}
export LC_ALL=C

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

# Interleaved: measuring one URL at a time would confuse a routing difference
# with the network drifting over the length of the run.
for ((i = 0; i < SAMPLES; i++)); do
    for ((n = 1; n <= URLS; n++)); do
        curl -sS -o /dev/null -w '%{time_appconnect} %{time_starttransfer}\n' "$BASE_URL/b/peer?x=$n" \
            | awk '{printf "%.1f\n", ($2 - $1) * 1000}' >> "$work/$n"
    done
done

printf '%-10s %-10s %-8s %s\n' "url" "ttfb_ms" "colo" "cf-ray seen by the origin"
for ((n = 1; n <= URLS; n++)); do
    ray=$(curl -sS "$BASE_URL/b/peer?x=$n" | sed -E 's/.*"cf_ray":"([^"]+)".*/\1/')
    sort -n "$work/$n" | awk -v n="$n" -v ray="$ray" '
        {a[NR] = $1}
        END {
            split(ray, parts, "-")
            printf "?x=%-7s %-10.1f %-8s %s\n", n, a[int((NR + 1) / 2)], parts[2], ray
        }'
done
