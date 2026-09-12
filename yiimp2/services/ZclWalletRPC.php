<?php
namespace app\services;

/** Payout-specific loopback RPC transport: one POST, no redirects or retries. */
final class ZclWalletRPC
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;

    public function __construct(string $host, int $port, string $username, string $password)
    {
        if (!in_array($host, ['127.0.0.1','localhost','::1'], true) || $port < 1 || $port > 65535 || $username === '' || $password === '') {
            throw new \InvalidArgumentException('Payout RPC requires authenticated loopback access');
        }
        $this->host = $host === 'localhost' ? '127.0.0.1' : $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
    }

    public function __invoke(string $method, array $params)
    {
        if (!in_array($method, ['getblockchaininfo','getblockheader','getconnectioncount','validateaddress','z_validateaddress','z_getbalance','listunspent','z_shieldcoinbase','z_sendmany','z_getoperationstatus','gettransaction'], true)) {
            throw new \RuntimeException('RPC method is not part of the payout protocol');
        }
        $id = bin2hex(random_bytes(16));
        $request = json_encode(['jsonrpc'=>'1.0','id'=>$id,'method'=>$method,'params'=>array_values($params)], JSON_THROW_ON_ERROR);
        $host = $this->host === '::1' ? '[::1]' : $this->host;
        $curl = curl_init('http://' . $host . ':' . $this->port . '/');
        $body = '';
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 4 * 1024 * 1024) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if ($ok === false || $status !== 200) throw new \RuntimeException('Wallet RPC transport failed for ' . $method);
            $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($response) || ($response['id'] ?? null) !== $id || !array_key_exists('result', $response)
                || !array_key_exists('error', $response) || $response['error'] !== null) {
                throw new \RuntimeException('Wallet RPC response failed validation for ' . $method);
            }
            return $response['result'];
        } finally {
            curl_close($curl);
        }
    }
}
