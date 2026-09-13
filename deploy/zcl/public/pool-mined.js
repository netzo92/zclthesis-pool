(() => {
  'use strict';
  const mount = document.getElementById('pool-mined');
  if (!mount) return;
  const es = document.documentElement.lang === 'es';
  const locale = es ? 'es-ES' : 'en-US';
  const labels = es ? {
    checking: 'Consultando los bloques de nuestro pool…',
    live: 'Recompensas verificadas en la cadena actual.',
    partial: 'Historial incompleto: solo se muestran recompensas verificadas. ≥ indica un mínimo conocido.',
    unavailable: 'No se pueden verificar las recompensas del pool. Los guiones no significan cero.',
    stale: 'Datos desactualizados: los intervalos terminan en la última actualización indicada.',
    failed: 'No se pudo actualizar. Se conservan los últimos datos verificados y su fecha.',
    held: 'La contabilidad o los pagos requieren revisión; estos importes no son saldos disponibles.',
    block: 'bloque', blocks: 'bloques', mature: 'Maduras', immature: 'Inmaduras',
    recorded: 'Historial registrado del pool', through: 'Datos hasta',
    unknown: 'Bloques sin verificar', orphans: 'Bloques huérfanos excluidos',
    pending: 'Recompensas pendientes de verificación',
  } : {
    checking: 'Checking blocks mined by our pool…',
    live: 'Rewards verified on the current chain.',
    partial: 'Incomplete history: showing verified rewards only. ≥ marks a known minimum.',
    unavailable: 'Pool rewards cannot be verified. Dashes do not mean zero.',
    stale: 'Stale data: the time windows end at the last update shown.',
    failed: 'Refresh failed. Showing the last verified figures and their timestamp.',
    held: 'Accounting or payouts require review; these figures are not spendable balances.',
    block: 'block', blocks: 'blocks', mature: 'Mature', immature: 'Immature',
    recorded: 'Recorded pool history', through: 'Data through',
    unknown: 'Unverified blocks', orphans: 'Excluded orphaned blocks',
    pending: 'Rewards awaiting verification',
  };
  const keys = ['allTime', 'last24h', 'lastHour'];
  const $ = name => document.getElementById(`pool-mined-${name}`);
  const integer = new Intl.NumberFormat(locale, {maximumFractionDigits: 0});
  const date = new Intl.DateTimeFormat(locale, {year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23', timeZone: 'UTC'});
  const maxAge = 180000;
  const count = value => Number.isSafeInteger(value) && value >= 0;
  const timestamp = value => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/.test(value) && Number.isFinite(Date.parse(value));
  const zat = value => typeof value === 'string' && /^(?:0|[1-9]\d{0,23})$/.test(value);
  function validWindow(value) {
    if (!value || !['rewardZat', 'matureRewardZat', 'immatureRewardZat'].every(key => zat(value[key])) ||
        !['blocks', 'matureBlocks', 'immatureBlocks'].every(key => count(value[key]))) return false;
    return BigInt(value.rewardZat) === BigInt(value.matureRewardZat) + BigInt(value.immatureRewardZat) &&
      value.blocks === value.matureBlocks + value.immatureBlocks &&
      (value.blocks !== 0 || value.rewardZat === '0') &&
      (value.matureBlocks !== 0 || value.matureRewardZat === '0') &&
      (value.immatureBlocks !== 0 || value.immatureRewardZat === '0');
  }
  function valid(data) {
    if (!data || data.schemaVersion !== 1 || data.asset !== 'ZCL' ||
        !['ok', 'partial', 'unavailable'].includes(data.status) || !timestamp(data.generatedAt) ||
        Date.parse(data.generatedAt) > Date.now() + maxAge ||
        data.coverageBasis !== 'retained-pool-ledger' || data.windowBasis !== 'pool-recorded-time' ||
        data.rewardBasis !== 'gross-coinbase-including-fees' ||
        !(data.coverageStartedAt === null || timestamp(data.coverageStartedAt))) return false;
    if (data.status === 'unavailable') return keys.every(key => data[key] === null);
    if (!keys.every(key => validWindow(data[key])) || !count(data.unknownBlocks) ||
        !count(data.excludedOrphans) || typeof data.accountingHeld !== 'boolean' ||
        (data.status === 'ok' && data.unknownBlocks !== 0)) return false;
    for (const key of ['rewardZat', 'matureRewardZat', 'immatureRewardZat', 'blocks', 'matureBlocks', 'immatureBlocks']) {
      if (BigInt(data.allTime[key]) < BigInt(data.last24h[key]) || BigInt(data.last24h[key]) < BigInt(data.lastHour[key])) return false;
    }
    return true;
  }
  // Keep every zatoshi; converting large integer totals to Number would lose precision.
  function amount(value, partial = false) {
    if (partial && value === '0') return '—';
    const digits = value.padStart(9, '0');
    const whole = BigInt(digits.slice(0, -8)).toLocaleString(locale);
    const fraction = digits.slice(-8).replace(/0+$/, '');
    return `${partial ? '≥ ' : ''}${whole}${fraction ? `${es ? ',' : '.'}${fraction}` : ''}`;
  }
  function blockCount(value, partial) {
    return `${partial ? '≥ ' : ''}${integer.format(value)} ${value === 1 ? labels.block : labels.blocks}`;
  }
  let snapshot = null, failed = false, busy = false;
  function paint() {
    if (!snapshot || snapshot.status === 'unavailable') {
      mount.dataset.status = 'unavailable';
      for (const key of keys) {
        $(key).textContent = '—';
        $(`${key}-blocks`).textContent = labels.pending;
        $(`${key}-maturity`).textContent = '';
      }
      $('status').textContent = labels.unavailable;
      $('updated').textContent = '';
      return;
    }
    const partial = snapshot.status === 'partial';
    const stale = Date.now() - Date.parse(snapshot.generatedAt) > maxAge || snapshot.stale === true;
    mount.dataset.status = failed || stale ? 'stale' : partial ? 'partial' : 'ok';
    for (const key of keys) {
      const window = snapshot[key];
      $(key).textContent = amount(window.rewardZat, partial);
      $(`${key}-blocks`).textContent = partial && window.blocks === 0 ? labels.pending : blockCount(window.blocks, partial);
      $(`${key}-maturity`).textContent = `${labels.mature}: ${amount(window.matureRewardZat, partial)} ZCL · ${labels.immature}: ${amount(window.immatureRewardZat, partial)} ZCL`;
    }
    const messages = [partial ? labels.partial : labels.live];
    if (snapshot.unknownBlocks) messages.push(`${labels.unknown}: ${integer.format(snapshot.unknownBlocks)}.`);
    if (snapshot.excludedOrphans) messages.push(`${labels.orphans}: ${integer.format(snapshot.excludedOrphans)}.`);
    if (stale) messages.push(labels.stale);
    if (failed) messages.push(labels.failed);
    if (snapshot.accountingHeld) messages.push(labels.held);
    $('status').textContent = messages.join(' ');
    $('updated').textContent = `${labels.through}: ${date.format(new Date(snapshot.generatedAt))} UTC.`;
    $('history').textContent = snapshot.coverageStartedAt
      ? `${labels.recorded} · ${date.format(new Date(snapshot.coverageStartedAt))} UTC`
      : labels.recorded;
  }
  function update(data) {
    if (!valid(data)) { failure(); return; }
    snapshot = data;
    failed = false;
    paint();
  }
  function failure() { failed = true; paint(); }
  // The pool dashboard reuses its existing status request; the thesis uses a same-origin proxy.
  window.ZclPoolMined = Object.freeze({update, failure});
  async function refresh() {
    if (busy) return;
    busy = true;
    try {
      const response = await fetch(mount.dataset.endpoint, {cache: 'no-store', signal: AbortSignal.timeout(12000)});
      if (!response.ok) throw Error('Pool data unavailable');
      update(await response.json());
    } catch { failure(); }
    finally { busy = false; }
  }
  if (mount.dataset.endpoint) refresh();
  setInterval(() => {
    if (document.hidden) return;
    if (mount.dataset.endpoint) refresh();
    else if (snapshot) paint();
  }, 30000);
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) return;
    if (mount.dataset.endpoint) refresh();
    else if (snapshot) paint();
  });
})();
