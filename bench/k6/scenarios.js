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

// Rates sit well under what the environment sustains, so the figures are
// latency rather than queueing. Warm-up is sized per scenario: 50 requests of
// db50 alone would cost 20 seconds.
const SCENARIOS = {
  noop: { path: '/b/noop', rate: 5, warmup: 20 },
  cpu: { path: '/b/cpu', rate: 5, warmup: 20 },
  db1: { path: '/b/db/1', rate: 5, warmup: 20 },
  db50: { path: '/b/db/50', rate: 2, warmup: 5 },
};

// One metric set per scenario: k6 aggregates a shared metric across all tags.
const metrics = {};
for (const name of Object.keys(SCENARIOS)) {
  metrics[name] = {
    app: new Trend(`app_ms_${name}`),
    boot: new Trend(`boot_ms_${name}`),
    db: new Trend(`db_ms_${name}`),
    ttfb: new Trend(`ttfb_ms_${name}`),
  };
}

export const options = {
  discardResponseBodies: true,
  summaryTrendStats: ['avg', 'min', 'med', 'p(95)', 'p(99)', 'max', 'count'],
  scenarios: Object.fromEntries(
    Object.entries(SCENARIOS).map(([name, scenario], index) => [
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

function serverTiming(response, name) {
  const header = response.headers['Server-Timing'];
  const match = header && header.match(new RegExp(`${name};dur=([\\d.]+)`));

  return match ? parseFloat(match[1]) : null;
}

/**
 * Warms every endpoint before the measured run, and captures the environment
 * metadata the results have to be filed under.
 */
export function setup() {
  if (!BASE_URL) {
    throw new Error('BASE_URL is required');
  }

  for (const scenario of Object.values(SCENARIOS)) {
    for (let i = 0; i < scenario.warmup; i++) {
      http.get(`${BASE_URL}${scenario.path}`);
    }
  }

  const info = http.get(`${BASE_URL}/b/info`, { responseType: 'text' });

  return { info: info.status === 200 ? JSON.parse(info.body) : { error: info.status } };
}

export function scenario() {
  const name = __ENV.SCENARIO;
  const response = http.get(`${BASE_URL}${SCENARIOS[name].path}`);

  check(response, { 'status is 200': (r) => r.status === 200 });

  const app = serverTiming(response, 'app');
  const boot = serverTiming(response, 'boot');
  const db = serverTiming(response, 'db');

  metrics[name].ttfb.add(response.timings.waiting);
  if (app !== null) metrics[name].app.add(app);
  if (boot !== null) metrics[name].boot.add(boot);
  if (db !== null) metrics[name].db.add(db);
}

export function handleSummary(data) {
  const rows = Object.entries(SCENARIOS).map(([name, scenario]) => {
    const stats = (metric) => {
      const values = data.metrics[`${metric}_${name}`]?.values;

      return values ? { med: values.med, p95: values['p(95)'], p99: values['p(99)'] } : null;
    };

    return {
      scenario: name,
      path: scenario.path,
      rate_per_second: scenario.rate * RATE_SCALE,
      requests: data.metrics[`ttfb_ms_${name}`]?.values?.count ?? 0,
      ttfb_ms: stats('ttfb_ms'),
      app_ms: stats('app_ms'),
      boot_ms: stats('boot_ms'),
      db_ms: stats('db_ms'),
    };
  });

  const result = {
    run_id: RUN_ID,
    base_url: BASE_URL,
    recorded_at: new Date().toISOString(),
    load: { duration_s: DURATION_S, rate_scale: RATE_SCALE },
    environment: data.setup_data?.info ?? null,
    scenarios: rows,
  };

  const table = rows
    .map((row) => {
      const cell = (stats) => (stats ? `${stats.med.toFixed(1)} / ${stats.p95.toFixed(1)}` : '-');

      return `  ${row.scenario.padEnd(6)} ttfb ${cell(row.ttfb_ms).padEnd(18)} app ${cell(row.app_ms)}`;
    })
    .join('\n');

  return {
    stdout: `\n${RUN_ID} — median / p95 in ms\n${table}\n`,
    [`bench/results/${RUN_ID}.json`]: JSON.stringify(result, null, 2),
  };
}
