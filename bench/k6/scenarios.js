/**
 * Laravel Cloud benchmark run.
 *
 * Each scenario runs on its own, one after another, at a fixed arrival rate:
 * a rate that adapts to latency would send less traffic to a slower
 * environment and hide the very slowdown being measured.
 *
 * For every request the run records both the client-side TTFB and the server
 * time the app reports through Server-Timing. The gap between the two is the
 * network cost, which is what separates a region question from a runtime one.
 *
 *   k6 run -e BASE_URL=https://fra.example.com bench/k6/scenarios.js
 */
import http from 'k6/http';
import { check } from 'k6';
import { Trend } from 'k6/metrics';

const BASE_URL = (__ENV.BASE_URL || '').replace(/\/$/, '');
// Multiplies every scenario rate. Leave at 1 to measure latency; raise it to
// look for the saturation point, which is a different question.
const RATE_SCALE = Number(__ENV.RATE_SCALE || 1);
const DURATION_S = Number(__ENV.DURATION_S || 30);
const RUN_ID = __ENV.RUN_ID || `run-${Date.now()}`;

// Pause between scenarios so a queue built by one does not spill into the next.
const GAP_S = 5;

// Answered by the Cloudflare edge itself, so it never reaches the container
// and is never cached. It is the only reference that stays valid: a static file
// measures the edge cache on a hit, and a costlier cache-filling path on a miss.
const EDGE_PATH = '/cdn-cgi/trace';
const REFERENCE_SAMPLES = 15;

// One route, one response, many URLs: the argument is never read, so all of
// them return the same bytes. Each iteration requests every one of them in
// rotating order, so neither drift over the run nor position within the
// iteration can explain a spread between them. A pair would only show the
// spread when the two happen to land apart; a dozen make it visible anywhere.
const BUCKET_URL_COUNT = Number(__ENV.BUCKET_URL_COUNT || 10);
// Runs that control alone: it is all a rate sweep needs, and the application
// scenarios would saturate a single worker long before the rate says anything.
const BUCKET_ONLY = __ENV.BUCKET_ONLY === '1';

const BUCKET_URLS = Array.from(
  { length: BUCKET_URL_COUNT },
  (_, index) => `/b/noop?x=${index + 1}`,
);

// Rates sit well under what the environment sustains, so the figures are
// latency rather than queueing. Warm-up is sized per scenario: 50 requests of
// db50 alone would cost 20 seconds.
const SCENARIOS = {
  noop: { path: '/b/noop', rate: 5, warmup: 20 },
  cpu: { path: '/b/cpu', rate: 5, warmup: 20 },
  blade: { path: '/b/blade?rows=50', rate: 5, warmup: 20 },
  // Same markup, rendered without components: the difference prices one.
  bladePlain: { path: '/b/blade?rows=50&mode=plain', rate: 5, warmup: 20 },
  db1: { path: '/b/db/1', rate: 5, warmup: 20 },
  db50: { path: '/b/db/50', rate: 2, warmup: 5 },
};

// One metric set per scenario: k6 aggregates a shared metric across all tags.
// Every one of these is recorded per request, so their percentiles describe
// real requests rather than a subtraction between two aggregates.
const metrics = {};
for (const name of Object.keys(SCENARIOS)) {
  metrics[name] = {
    ttfb: new Trend(`ttfb_ms_${name}`),
    server: new Trend(`server_ms_${name}`),
    app: new Trend(`app_ms_${name}`),
    boot: new Trend(`boot_ms_${name}`),
    db: new Trend(`db_ms_${name}`),
    receiving: new Trend(`receiving_ms_${name}`),
    edge: new Trend(`edge_ms_${name}`),
    beyondEdge: new Trend(`beyond_edge_ms_${name}`),
  };
}

const bucketMetrics = BUCKET_URLS.map((_, index) => new Trend(`ttfb_ms_bucket${index}`));

