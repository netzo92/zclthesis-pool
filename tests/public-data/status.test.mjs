import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../deploy/zcl/public/status.js', import.meta.url), 'utf8');
const START = Date.parse('2026-09-13T12:00:00Z');
const flush = () => new Promise(resolve => setImmediate(resolve));

function harness({lang = 'en', edit = () => {}} = {}) {
  let now = START, serial = 0, mode = 'ok';
  const timers = new Map(), listeners = new Map(), fields = new Map(), requests = [], late = [];
  const get = selector => {
    if (!fields.has(selector)) fields.set(selector, {textContent: '', hidden: true});
    return fields.get(selector);
  };
  const node = {schemaVersion: 1, asset: 'ZCL', generatedAt: new Date(START).toISOString(),
    chain: {height: 3248000}, node: {synced: true}};
  const pool = {schemaVersion: 1, asset: 'ZCL', generatedAt: new Date(START).toISOString(),
    acceptingMiners: true, feePercent: 0.8, status: 'open', mined: {fixture: 'unchanged'}};
  edit({node, pool});
  const mined = {updates: [], failures: 0, update(value) { this.updates.push(value); }, failure() { this.failures++; }};
  const document = {documentElement: {lang}, hidden: false, querySelector: get,
    addEventListener: (event, callback) => listeners.set(event, callback)};
  function schedule(callback, delay, interval = 0) {
    const id = ++serial;
    timers.set(id, {callback, at: now + delay, interval});
    return id;
  }
  const context = vm.createContext({
    document, window: {ZclPoolMined: mined}, AbortController,
    Date: class extends Date { constructor(...args) { super(...(args.length ? args : [now])); } static now() { return now; } },
    setTimeout: (callback, delay) => schedule(callback, delay),
    clearTimeout: id => timers.delete(id),
    setInterval: (callback, delay) => schedule(callback, delay, delay),
    fetch: async (url, options) => {
      requests.push({url, options});
      if (mode === 'hang') return new Promise(() => {});
      if (mode === 'late') return new Promise(resolve => late.push(() => resolve({ok: true, json: async () => structuredClone(url.includes('node') ? node : pool)})));
      if (mode === 'fail-node' && url.includes('node')) throw Error('Unavailable');
      if (mode === 'fail-pool' && url.includes('pool')) return {ok: false};
      const value = structuredClone(url.includes('node') ? node : pool);
      return {ok: true, json: async () => mode === 'body-hang' ? new Promise(() => {}) : value};
    }
  });
  vm.runInContext(source, context);
  return {
    get, node, pool, mined, requests, document, late,
    mode(value) { mode = value; },
    refresh() { return vm.runInContext('refresh()', context); },
    visible() { document.hidden = false; listeners.get('visibilitychange')(); },
    jump(milliseconds) { now += milliseconds; },
    async advance(milliseconds) {
      const target = now + milliseconds;
      for (;;) {
        const due = [...timers.entries()].filter(([, timer]) => timer.at <= target)
          .sort((a, b) => a[1].at - b[1].at)[0];
        if (!due) break;
        const [id, timer] = due;
        now = timer.at;
        if (timer.interval) timer.at += timer.interval;
        else timers.delete(id);
        timer.callback();
        await flush();
      }
      now = target;
      await flush();
    }
  };
}

test('both languages start by checking and show open only after fresh validated observations', async () => {
  for (const lang of ['en', 'es']) {
    const h = harness({lang});
    assert.match(h.get('#pool-state').textContent, lang === 'en' ? /Checking current/ : /Consultando/);
    assert.equal(h.get('#connection').hidden, true);
    await flush();
    assert.equal(h.get('#connection').hidden, false);
    assert.match(h.get('#pool-state').textContent, lang === 'en' ? /Pool accepting miners/ : /El pool acepta mineros/);
    assert.equal(h.requests.length, 2);
    assert.ok(h.requests.every(request => request.options.cache === 'no-store'));
    assert.deepEqual(h.mined.updates, [h.pool.mined]);
  }
});

