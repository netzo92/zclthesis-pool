<?php
require __DIR__.'/../../yiimp2/services/ZclNodeReadiness.php';
use app\services\ZclNodeReadiness;
function check(bool $condition,string $label): void {if (!$condition) throw new RuntimeException($label);}
$now=1800000000;
$chain=['chain'=>'main','blocks'=>3200000,'headers'=>3200000,'bestblockhash'=>str_repeat('a',64),'verificationprogress'=>1,
    'bootstrap_validation'=>['tip_hold'=>false],'finalization_hold'=>['held'=>false]];
$header=['hash'=>str_repeat('a',64),'height'=>3200000,'time'=>$now-75,'confirmations'=>1];
check(ZclNodeReadiness::ready($chain,$header,3,$now),'current corroborated node is ready');
foreach ([['chain','regtest'],['headers',3199999],['blocks','3200000'],['verificationprogress',0.99],['initialblockdownload',true],['bootstrap_validation',[]],['bootstrap_validation',['tip_hold'=>true]],['finalization_hold',['held'=>true]]] as [$field,$value]) {
    $bad=$chain;$bad[$field]=$value;check(!ZclNodeReadiness::ready($bad,$header,3,$now),'reject '.$field);
}
foreach ([['hash',str_repeat('b',64)],['height',3199999],['time',$now-1801],['time',$now+601],['confirmations',-1]] as [$field,$value]) {
    $bad=$header;$bad[$field]=$value;check(!ZclNodeReadiness::ready($chain,$bad,3,$now),'reject header '.$field);
}
check(!ZclNodeReadiness::ready($chain,$header,0,$now),'reject disconnected node');

$live=['schemaVersion'=>1,'ready'=>true,'tipHash'=>$chain['bestblockhash'],'tipHeight'=>$chain['blocks'],
    'candidateHeight'=>$chain['blocks']-10,'requiredDepth'=>10,'requiredPeers'=>2,
    'reason'=>'2 independent outbound peers corroborate the chain at depth >=10'];
foreach ([['held'=>false],['held'=>true],[],null] as $cached) {
    $current=$chain;$current['live_corroboration']=$live;$current['finalization_hold']=$cached;
    check(ZclNodeReadiness::ready($current,$header,3,$now),'valid live corroboration supersedes cached hold');
}
$current=$chain;$current['live_corroboration']=$live;unset($current['finalization_hold']);
check(ZclNodeReadiness::ready($current,$header,3,$now),'valid live diagnostic does not require legacy field');
$current['live_corroboration']['requiredPeers']=3;
$current['live_corroboration']['requiredDepth']=20;
$current['live_corroboration']['candidateHeight']=$chain['blocks']-20;
check(ZclNodeReadiness::ready($current,$header,3,$now),'accept consistent stricter live configuration');
foreach ([null,false,true,1,'ready',[]] as $malformed) {
    $bad=$chain;$bad['live_corroboration']=$malformed;
    check(!ZclNodeReadiness::ready($bad,$header,3,$now),'present malformed live diagnostic cannot fall back');
}
foreach ($live as $field=>$value) {
    $bad=$chain;$bad['live_corroboration']=$live;unset($bad['live_corroboration'][$field]);
    check(!ZclNodeReadiness::ready($bad,$header,3,$now),'reject missing live '.$field);
}
$invalid=[
    'schemaVersion'=>[true,1.0,'1',2,null], 'ready'=>[false,1,'true',null],
    'tipHash'=>[str_repeat('b',64),str_repeat('A',64),str_repeat('a',63),null],
    'tipHeight'=>[$chain['blocks']-1,(string)$chain['blocks'],(float)$chain['blocks'],true,-1],
    'candidateHeight'=>[$chain['blocks']-9,(string)($chain['blocks']-10),(float)($chain['blocks']-10),true,-1],
    'requiredDepth'=>[0,-1,10.0,'10',true,$chain['blocks']+1],
    'requiredPeers'=>[0,1,2.0,'2',true], 'reason'=>[null,false,[],1],
];
foreach ($invalid as $field=>$values) foreach ($values as $value) {
    $bad=$chain;$bad['live_corroboration']=$live;$bad['live_corroboration'][$field]=$value;
    check(!ZclNodeReadiness::ready($bad,$header,3,$now),'reject invalid live '.$field.' with cached hold clear');
}
foreach ([['bootstrap_validation',[]],['bootstrap_validation',['tip_hold'=>true]],['initialblockdownload',true],
    ['chain','regtest'],['headers',$chain['blocks']-1],['verificationprogress',0.99],['bestblockhash',str_repeat('b',64)]] as [$field,$value]) {
    $bad=$chain;$bad['live_corroboration']=$live;$bad[$field]=$value;
    check(!ZclNodeReadiness::ready($bad,$header,3,$now),'live diagnostic preserves '.$field.' guard');
}
$current=$chain;$current['live_corroboration']=$live;
foreach ([['hash',str_repeat('b',64)],['height',$chain['blocks']-1],['time',$now-1801],['time',$now+601],['confirmations',-1]] as [$field,$value]) {
    $bad=$header;$bad[$field]=$value;
    check(!ZclNodeReadiness::ready($current,$bad,3,$now),'live diagnostic preserves header '.$field.' guard');
}
check(!ZclNodeReadiness::ready($current,$header,0,$now),'live diagnostic preserves connectivity guard');
foreach ([null,[],['held'=>true],['held'=>0],['held'=>'false']] as $cached) {
    $bad=$chain;$bad['finalization_hold']=$cached;
    check(!ZclNodeReadiness::ready($bad,$header,3,$now),'absent live diagnostic requires explicit cached false');
}
$bad=$chain;unset($bad['finalization_hold']);
check(!ZclNodeReadiness::ready($bad,$header,3,$now),'absent live and cached diagnostics fail closed');
echo "ZCL mainnet readiness guards passed.\n";