export const options = {
  discardResponseBodies: true,
  summaryTrendStats: ['avg', 'min', 'med', 'p(95)', 'p(99)', 'max', 'count'],
  scenarios: Object.fromEntries(
    (BUCKET_ONLY ? [] : Object.entries(SCENARIOS)).map(([name, scenario], index) => [
      name,
      {
        executor: 'constant-arrival-rate',
        rate: scenario.rate * RATE_SCALE,
        timeUnit: '1s',
        duration: `${DURATION_S}s`,
        // Headroom so the generator is never the bottleneck being measured.
        preAllocatedVUs: Math.max(10, scenario.rate * RATE_SCALE),
        maxVUs: Math.max(50, scenario.rate * RATE_SCALE * 5),
        startTime: `${index * (DURATION_S + GAP_S)}s`,
        exec: 'scenario',
        env: { SCENARIO: name },
        tags: { scenario: name },
      },
    ]),
  ),
};

options.scenarios.bucket = {
  executor: 'constant-arrival-rate',
  rate: 2 * RATE_SCALE,
  timeUnit: '1s',
  duration: `${DURATION_S}s`,
  preAllocatedVUs: 10,
  maxVUs: 50,
  startTime: BUCKET_ONLY ? '0s' : `${Object.keys(SCENARIOS).length * (DURATION_S + GAP_S)}s`,
  exec: 'bucketPair',
  tags: { scenario: 'bucket' },
};

function serverTiming(response, name) {
  const header = response.headers['Server-Timing'];
  const match = header && header.match(new RegExp(`${name};dur=([\\d.]+)`));

  return match ? parseFloat(match[1]) : null;
}

function median(values) {
  const sorted = [...values].sort((a, b) => a - b);

  return sorted[Math.floor(sorted.length / 2)];
}

/**
 * Times a path that no scenario measures, to serve as a reference layer.
 */
function reference(path) {
  const samples = [];

  for (let i = 0; i < REFERENCE_SAMPLES; i++) {
    samples.push(http.get(`${BASE_URL}${path}`).timings.waiting);
  }

  return +median(samples).toFixed(2);
}

export function setup() {
  if (!BASE_URL) {
    throw new Error('BASE_URL is required');
  }

  for (const scenario of BUCKET_ONLY ? [] : Object.values(SCENARIOS)) {
    for (let i = 0; i < scenario.warmup; i++) {
      http.get(`${BASE_URL}${scenario.path}`);
    }
  }

  // The data centre a URL is routed through is stable, so one probe each is
  // enough, and it explains a scenario's TTFB better than any warning could.
  const tiers = {};
  for (const [name, scenario] of Object.entries(SCENARIOS)) {
    tiers[name] = http.get(`${BASE_URL}${scenario.path}`).headers['X-Bench-Tier'] ?? null;
  }

  const info = http.get(`${BASE_URL}/b/info`, { responseType: 'text' });
  const probe = http.get(`${BASE_URL}${SCENARIOS.noop.path}`);

  return {
    info: info.status === 200 ? JSON.parse(info.body) : { error: info.status },
    // Records which edge answered, and whether it added timings of its own.
    edge: {
      ray: probe.headers['Cf-Ray'] ?? null,
      cache_status: probe.headers['Cf-Cache-Status'] ?? null,
      server_timing: probe.headers['Server-Timing'] ?? null,
    },
    tiers,
    reference: { client_to_edge_ms: reference(EDGE_PATH) },
  };
}

export function scenario(data) {
  const name = __ENV.SCENARIO;
  const response = http.get(`${BASE_URL}${SCENARIOS[name].path}`);

  check(response, { 'status is 200': (r) => r.status === 200 });

  const app = serverTiming(response, 'app');
  const boot = serverTiming(response, 'boot');
  const db = serverTiming(response, 'db');
  const ttfb = response.timings.waiting;

  metrics[name].ttfb.add(ttfb);
  metrics[name].receiving.add(response.timings.receiving);
  if (app !== null) metrics[name].app.add(app);
  if (boot !== null) metrics[name].boot.add(boot);
  if (db !== null) metrics[name].db.add(db);

  if (app === null) {
    return;
  }

  const server = app + (boot ?? 0);
  const edge = data.reference.client_to_edge_ms;

  metrics[name].server.add(server);
  metrics[name].edge.add(edge);
  // Everything between the edge holding the request and PHP answering it:
  // the hop to the container, platform routing, queueing, and the response
  // serialisation that happens after the middleware stops counting.
  metrics[name].beyondEdge.add(ttfb - server - edge);
}

