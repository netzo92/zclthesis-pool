const es=document.documentElement.lang==='es';
const labels=es?{unavailable:'No disponible',syncing:'Sincronizando',synced:'Al día',stale:'Observación antigua',waiting:'Esperando al nodo',open:'La pool acepta mineros',closed:'La minería todavía no está disponible',ready:'Conecta un minero con tu propia dirección ZCL. La comisión de la pool es del 0,8%.',wait:'Esperamos la confirmación actual del nodo, la contabilidad y los pagos antes de aceptar trabajo.'}:{unavailable:'Unavailable',syncing:'Synchronizing',synced:'Current',stale:'Older observation',waiting:'Waiting for the node',open:'Pool accepting miners',closed:'Mining is not available yet',ready:'Connect a miner with your own ZCL address. The pool fee is 0.8%.',wait:'We are waiting for current node, accounting, and payout readiness before accepting work.'};
function fresh(data){const age=Date.now()-Date.parse(data?.generatedAt);return Number.isFinite(age)&&age>=-300000&&age<=180000;}
function poolState(open){document.querySelector('#pool-state').textContent=open?labels.open:labels.closed;document.querySelector('#pool-message').textContent=open?labels.ready:labels.wait;document.querySelector('#connection').hidden=!open;}
async function refresh(){
  let node;
  try{
    const response=await fetch('/api/node.json',{cache:'no-store'});if(!response.ok)throw Error('Unavailable');
    node=await response.json();if(node.asset!=='ZCL'||node.schemaVersion!==1)throw Error('Invalid response');
    const height=node.chain?.height;document.querySelector('#block').textContent=Number.isSafeInteger(height)?height.toLocaleString(es?'es-ES':'en-US'):labels.waiting;
    document.querySelector('#sync').textContent=fresh(node)?(node.node?.synced===true?labels.synced:labels.syncing):labels.stale;
    document.querySelector('#updated').textContent=Number.isFinite(Date.parse(node.generatedAt))?new Date(node.generatedAt).toLocaleString(es?'es-ES':'en-US'):labels.unavailable;
  }catch{node=undefined;document.querySelector('#sync').textContent=labels.unavailable;}
  try{
    const response=await fetch('/api/pool.json',{cache:'no-store'});if(!response.ok)throw Error('Unavailable');
    const pool=await response.json();
    if(pool.asset==='ZCL'&&pool.schemaVersion===1)window.ZclPoolMined?.update(pool.mined);else window.ZclPoolMined?.failure();
    poolState(pool.asset==='ZCL'&&pool.schemaVersion===1&&fresh(pool)&&pool.acceptingMiners===true&&pool.feePercent===0.8&&fresh(node)&&node.node?.synced===true);
  }catch{poolState(false);window.ZclPoolMined?.failure();}
}
refresh();setInterval(()=>{if(!document.hidden)refresh();},30000);
document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
