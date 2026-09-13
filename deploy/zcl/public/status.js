const es = document.documentElement.lang === 'es';
const labels = es ? {
  unavailable: 'No disponible', syncing: 'Sincronizando', synced: 'Al día',
  stale: 'Observación antigua', waiting: 'Esperando al nodo',
  checking: 'Consultando la disponibilidad actual del pool',
  checkingMessage: 'Consultando el estado actual del nodo y del pool…',
  open: 'El pool acepta mineros',
  closed: 'El pool no está aceptando mineros temporalmente',
  unverified: 'No se puede verificar la disponibilidad actual del pool',
  ready: 'Conecta un minero con tu propia dirección ZCL. La comisión del pool es del 0,8%.',
  wait: 'Las comprobaciones actuales del nodo, la contabilidad o los pagos aún no permiten aceptar trabajo. El estado se actualizará automáticamente.',
  retry: 'Falta una observación actual y válida del nodo o del pool. El estado se actualizará automáticamente.'
} : {
  unavailable: 'Unavailable', syncing: 'Synchronizing', synced: 'Current',
  stale: 'Older observation', waiting: 'Waiting for the node',
  checking: 'Checking current pool availability',
  checkingMessage: 'Checking the current node and pool status…',
  open: 'Pool accepting miners',
  closed: 'Pool temporarily not accepting miners',
  unverified: 'Current pool availability could not be verified',
  ready: 'Connect a miner with your own ZCL address. The pool fee is 0.8%.',
  wait: 'Current node, accounting, or payout checks are preventing admission. Status will refresh automatically.',
  retry: 'A current, valid node or pool observation is unavailable. Status will refresh automatically.'
};
const MAX_AGE = 180000, REQUEST_TIMEOUT = 8000;
let node, pool, refreshing = false, expiryTimer;

function valid(data) { return data?.asset === 'ZCL' && data.schemaVersion === 1; }
function fresh(data) {
  const age = Date.now() - Date.parse(data?.generatedAt);
  return Number.isFinite(age) && age >= -300000 && age <= MAX_AGE;
}
function poolState(state) {
  document.querySelector('#pool-state').textContent = labels[state];
  document.querySelector('#pool-message').textContent = state === 'open' ? labels.ready :
    state === 'closed' ? labels.wait : state === 'checking' ? labels.checkingMessage : labels.retry;
  document.querySelector('#connection').hidden = state !== 'open';
}
function availability() {
  if (!valid(node) || !valid(pool) || !fresh(node) || !fresh(pool) ||
      typeof node.node?.synced !== 'boolean' || typeof pool.acceptingMiners !== 'boolean' ||
      pool.feePercent !== 0.8 || pool.status === 'unavailable') return 'unverified';
  return pool.acceptingMiners && node.node.synced ? 'open' : 'closed';
}
function renderAvailability() {
  poolState(availability());
  if (node && !fresh(node)) document.querySelector('#sync').textContent = labels.stale;
}
function expireAvailability() {
  // Expire independently of requests, even if a later response body never completes.
  node = undefined;
  pool = undefined;
  poolState('unverified');
  document.querySelector('#sync').textContent = labels.stale;
}
function scheduleExpiry() {
  clearTimeout(expiryTimer);
  if (availability() === 'unverified') return;
  const expiresAt = Math.min(Date.parse(node.generatedAt), Date.parse(pool.generatedAt)) + MAX_AGE;
  expiryTimer = setTimeout(expireAvailability, Math.max(1, expiresAt - Date.now() + 1));
}
async function load(url) {
  const controller = new AbortController();
  let timer;
  try {
    return await Promise.race([
      (async () => {
        const response = await fetch(url, {cache: 'no-store', signal: controller.signal});
        if (!response.ok) throw Error('Unavailable');
        return await response.json();
      })(),
      new Promise((_, reject) => {
        timer = setTimeout(() => { reject(Error('Timeout')); controller.abort(); }, REQUEST_TIMEOUT);
      })
    ]);
  } finally {
    clearTimeout(timer);
    controller.abort();
  }
}
async function refresh() {
  if (refreshing) return;
  refreshing = true;
  try {
    const [nodeResult, poolResult] = await Promise.allSettled([load('/api/node.json'), load('/api/pool.json')]);
    node = nodeResult.status === 'fulfilled' && valid(nodeResult.value) ? nodeResult.value : undefined;
    pool = poolResult.status === 'fulfilled' && valid(poolResult.value) ? poolResult.value : undefined;
    if (node) {
      const height = node.chain?.height;
      document.querySelector('#block').textContent = Number.isSafeInteger(height) ? height.toLocaleString(es ? 'es-ES' : 'en-US') : labels.waiting;
      document.querySelector('#sync').textContent = fresh(node) ? (node.node?.synced === true ? labels.synced : labels.syncing) : labels.stale;
      document.querySelector('#updated').textContent = Number.isFinite(Date.parse(node.generatedAt)) ? new Date(node.generatedAt).toLocaleString(es ? 'es-ES' : 'en-US') : labels.unavailable;
    } else {
      document.querySelector('#sync').textContent = labels.unavailable;
    }
    if (pool) window.ZclPoolMined?.update(pool.mined);
    else window.ZclPoolMined?.failure();
    renderAvailability();
    scheduleExpiry();
  } finally {
    refreshing = false;
  }
}
poolState('checking');
refresh();
setInterval(() => { if (!document.hidden) refresh(); }, 30000);
document.addEventListener('visibilitychange', () => {
  if (!document.hidden) { renderAvailability(); refresh(); }
});