test('current admission holds use temporary wording; malformed and failed data are unverifiable', async () => {
  for (const lang of ['en', 'es']) {
    for (const edit of [({pool}) => { pool.acceptingMiners = false; pool.status = 'validation'; },
      ({node}) => { node.node.synced = false; }]) {
      const h = harness({lang, edit});
      await flush();
      assert.equal(h.get('#connection').hidden, true);
      assert.match(h.get('#pool-state').textContent, lang === 'en' ? /temporarily not accepting/ : /temporalmente/);
      assert.doesNotMatch(h.get('#pool-state').textContent, /not available yet|todavía no está/);
    }
  }
  for (const edit of [({pool}) => { pool.acceptingMiners = 'true'; },
    ({pool}) => { pool.feePercent = 1; }, ({pool}) => { pool.schemaVersion = 2; },
    ({node}) => { node.asset = 'ZEC'; }, ({node}) => { node.node.synced = 'true'; },
    ({pool}) => { pool.status = 'unavailable'; pool.acceptingMiners = false; }]) {
    const h = harness({edit});
    await flush();
    assert.equal(h.get('#connection').hidden, true);
    assert.match(h.get('#pool-state').textContent, /could not be verified/);
  }
});

test('stale, missing, and excessively future timestamps never authorize connections', async () => {
  for (const field of ['node', 'pool']) {
    for (const timestamp of [undefined, 'invalid', new Date(START - 180001).toISOString(), new Date(START + 300001).toISOString()]) {
      const h = harness({edit: data => { data[field].generatedAt = timestamp; }});
      await flush();
      assert.equal(h.get('#connection').hidden, true);
      assert.match(h.get('#pool-state').textContent, /could not be verified/);
    }
  }
});

test('request and body timeouts close availability after eight seconds and permit recovery', async () => {
  for (const mode of ['hang', 'body-hang']) {
    const h = harness();
    await flush();
    h.mode(mode);
    const pending = h.refresh();
    await flush();
    await h.refresh();
    assert.equal(h.requests.length, 4, 'overlapping refresh must not add requests');
    await h.advance(7999);
    assert.equal(h.get('#connection').hidden, false);
    await h.advance(1);
    await pending;
    assert.equal(h.get('#connection').hidden, true);
    assert.match(h.get('#pool-state').textContent, /could not be verified/);
    assert.ok(h.requests.slice(2).every(request => request.options.signal.aborted));
    h.mode('ok');
    await h.refresh();
    assert.equal(h.get('#connection').hidden, false);
  }
});

test('old admission expires independently while hidden and while a refresh is stalled', async () => {
  for (const stalled of [false, true]) {
    const h = harness({edit: ({node}) => { node.generatedAt = new Date(START - 179000).toISOString(); }});
    await flush();
    h.document.hidden = true;
    if (stalled) { h.mode('hang'); h.refresh(); await flush(); }
    await h.advance(1000);
    assert.equal(h.get('#connection').hidden, false);
    await h.advance(1);
    assert.equal(h.get('#connection').hidden, true);
    assert.match(h.get('#pool-state').textContent, /could not be verified/);
  }
});

test('returning to a suspended page immediately rechecks freshness before a response arrives', async () => {
  const h = harness();
  await flush();
  h.document.hidden = true;
  h.mode('hang');
  h.jump(180001);
  h.visible();
  assert.equal(h.get('#connection').hidden, true);
  assert.match(h.get('#pool-state').textContent, /could not be verified/);
});

test('late timed-out responses cannot reopen availability after a newer closed observation', async () => {
  const h = harness();
  await flush();
  h.mode('late');
  const pending = h.refresh();
  await flush();
  await h.advance(8000);
  await pending;
  h.mode('ok');
  h.pool.acceptingMiners = false;
  h.pool.status = 'validation';
  await h.refresh();
  h.pool.acceptingMiners = true;
  h.pool.status = 'open';
  h.late.forEach(resolve => resolve());
  await flush();
  assert.equal(h.get('#connection').hidden, true);
  assert.match(h.get('#pool-state').textContent, /temporarily not accepting/);
});

test('node failure closes admission while the independent mined snapshot still reaches its consumer', async () => {
  const h = harness();
  await flush();
  h.mode('fail-node');
  await h.refresh();
  assert.equal(h.get('#connection').hidden, true);
  assert.equal(h.mined.updates.length, 2);
  assert.equal(h.mined.failures, 0);
  h.mode('fail-pool');
  await h.refresh();
  assert.equal(h.mined.failures, 1);
  assert.equal(h.get('#connection').hidden, true);
});
