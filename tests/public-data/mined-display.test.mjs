import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
const source=readFileSync(new URL('../../deploy/zcl/public/pool-mined.js',import.meta.url),'utf8');
function harness(lang){
 const fields=new Map();
 const get=id=>{if(!fields.has(id))fields.set(id,{textContent:'',dataset:{},setAttribute(){},removeAttribute(){}});return fields.get(id);};
 const window={};
 vm.runInNewContext(source,{window,document:{getElementById:get,documentElement:{lang},addEventListener(){}},setInterval(){},setTimeout(){}});
 return {get,window};
}
const zero=()=>({blocks:0,matureBlocks:0,immatureBlocks:0,rewardZat:'0',matureRewardZat:'0',immatureRewardZat:'0'});
const mined=()=>({schemaVersion:1,asset:'ZCL',status:'partial',generatedAt:new Date().toISOString(),coverageStartedAt:null,coverageBasis:'retained-pool-ledger',windowBasis:'pool-recorded-time',rewardBasis:'gross-coinbase-including-fees',unknownBlocks:12,excludedOrphans:0,accountingHeld:false,allTime:{...zero(),blocks:19,matureBlocks:19,rewardZat:'742227745',matureRewardZat:'742227745'},last24h:zero(),lastHour:zero()});
for(const lang of ['en','es']){
 test(`${lang}: partial figures stay numeric and explicitly bounded`,()=>{
  const h=harness(lang);h.window.ZclPoolMined.update(mined());
  assert.equal(h.get('pool-mined-allTime').textContent,lang==='en'?'≥ 7.42227745':'≥ 7,42227745');
  assert.equal(h.get('pool-mined-lastHour').textContent,'≥ 0');
  assert.match(h.get('pool-mined-last24h-blocks').textContent,/≥ 0/);
  assert.match(h.get('pool-mined-status').textContent,/12/);
  assert.match(h.get('pool-mined-status').textContent,lang==='en'?/pending rewards are excluded/:/pendientes están excluidas/);
  h.window.ZclPoolMined.failure();
  assert.equal(h.get('pool-mined-lastHour').textContent,'≥ 0');
  assert.equal(h.get('pool-mined').dataset.status,'stale');
 });
 test(`${lang}: verified zero differs from unavailable`,()=>{
  const h=harness(lang),data=mined();data.status='ok';data.unknownBlocks=0;
  h.window.ZclPoolMined.update(data);assert.equal(h.get('pool-mined-lastHour').textContent,'0');
  data.status='unavailable';for(const key of ['allTime','last24h','lastHour'])data[key]=null;
  h.window.ZclPoolMined.update(data);assert.equal(h.get('pool-mined-lastHour').textContent,'—');
 });
 test(`${lang}: treasury partial zero is a lower bound, unavailable is a dash`,()=>{
  const h=harness(lang),allocation={totalZat:'0',matureZat:'0',immatureZat:'0'};
  const data={schemaVersion:1,asset:'ZCL',unit:'zatoshi',recipient:'t1Q8PRCDso9HoK36XeLCPym6vZmkwyNgS4d',generatedAt:new Date().toISOString(),status:'partial',coverageBasis:'retained-pool-ledger',allocationBasis:'retained-ledger-block-status',receivedBasis:'confirmed-payout-journal',coverageStartedAt:null,canonicalStatus:'partial',accountingHeld:false,unknownRounds:12,unknownPayments:0,excludedOrphanRounds:0,received:{totalZat:'0',operatorTransfersZat:'0',sharedMiningZat:'0'},allocated:{operatorFees:allocation,otherRetained:allocation,sharedMining:allocation}};
  h.window.ZclTreasury.update(data);assert.equal(h.get('treasury-total').textContent,'≥ 0');
  assert.match(h.get('treasury-status').textContent,/12/);
  h.window.ZclTreasury.unavailable();assert.equal(h.get('treasury-total').textContent,'—');
 });
}
