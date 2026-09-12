<?php
require __DIR__ . '/fixture.php';
require __DIR__ . '/fake-wallet.php';

function liveDiagnostic(FakeWallet $wallet): array {
    return ['schemaVersion'=>1,'ready'=>true,'tipHash'=>$wallet->chain['bestblockhash'],
        'tipHeight'=>$wallet->chain['blocks'],'candidateHeight'=>$wallet->chain['blocks']-10,
        'requiredDepth'=>10,'requiredPeers'=>2,'reason'=>'Current peer corroboration'];
}

// A cached hold must not strand a ready mainnet payout before reservation.
$db=setup();$wallet=new FakeWallet();$worker=coordinator($db,$wallet);
$wallet->chain['finalization_hold']['held']=true;
check($worker->tick()==='syncing' && $wallet->mutations===0,'Cached hold did not stop payout');
check(balance($db,1)===9920000 && scalar($db,'SELECT COUNT(*) FROM payouts')==0,'Held node reserved a payout');
$wallet->chain['live_corroboration']=liveDiagnostic($wallet);
check($worker->tick()==='shield-submitted' && $wallet->mutations===1,'Valid live diagnostic did not supersede cached hold');
$wallet->chain['live_corroboration']['ready']=false;
$wallet->chain['finalization_hold']['held']=false;
check($worker->tick()==='syncing' && $wallet->mutations===1,'Lost live readiness continued an active payout');
check(balance($db,1)===0 && scalar($db,'SELECT COUNT(*) FROM payouts')==2,'Lost readiness released or duplicated the reservation');

$cases=[
    'null live'=>fn($w)=>$w->chain['live_corroboration']=null,
    'false live'=>fn($w)=>$w->chain['live_corroboration']=false,
    'empty live'=>fn($w)=>$w->chain['live_corroboration']=[],
    'not ready'=>fn($w)=>$w->chain['live_corroboration']=array_replace(liveDiagnostic($w),['ready'=>false]),
    'stale hash'=>fn($w)=>$w->chain['live_corroboration']=array_replace(liveDiagnostic($w),['tipHash'=>str_repeat('b',64)]),
    'stale height'=>fn($w)=>$w->chain['live_corroboration']=array_replace(liveDiagnostic($w),['tipHeight'=>999]),
    'wrong candidate'=>fn($w)=>$w->chain['live_corroboration']=array_replace(liveDiagnostic($w),['candidateHeight'=>991]),
    'one peer'=>fn($w)=>$w->chain['live_corroboration']=array_replace(liveDiagnostic($w),['requiredPeers'=>1]),
    'zero depth'=>fn($w)=>$w->chain['live_corroboration']=array_replace(liveDiagnostic($w),['requiredDepth'=>0]),
    'unknown schema'=>fn($w)=>$w->chain['live_corroboration']=array_replace(liveDiagnostic($w),['schemaVersion'=>2]),
    'missing cache'=>function($w){unset($w->chain['finalization_hold']);},
    'malformed cache'=>fn($w)=>$w->chain['finalization_hold']=['held'=>0],
    'missing bootstrap'=>function($w){unset($w->chain['bootstrap_validation']);},
];
foreach (['bootstrap hold','ibd','headers behind','low progress'] as $name) {
    $cases[$name]=function($w)use($name){
        $w->chain['live_corroboration']=liveDiagnostic($w);
        if($name==='bootstrap hold')$w->chain['bootstrap_validation']['tip_hold']=true;
        if($name==='ibd')$w->chain['initialblockdownload']=true;
        if($name==='headers behind')$w->chain['headers']=999;
        if($name==='low progress')$w->chain['verificationprogress']=0.99;
    };
}
foreach ($cases as $label=>$change) {
    $db=setup();$wallet=new FakeWallet();$change($wallet);
    check(coordinator($db,$wallet)->tick()==='syncing' && $wallet->mutations===0,'Payout did not close: '.$label);
    check(balance($db,1)===9920000 && scalar($db,'SELECT COUNT(*) FROM payouts')==0,'Closed readiness reserved funds: '.$label);
}
// Rollback to an older daemon still requires its cached mainnet hold to clear.
$db=setup();$wallet=new FakeWallet();
check(coordinator($db,$wallet)->tick()==='shield-submitted','Official daemon cached-clear compatibility failed');
// Keep the existing isolated regtest contract for older daemons without fields.
$db=setup();$wallet=new FakeWallet();$wallet->chain['chain']='regtest';
unset($wallet->chain['bootstrap_validation'],$wallet->chain['finalization_hold']);
$worker=new app\services\ZclPayoutCoordinator(new app\services\ZclPayoutLedger($db,1),$wallet,config()+['network'=>'regtest'],true);
check($worker->tick()==='shield-submitted','Legacy isolated regtest compatibility failed');
echo "PASS payout live readiness, cached fallback and reservation preservation\n";