export function bucketPair() {
  for (let i = 0; i < BUCKET_URLS.length; i++) {
    // Rotating start, so no URL is always requested first.
    const index = (i + __ITER) % BUCKET_URLS.length;

    bucketMetrics[index].add(http.get(`${BASE_URL}${BUCKET_URLS[index]}`).timings.waiting);
  }
}

export function handleSummary(data) {
  const stats = (metric, name) => {
    const values = data.metrics[`${metric}_${name}`]?.values;

    return values
      ? {
          med: +values.med.toFixed(2),
          p95: +values['p(95)'].toFixed(2),
          p99: +values['p(99)'].toFixed(2),
        }
      : null;
  };

  const rows = (BUCKET_ONLY ? [] : Object.entries(SCENARIOS)).map(([name, scenario]) => ({
    scenario: name,
    path: scenario.path,
    // The data centre this URL reaches the origin through.
    tier: data.setup_data?.tiers?.[name] ?? null,
    rate_per_second: scenario.rate * RATE_SCALE,
    requests: data.metrics[`ttfb_ms_${name}`]?.values?.count ?? 0,
    // These three add up to ttfb_ms, each recorded on the same request.
    edge_ms: stats('edge_ms', name),
    beyond_edge_ms: stats('beyond_edge_ms', name),
    server_ms: stats('server_ms', name),
    ttfb_ms: stats('ttfb_ms', name),
    // Detail within server_ms, and the body transfer that follows the TTFB.
    app_ms: stats('app_ms', name),
    boot_ms: stats('boot_ms', name),
    db_ms: stats('db_ms', name),
    receiving_ms: stats('receiving_ms', name),
  }));

  const result = {
    run_id: RUN_ID,
    base_url: BASE_URL,
    recorded_at: new Date().toISOString(),
    load: { duration_s: DURATION_S, rate_scale: RATE_SCALE },
    legend: {
      edge_ms: `client to the Cloudflare edge and back, measured on ${EDGE_PATH}, which the edge answers without reaching the container`,
      server_ms: 'app + boot, as the application reports them through Server-Timing',
      beyond_edge_ms: 'ttfb_ms - edge_ms - server_ms: everything between the edge and PHP, which nothing in the chain reports',
      ttfb_ms: 'time to first byte measured by the client',
    },
    // A control, not a scenario: URLs that differ only by an argument the
    // application never reads, so any spread belongs to the chain in front
    // of it rather than to the response.
    bucket: BUCKET_URLS.map((path, index) => ({
      path,
      ttfb_ms: stats('ttfb_ms', `bucket${index}`),
    })),
    environment: data.setup_data?.info ?? null,
    edge: data.setup_data?.edge ?? null,
    reference: data.setup_data?.reference ?? null,
    scenarios: rows,
  };

  const table = rows
    .map((row) => {
      const cell = (s) => (s ? String(s.med).padStart(8) : '       -');

      return `  ${row.scenario.padEnd(6)}${cell(row.edge_ms)}${cell(row.beyond_edge_ms)}${cell(row.server_ms)}${cell(row.ttfb_ms)}`;
    })
    .join('\n');

  return {
    stdout: `\n${RUN_ID} — medians in ms\n  ${'scenario'.padEnd(6)}${'edge'.padStart(8)}${'beyond'.padStart(8)}${'server'.padStart(8)}${'ttfb'.padStart(8)}\n${table}\n`,
    [`bench/results/${RUN_ID}.json`]: JSON.stringify(result, null, 2),
  };
}
