<?php
require __DIR__ . '/../../yiimp2/services/ZclWalletRPC.php';
use app\services\ZclWalletRPC;
function checkTransport($condition,$message) { if(!$condition) throw new RuntimeException($message); }
foreach(['example.org','https://localhost','127.0.0.2'] as $host) {
    try { new ZclWalletRPC($host,8023,'test','test'); throw new RuntimeException('Remote host accepted'); }
    catch(InvalidArgumentException $expected) {}
}
foreach(['success','wrong-id','redirect','error','malformed'] as $scenario) {
    $server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    if(!$server) throw new RuntimeException($error);
    $port=(int)substr(strrchr(stream_socket_get_name($server,false),':'),1);
    $pid=pcntl_fork();
    if($pid===0) {
        $peer=stream_socket_accept($server,5);
        if(!$peer) exit(10);
        $headers='';
        while(!str_contains($headers,"\r\n\r\n")) {
            $chunk=fread($peer,1); if($chunk==='')exit(11); $headers.=$chunk;
        }
        preg_match('/Content-Length:\s*([0-9]+)/i',$headers,$match);
        $body=''; $length=(int)($match[1]??0);
        while(strlen($body)<$length) $body.=fread($peer,$length-strlen($body));
        $request=json_decode($body,true);
        if(($request['method']??null)!=='z_sendmany' || ($request['params'][1][0]['amount']??null)!=='0.00000001') exit(12);
        $result=['result'=>'opid-test-12345678','error'=>null,'id'=>$request['id']];
        if($scenario==='wrong-id') $result['id']='wrong';
        if($scenario==='error') $result['error']=['code'=>-1,'message'=>'test'];
        $response=$scenario==='malformed' ? '{' : json_encode($result);
        $status=$scenario==='redirect' ? '307 Temporary Redirect' : '200 OK';
        $redirect=$scenario==='redirect' ? "Location: http://127.0.0.1:$port/again\r\n" : '';
        fwrite($peer,"HTTP/1.1 $status\r\n{$redirect}Content-Length: ".strlen($response)."\r\nConnection: close\r\n\r\n$response");
        fclose($peer);
        if($scenario==='redirect' && @stream_socket_accept($server,1)) exit(13);
        fclose($server); exit(0);
    }
    checkTransport($pid>0,'Could not start fake wallet server'); fclose($server);
    $rejected=false;
    try {
        $value=(new ZclWalletRPC('127.0.0.1',$port,'test-only','test-only'))('z_sendmany',['zsTest',[['address'=>'tTest','amount'=>'0.00000001']],6,0.0001]);
    } catch(RuntimeException|JsonException $e) {
        $rejected=true;
        if($scenario==='success') throw $e;
    }
    checkTransport($rejected===($scenario!=='success'),'Invalid response accepted');
    if(!$rejected) checkTransport($value==='opid-test-12345678','Wrong RPC result');
    pcntl_waitpid($pid,$status);
    checkTransport(pcntl_wexitstatus($status)===0,'Transport redirected, duplicated, or changed RPC request');
    echo "PASS payout RPC transport: $scenario\n";
}
