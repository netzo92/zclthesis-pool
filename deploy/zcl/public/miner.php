<?php
/** Expose only through the exact /api/miner.json Caddy route, never a PHP wildcard. */
declare(strict_types=1);

ini_set('display_errors','0');
ini_set('log_errors','0');
set_time_limit(8);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    exit;
}
function unavailable(): array {
    return ['schemaVersion'=>1,'asset'=>'ZCL','generatedAt'=>gmdate('Y-m-d\TH:i:s\Z'),
        'status'=>'unavailable','address'=>null,'network'=>null,'pool'=>null,'miner'=>null,
        'history'=>['basis'=>'session-observations-only','available'=>false]];
}
function publicJson(string $path): ?array {
    if (!is_file($path) || filesize($path)>65536) return null;
    $data=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    return is_array($data) ? $data : null;
}
try {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '',['GET','HEAD'],true)) respond(405,['error'=>'method-not-allowed']);
    if (strlen($_SERVER['QUERY_STRING'] ?? '')>100 || count($_GET)>1 || array_diff(array_keys($_GET),['address'])) {
        respond(400,['error'=>'invalid-address']);
    }
    $root=dirname(__DIR__,3);
    require $root.'/yiimp2/services/ZclAmount.php';
    require $root.'/yiimp2/services/ZclMinerStats.php';
    $address=$_GET['address'] ?? null;
    if ($address!==null) {
        if (!is_string($address)) respond(400,['error'=>'invalid-address']);
        try { $address=\app\services\ZclMinerStats::address($address); }
        catch (\InvalidArgumentException $e) { respond(400,['error'=>'invalid-address']); }
    }

    // Bounded shared cache, one concurrent DB reader, at most four cache misses/sec.
    // The operator creates this nonpublic directory owned by the PHP-FPM user.
    $cache='/var/cache/zcl-miner-stats';
    if (!is_dir($cache) || !is_writable($cache)) throw new RuntimeException('Cache unavailable');
    $key=hash('sha256',$address ?? 'pool');
    $path=$cache.'/'.$key.'.json';
    $readCache=static function() use ($path): ?array {
        $now=time();
        if (!is_file($path) || filemtime($path)<$now-10 || filesize($path)>65536) return null;
        $data=json_decode((string)file_get_contents($path),true);
        return is_array($data) && isset($data['generatedAt']) && strtotime($data['generatedAt'])>=$now-10 ? $data : null;
    };
    if (($cached=$readCache())!==null) respond(200,$cached);
    $lock=fopen($cache.'/reader.lock','c+');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { header('Retry-After: 2'); respond(503,unavailable()); }
    if (($cached=$readCache())!==null) respond(200,$cached);
    $previous=(float)stream_get_contents($lock);
    if (microtime(true)-$previous<0.25) { header('Retry-After: 2'); respond(503,unavailable()); }
    ftruncate($lock,0); rewind($lock); fwrite($lock,(string)microtime(true)); fflush($lock);
    $files=glob($cache.'/*.json');
    if (count($files)>512) throw new RuntimeException('Cache capacity reached');
    foreach ($files as $file) if (filemtime($file)<time()-60) unlink($file);
    if (count(glob($cache.'/*.json'))>=512 && !is_file($path)) { header('Retry-After: 10'); respond(503,unavailable()); }

    // No web Application, queue, session, admin controller or wallet RPC is started.
    require '/etc/yiimp/serverconfig.php';
    require $root.'/yiimp2/config/constants.php';
    $db=new PDO('mysql:host='.YIIMP_DBHOST.';dbname='.YIIMP_DBNAME.';charset=utf8',YIIMP_DBUSER,YIIMP_DBPASSWORD,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_STRINGIFY_FETCHES=>true,PDO::ATTR_TIMEOUT=>2]);
    $db->exec('SET SESSION max_statement_time=2');
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    $node=publicJson('/var/lib/zcl-public/api/node.json');
    $pool=publicJson('/var/lib/zcl-public/api/pool.json');
    $mined=is_array($pool['mined'] ?? null) ? $pool['mined'] : null;
    // Only the already-public mined summary is copied; never arbitrary pool metadata.
    if ($mined!==null) $mined=array_intersect_key($mined,array_flip([
        'schemaVersion','asset','generatedAt','status','coverageStartedAt','coverageBasis','windowBasis','rewardBasis',
        'allTime','last24h','lastHour','unknownBlocks','excludedOrphans','accountingHeld']));
    $minimum=\app\services\ZclAmount::parse(defined('YIIMP_ZCL_PAYOUT_MIN') ? YIIMP_ZCL_PAYOUT_MIN : '0.05');
    $result=(new \app\services\ZclMinerStats($db))->snapshot($address,time(),$node,$mined,$minimum);
    $db->commit();
    $json=json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    if (strlen($json)>65536) throw new RuntimeException('Response limit reached');
    $temporary=$path.'.tmp';
    if (file_put_contents($temporary,$json,LOCK_EX)===false) throw new RuntimeException('Cache write failed');
    chmod($temporary,0600);
    if (!rename($temporary,$path)) throw new RuntimeException('Cache publication failed');
    respond(200,$result);
} catch (Throwable $e) {
    // Errors contain no SQL, configuration, account identifiers, addresses or keys.
    respond(503,unavailable());
}
