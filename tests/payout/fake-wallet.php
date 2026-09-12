<?php
use app\services\ZclPayoutLedger;
use app\services\ZclPayoutCoordinator;
class FakeWallet {
    public array $calls = [];
    public int $mutations = 0;
    public string $shieldBalance = '0';
    public array $utxos = [];
    public int $confirmations = 6;
    public string $opStatus = 'success';
    public bool $loseOperation = false;
    public bool $failMutation = false;
    public bool $failRead = false;
    public $duringMutation = null;
    public function __construct() {
        $this->utxos = [['address'=>config()['pool_taddress'],'generated'=>true,'spendable'=>true,'confirmations'=>101,'amount'=>'1.0']];
    }
    public function __invoke(string $method,array $params) {
        $this->calls[] = [$method,$params];
        switch ($method) {
            case 'getblockchaininfo': return ['chain'=>'main','blocks'=>1000,'headers'=>1000,'verificationprogress'=>1];
            case 'validateaddress': return ['isvalid'=>true,'ismine'=>$params[0]===config()['pool_taddress']];
            case 'z_validateaddress': return ['isvalid'=>true,'ismine'=>true,'type'=>'sapling'];
            case 'z_getbalance': return $this->shieldBalance;
            case 'listunspent': return $this->utxos;
            case 'z_shieldcoinbase':
            case 'z_sendmany':
                $this->mutations++;
                if ($this->duringMutation) ($this->duringMutation)();
                if ($this->failMutation) throw new RuntimeException('Transport timed out after possible broadcast');
                return $method==='z_shieldcoinbase' ? ['opid'=>'opid-shield-11111111'] : 'opid-send-22222222';
            case 'z_getoperationstatus':
                if ($this->failRead) throw new RuntimeException('Temporary status-read failure');
                if ($this->loseOperation) return [];
                return [['id'=>$params[0][0],'status'=>$this->opStatus,'result'=>['txid'=>str_repeat(str_contains($params[0][0],'shield')?'a':'b',64)]]];
            case 'gettransaction': return ['txid'=>$params[0],'confirmations'=>$this->confirmations,'walletconflicts'=>[]];
            default: throw new RuntimeException('Unexpected RPC '.$method);
        }
    }
}
function coordinator(PDO $db, FakeWallet $wallet, $enabled=true): ZclPayoutCoordinator {
    return new ZclPayoutCoordinator(new ZclPayoutLedger($db,1),$wallet,config(),$enabled);
}

