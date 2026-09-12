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
echo "ZCL mainnet readiness guards passed.\n";
