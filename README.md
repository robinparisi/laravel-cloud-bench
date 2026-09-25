# Laravel Cloud benchmark

A minimal Laravel app. Each route isolates one cost, so a page's time can be
split between the network, the platform and the application.

Every route reports its own server-side time through a `Server-Timing` header,
which k6 records next to the client-side TTFB.

It was built to benchmark Laravel Cloud across a few configurations, and ended
up documenting something else: two URLs returning the same bytes are
consistently 40 to 50 ms apart. See [TTFB depends on the URL](#ttfb-depends-on-the-url).

## Setup

|             |                                                                                                        |
| ----------- | ------------------------------------------------------------------------------------------------------ |
| Application | Laravel 13, Octane on FrankenPHP, PHP 8.5.8, JIT off                                                   |
| Caches      | `config:cache` and `route:cache` applied at build                                                      |
| Compute     | Flex, 512 MiB, 1 vCPU, autoscaling off                                                                 |
| Database    | Serverless Postgres 18, ¼ compute unit                                                                 |
| Region      | Frankfurt (`eu-central`), application and database both                                                |
| Client      | A laptop in France, reaching Cloudflare through the `CDG` edge                                         |
| Load        | [k6](https://k6.io), 30 s per scenario, one at a time, at a fixed 5 requests per second (2 for `db50`) |
| Samples     | 150 per scenario, 60 for `db50`                                                                        |

Tables quote the median. The network figures depend on where the client sits, the server figures do not.

## How a request splits

|          | measured on                                                                                                                                           |
| -------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| `edge`   | a round trip to the Cloudflare edge, measured on `/cdn-cgi/trace`, which the edge answers without reaching the origin; a reference taken once per run |
| `server` | `app + boot`, reported by the application on the request itself                                                                                       |
| `beyond` | the rest of that request's TTFB: the hop to the container, platform routing, queueing, response serialisation                                         |
| `tier`   | the data centre the request reaches the origin through, read from the `CF-Ray` the origin receives                                                    |

## Results

| scenario     | what it does                      | tier  | edge | beyond | server |  **TTFB** |
| ------------ | --------------------------------- | ----- | ---: | -----: | -----: | --------: |
| `noop`       | returns `ok`                      | `MRS` | 11.9 |   56.3 |    1.4 |  **69.6** |
| `cpu`        | 200k float operations             | `CDG` | 11.9 |   25.9 |    9.0 |  **47.0** |
| `blade`      | 50 rows, one Blade component each | `MRS` | 11.9 |   54.7 |    3.3 |  **69.8** |
| `bladePlain` | the same 50 rows, inline markup   | `AMS` | 11.9 |   41.3 |    2.2 |  **55.5** |
| `db1`        | one lookup by primary key         | `AMS` | 11.9 |   35.6 |    6.3 |  **54.9** |
| `db50`       | fifty of them                     | `AMS` | 11.9 |   64.5 |  189.1 | **271.7** |

`tier` sets `beyond`: 26 ms through Paris, 36 to 41 through Amsterdam, 55
through Marseille. It is a property of the URL rather than of the scenario,
which is why `cpu` answers sooner than `noop` while doing sixty times more
work. Compare scenarios on `server` below. `db50` sits above its tier at 64.5, which I
have not accounted for: it is also the only scenario with a long service time,
and the only one measured on 60 samples rather than 150.

The three parts (edge, beyond, server) add up on any single request, but each column is the median of
its own distribution, so the columns do not sum exactly.

### Focus on the server part

`app` is the application's own code, `db` the part of it spent in queries,
and `boot` everything that runs before the application's code on each request.

| scenario     |    app |    db | boot |
| ------------ | -----: | ----: | ---: |
| `noop`       |   0.12 |     0 | 1.32 |
| `cpu`        |   7.70 |     0 | 1.33 |
| `blade`      |   1.93 |     0 | 1.35 |
| `bladePlain` |   0.81 |     0 | 1.35 |
| `db1`        |   4.96 |   4.1 | 1.30 |
| `db50`       | 187.88 | 174.8 | 1.28 |

### What these say

**Laravel costs almost nothing.** The framework spends **1.3 ms** per request
before the application's own code runs, and the same on every scenario. Despite
its name, `boot` is not a framework boot: under Octane that happens once per
worker, so nothing accumulates from one request to the next.

**A Blade component costs 22 µs.** `blade` and `bladePlain` render identical
markup and differ only in how: `(1.93 − 0.81) / 50`. A page rendering 700 of
them spends 15 ms on that, which is rarely the problem on a slow page.

**A database round trip costs 3.5 ms**, for a lookup by primary key in the same
region. The cost is the round trip, not the query: fifty of them take 175 ms,
against 1.3 ms for the whole framework. Query count is the only
application-side number that matters at this scale.

**The tail comes from the database.** `db1` has a median of 55 ms and a 99th
percentile of 380 ms, all of it in `db_ms`. Repeat the round trip fifty times
and catching a slow one becomes likely.

## TTFB depends on the URL

While measuring, TTFB turned out not to be stable, and not in proportion to the
work either: `cpu` does sixty times more of it than `noop` and answers 22 ms
sooner. The reason is not in the application.

To test that, I measured ten URLs on the same route, differing only by a query parameter the application never reads and all returning the same bytes:

| URL            | TTFB | routed through  |
| -------------- | ---: | --------------- |
| `/b/peer?x=2`  | 39.2 | `CDG` Paris     |
| `/b/peer?x=1`  | 39.4 | `CDG`           |
| `/b/peer?x=8`  | 43.4 | `CDG`           |
| `/b/peer?x=4`  | 52.5 | `AMS` Amsterdam |
| `/b/peer?x=7`  | 55.7 | `AMS`           |
| `/b/peer?x=10` | 59.2 | `MRS` Marseille |
| `/b/peer?x=9`  | 71.6 | `MRS`           |
| `/b/peer?x=3`  | 80.5 | `MAD` Madrid    |
| `/b/peer?x=6`  | 85.7 | `MAD`           |
| `/b/peer?x=5`  | 89.2 | `MAD`           |

Cloudflare stamps every request with `cf-ray: <id>-<colo>`, where `colo` is the
IATA code of the data centre that handled it. The client reads it from the
response header. The origin receives its own copy, which `/b/peer` echoes back
and every other bench route exposes as `X-Bench-Tier`.

On one and the same request, the two disagree.

### Ray ID pairs

Raw output of `bench/ray-pairs.sh`, ten URLs in one second:

```
timestamp (UTC)        url              ttfb_ms  detour  cf-ray, client         cf-ray, origin
2026-09-25T15:15:31Z   /b/peer?x=1      38.7     no      a40afc19be362e92-CDG   a40afc19be362e92-CDG
2026-09-25T15:15:31Z   /b/peer?x=2      60.3     no      a40afc1a5a26d560-CDG   a40afc1a5a26d560-CDG
2026-09-25T15:15:31Z   /b/peer?x=3      142.3    YES     a40afc1b1ee5bb7b-CDG   a40afc1b1ee5bb7b-MAD
2026-09-25T15:15:31Z   /b/peer?x=4      54.2     YES     a40afc1c6a574e73-CDG   a40afc1c6a574e73-AMS
2026-09-25T15:15:31Z   /b/peer?x=5      92.7     YES     a40afc1d2a88d574-CDG   a40afc1d2a88d574-MAD
2026-09-25T15:15:32Z   /b/peer?x=6      86.3     YES     a40afc1e18baf0d3-CDG   a40afc1e18baf0d3-MAD
2026-09-25T15:15:32Z   /b/peer?x=7      51.9     YES     a40afc1f0883d10c-CDG   a40afc1f0883d10c-AMS
2026-09-25T15:15:32Z   /b/peer?x=8      47.1     no      a40afc1fa89c2285-CDG   a40afc1fa89c2285-CDG
2026-09-25T15:15:32Z   /b/peer?x=9      67.9     YES     a40afc2059f04562-CDG   a40afc2059f04562-MRS
2026-09-25T15:15:32Z   /b/peer?x=10     61.0     YES     a40afc211c181b44-CDG   a40afc211c181b44-MRS
```

The identifier matches on every line; the data centre does not on seven of them.
Three URLs reach the origin from Paris, with no detour at all. The latency
follows:

| through | path                          | straight line |  TTFB |
| ------- | ----------------------------- | ------------: | ----: |
| `CDG`   | Paris → Frankfurt             |        480 km | 39-43 |
| `AMS`   | Paris → Amsterdam → Frankfurt |        790 km | 53-56 |
| `MRS`   | Paris → Marseille → Frankfurt |       1460 km | 59-72 |
| `MAD`   | Paris → Madrid → Frankfurt    |       2500 km | 81-89 |

These responses are `cache-control: no-cache, private`, so Cloudflare marks them
`cf-cache-status: BYPASS` and caches them nowhere. The upper tier stores nothing
and spares the origin nothing: on dynamic routes the detour is pure cost,
**up to 50 ms on every request**, decided by the URL.

### Why a dynamic request takes the detour

`cf-cache-status` records two different refusals. `DYNAMIC` is decided when the
request arrives, from the URL and the zone's rules, before the cache is
consulted at all. `BYPASS` is decided from the response: the request _was_
eligible, and only the headers it came back with prevented storing.

These responses come back `BYPASS`. They were therefore eligible on arrival, and
tiered on that basis. Nothing is stored, so nothing is learned from it: the next
request takes the same detour.

The upper tier is picked by hashing the cache key, within a regional pool and
without regard to where the origin sits. From Paris that pool includes Madrid.

I cannot see the zone's configuration, so I may be missing a constraint.

> [!IMPORTANT]
> **Could the upper tier be pinned near each origin**, through Smart Tiered
> Cache with a cloud region hint? Or could uncacheable routes be made ineligible
> at request time, so that they answer `DYNAMIC` and skip tiering altogether?

### References

- [Investigate uncached responses](https://developers.cloudflare.com/cache/troubleshooting/investigating-uncached-responses/)
  and [cache responses](https://developers.cloudflare.com/cache/concepts/cache-responses/): `DYNAMIC` is decided at request time, `BYPASS` at response time.
- [cloudflare-docs PR #33587](https://github.com/cloudflare/cloudflare-docs/pull/33587)
  (open, September 2026): an eligible request may be routed through an upper
  tier before the origin's headers are read, and Generic Global picks that tier
  by hashing the cache key against a regional pool. The measurements above are
  the evidence; this only shows Cloudflare describing the same mechanism.
- [Tiered Cache](https://developers.cloudflare.com/cache/how-to/tiered-cache/)
- [Smart Tiered Cache](https://developers.cloudflare.com/smart-shield/configuration/smart-tiered-cache/): selects a single upper tier close to the origin.

## Reproducing

```bash
composer install && php artisan migrate --force
php artisan db:seed --class=BenchRowSeeder --force

bench/run.sh https://your-app.laravel.cloud frankfurt      # the matrix, ~4 min
bench/tiered-cache.sh https://your-app.laravel.cloud       # the routing, ~30 s
```

`bench/run.sh` writes a JSON report per run under `bench/results/`, including a
control that requests ten URLs returning the same bytes, in rotating order
within one iteration, so that drift and ordering cannot explain a spread
between them.

Set `BENCH_REGION` on each environment; `/b/info` reports the runtime, the JIT
state and whether the caches are warm, and every result file carries it.

## Caveats

Measured on 23 and 24 September 2026, from one client in one location. Medians
rest on 60 to 150 samples per scenario; the 99th percentiles on far fewer, and
should be read as "this happened" rather than as a rate.

Cloudflare's pool of upper tiers is not fixed, so a given URL may not take the
same path months from now. The mechanism should outlive the table.
