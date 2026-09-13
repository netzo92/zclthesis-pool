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

// Public treasury reporting is separate from the visitor's mining destination.
(() => {
  'use strict';
  const root=document.getElementById('pool-treasury');if(!root)return;
  const es=document.documentElement.lang==='es',locale=es?'es-ES':'en-US';
  const recipient='t1Q8PRCDso9HoK36XeLCPym6vZmkwyNgS4d';
  const $=name=>document.getElementById('treasury-'+name);
  const text=es?{
    current:'Transferencias confirmadas según el registro del pool.',
    partial:'Registro parcial: ≥ indica un mínimo verificado. Los guiones no significan cero.',
    unavailable:'No se pueden verificar los ingresos de la tesorería. Los guiones no significan cero.',
    stale:'Observación desactualizada; se muestra la última verificación con su fecha.',
    failed:'No se pudo actualizar. Se conservan las últimas cifras verificadas con su fecha.',
    held:'La contabilidad requiere revisión.',canonical:'La comprobación de bloques está incompleta o no disponible.',
    checked:'Registro verificado',rounds:'Rondas sin verificar',payments:'Pagos sin verificar',
    mature:'Maduras',immature:'Inmaduras',operator:'Transferencias al operador',shared:'Pagos de minería a la dirección compartida',
    empty:'Todavía no hay ingresos confirmados registrados.',unknown:'No hay ingresos positivos verificados para representar.',
  }:{
    current:'Transfers confirmed in the pool journal.',
    partial:'Partial journal: ≥ marks a verified minimum. Dashes do not mean zero.',
    unavailable:'Treasury receipts cannot be verified. Dashes do not mean zero.',
    stale:'Stale observation; showing the last verification and its timestamp.',
    failed:'Refresh failed. Showing the last verified figures and their timestamp.',
    held:'Accounting requires review.',canonical:'The block check is incomplete or unavailable.',
    checked:'Journal checked',rounds:'Unverified rounds',payments:'Unverified payments',
    mature:'Mature',immature:'Immature',operator:'Operator transfers',shared:'Mining payouts to the shared address',
    empty:'No confirmed receipts recorded yet.',unknown:'No verified positive receipts to visualize.',
  };
  const time=value=>typeof value==='string'&&/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/.test(value)&&Number.isFinite(Date.parse(value));
  const amount=value=>typeof value==='string'&&/^(?:0|[1-9]\d{0,15})$/.test(value)&&BigInt(value)<=2100000000000000n;
  const count=value=>Number.isSafeInteger(value)&&value>=0&&value<=1000000000;
  const date=new Intl.DateTimeFormat(locale,{year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23',timeZone:'UTC'});
  function exact(value,partial=false){
    if(partial&&value==='0')return '—';
    const digits=value.padStart(9,'0'),whole=BigInt(digits.slice(0,-8)).toLocaleString(locale),fraction=digits.slice(-8).replace(/0+$/,'');
    return (partial?'≥ ':'')+whole+(fraction?(es?',':'.')+fraction:'');
  }
  function valid(data){
    if(!data||data.schemaVersion!==1||data.asset!=='ZCL'||data.unit!=='zatoshi'||data.recipient!==recipient||!time(data.generatedAt)||
       !['ok','partial','unavailable'].includes(data.status)||data.coverageBasis!=='retained-pool-ledger'||
       data.allocationBasis!=='retained-ledger-block-status'||data.receivedBasis!=='confirmed-payout-journal'||data.coverageStartedAt!==null||
       !['ok','partial','stale','unavailable'].includes(data.canonicalStatus))return false;
    if(data.status==='unavailable')return data.received===null&&data.allocated===null;
    if(typeof data.accountingHeld!=='boolean'||!['unknownRounds','unknownPayments','excludedOrphanRounds'].every(key=>count(data[key])))return false;
    if(data.status==='ok'&&(data.canonicalStatus!=='ok'||data.accountingHeld||data.unknownRounds||data.unknownPayments))return false;
    if(!data.received||!['totalZat','operatorTransfersZat','sharedMiningZat'].every(key=>amount(data.received[key]))||
       BigInt(data.received.totalZat)!==BigInt(data.received.operatorTransfersZat)+BigInt(data.received.sharedMiningZat))return false;
    return ['operatorFees','otherRetained','sharedMining'].every(key=>{
      const allocation=data.allocated?.[key];
      return allocation&&['totalZat','matureZat','immatureZat'].every(name=>amount(allocation[name]))&&
        BigInt(allocation.totalZat)===BigInt(allocation.matureZat)+BigInt(allocation.immatureZat);
    });
  }
  let snapshot=null,failed=false,clockOffset=0,busy=false;
  const now=()=>Date.now()+clockOffset;
  const stale=data=>now()-Date.parse(data.generatedAt)>180000||now()-Date.parse(data.generatedAt)<-60000||data.canonicalStatus==='stale';
  function paint(){
    const unavailable=!snapshot||snapshot.status==='unavailable';
    if(unavailable){
      root.dataset.status='unavailable';for(const key of ['total','operator','shared','fees'])$(key).textContent='—';
      $('fee-detail').textContent='';$('updated').textContent='';$('status').textContent=text.unavailable;
      $('composition').setAttribute('hidden','');$('composition-note').textContent=text.unknown;return;
    }
    const partial=snapshot.status==='partial',old=stale(snapshot),received=snapshot.received,fees=snapshot.allocated.operatorFees;
    root.dataset.status=failed||old?'stale':partial?'partial':'ok';
    for(const [id,value]of [['total',received.totalZat],['operator',received.operatorTransfersZat],['shared',received.sharedMiningZat],['fees',fees.totalZat]])$(id).textContent=exact(value,partial);
    $('fee-detail').textContent=text.mature+': '+exact(fees.matureZat,partial)+' ZCL · '+text.immature+': '+exact(fees.immatureZat,partial)+' ZCL';
    const messages=[failed?text.failed:old?text.stale:partial?text.partial:text.current];
    if(partial&&(failed||old))messages.push(text.partial);
    if(snapshot.accountingHeld)messages.push(text.held);
    if(snapshot.canonicalStatus!=='ok')messages.push(text.canonical);
    if(snapshot.unknownRounds)messages.push(text.rounds+': '+snapshot.unknownRounds.toLocaleString(locale)+'.');
    if(snapshot.unknownPayments)messages.push(text.payments+': '+snapshot.unknownPayments.toLocaleString(locale)+'.');
    $('status').textContent=messages.join(' ');
    $('updated').textContent=text.checked+': '+date.format(new Date(snapshot.generatedAt))+' UTC.';
    const total=BigInt(received.totalZat),width=total>0n?Number(BigInt(received.operatorTransfersZat)*3600000n/total)/10000:0;
    $('composition').removeAttribute('hidden');$('operator-bar').setAttribute('width',String(width));
    $('shared-bar').setAttribute('x',String(width));$('shared-bar').setAttribute('width',total>0n?String(360-width):'0');
    $('composition-note').textContent=total>0n?text.operator+': '+exact(received.operatorTransfersZat,partial)+' ZCL · '+text.shared+': '+exact(received.sharedMiningZat,partial)+' ZCL':partial?text.unknown:text.empty;
  }
  function unavailable(){snapshot=null;failed=false;paint();}
  function update(data,reference=Date.now()){
    if(!valid(data)||!Number.isFinite(reference)||Date.parse(data.generatedAt)>reference+60000){unavailable();return;}
    clockOffset=reference-Date.now();
    const increased=snapshot?.status==='ok'&&data.status==='ok'&&!stale(snapshot)&&!stale(data)&&BigInt(data.received.totalZat)>BigInt(snapshot.received.totalZat);
    snapshot=data;failed=false;paint();
    if(increased){root.dataset.receipt='new';setTimeout(()=>{root.dataset.receipt='';},700);}
  }
  function failure(){failed=true;paint();}
  window.ZclTreasury=Object.freeze({update,failure,unavailable});
  async function refresh(){
    if(busy||document.hidden)return;busy=true;
    try{
      const response=await fetch(root.dataset.endpoint,{cache:'no-store',credentials:'omit',signal:AbortSignal.timeout(10000)});
      if(!response.ok)throw Error('Treasury source unavailable');
      const data=await response.json();if(data?.schemaVersion!==1||data.asset!=='ZCL')throw Error('Invalid source');
      const server=Date.parse(response.headers.get('date')),ageHeader=response.headers.get('age'),age=ageHeader===null?0:Number(ageHeader)*1000;
      if(!Number.isFinite(age)||age<0||age>180000)throw Error('Stale source');
      if(data.status==='unavailable')unavailable();else update(data.pool?.treasury,Number.isFinite(server)?server+age:Date.now());
    }catch{failure();}finally{busy=false;}
  }
  if(root.dataset.endpoint)refresh();
  setInterval(()=>{if(document.hidden)return;paint();if(root.dataset.endpoint)refresh();},30000);
  document.addEventListener('visibilitychange',()=>{if(document.hidden)return;paint();if(root.dataset.endpoint)refresh();});
})();
