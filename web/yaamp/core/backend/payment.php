<?php

function BackendPaymentsEnabled()
{
    return defined('YIIMP_PAYMENTS_ENABLED') && YIIMP_PAYMENTS_ENABLED === true;
}

function BackendPayments()
{
    if (!BackendPaymentsEnabled()) {
        debuglog('Payouts disabled: validation deployment does not send funds.');
        return;
    }
	// attempt to increase max execution time limit for the cron job
	set_time_limit(300);

	$list = getdbolist('db_coins', "enable and id in (select distinct coinid from accounts)");
	foreach($list as $coin)
		BackendCoinPayments($coin);

	dborun("update accounts set balance=0 where coinid=0");
}

function BackendUserCancelFailedPayment($userid)
{
    debuglog('Automatic payout cancellation disabled: reconcile the wallet transaction first.');
    return 0.0;
}

function BackendCoinPayments($coin)
{
    if (!BackendPaymentsEnabled()) {
        debuglog('Payouts disabled: validation deployment does not send funds.');
        return;
    }
    if (strtoupper((string) $coin->symbol) === 'ZCL') {
        debuglog('ZCL payouts require the durable shielded payout coordinator; legacy sender blocked.');
        return;
    }
    if (dboscalar("SELECT COUNT(*) FROM payouts WHERE idcoin=:coin AND IFNULL(tx,'')=''", array(':coin'=>$coin->id))) {
        debuglog('Payouts held: unresolved transaction requires wallet reconciliation.');
        return;
    }
//	debuglog("BackendCoinPayments $coin->symbol");
	$remote = new WalletRPC($coin);

	$info = $remote->getinfo();
	if(!$info) {
		debuglog("payment: can't connect to {$coin->symbol} wallet");
		return;
	}

	$txfee = floatval($coin->txfee);
	$min_payout = max(floatval(YAAMP_PAYMENTS_MINI), floatval($coin->payout_min), $txfee);

	if(date("w", time()) == 0 && date("H", time()) > 18) { // sunday evening, minimum reduced
		$min_payout = max($min_payout/10, $txfee);
		if($coin->symbol == 'DCR') $min_payout = 0.01005;
	}

	$users = getdbolist('db_accounts', "balance>$min_payout AND (payout_threshold IS NULL OR balance>payout_threshold) AND coinid={$coin->id} and !is_locked ORDER BY balance DESC");

	// todo: enhance/detect payout_max from normal sendmany error
	if($coin->symbol == 'BITC' || $coin->symbol == 'BNODE' || $coin->symbol == 'BOD' || $coin->symbol == 'DIME' || $coin->symbol == 'BTCRY' || $coin->symbol == 'IOTS' || $coin->symbol == 'ECC' || $coin->symbol == 'ADOT' || $coin->symbol == 'SAPP' || $coin->symbol == 'CURVE' || $coin->symbol == 'CBE' || $coin->symbol == 'PEPEW' || !empty($coin->payout_max))
	{
		foreach($users as $user)
		{
			$user = getdbo('db_accounts', $user->id);
			if(!$user) continue;

			$amount = $user->balance;
			while($user->balance > $min_payout && $amount > $min_payout)
			{
				debuglog("$coin->symbol sendtoaddress $user->username $amount");
				$tx = $remote->sendtoaddress($user->username, round($amount, 8));
				if(!$tx)
				{
					$error = $remote->error;
					debuglog("RPC $error, {$user->username}, $amount");
					if (stripos($error,'transaction too large') !== false || stripos($error,'invalid amount') !== false
						|| stripos($error,'insufficient funds') !== false || stripos($error,'transaction creation failed') !== false
					) {
						$coin->payout_max = min((double) $amount, (double) $coin->payout_max);
						$coin->save();
						$amount /= 2;
						continue;
					}
					break;
				}

				$payout = new db_payouts;
				$payout->account_id = $user->id;
				$payout->time = time();
				$payout->amount = bitcoinvaluetoa($amount);
				$payout->fee = 0;
				$payout->tx = $tx;
				$payout->idcoin = $coin->id;
				$payout->save();

				$user->balance -= $amount;
				$user->save();
			}
		}

		debuglog("payment done");
		return;
	}

	$total_to_pay = 0;
	$addresses = array();

	foreach($users as $user)
	{
		$total_to_pay += round($user->balance, 8);
		$addresses[$user->username] = round($user->balance, 8);
		// transaction xxx has too many sigops: 1035 > 1000
		if ($coin->symbol == 'DCR' && count($addresses) > 990) {
			debuglog("payment: more than 990 {$coin->symbol} users to pay, limit to top balances...");
			break;
		}
		// MicroBitcoin doesn't like 8 decimals
		if($coin->symbol == 'MBC') 
		{
			$total_to_pay += round($user->balance, 2);
			$addresses[$user->username] = round($user->balance, 2);
		}
	}

	if(!$total_to_pay)
	{
	//	debuglog("nothing to pay");
		return;
	}

	$coef = 1.0;
	if($info['balance']-$txfee < $total_to_pay && $coin->symbol!='BTC')
	{
		$msg = "$coin->symbol: insufficient funds for payment {$info['balance']} < $total_to_pay!";
		debuglog($msg);
		send_email_alert('payouts', "$coin->symbol payout problem detected", $msg);

		$coef = 0.5; // so pay half for now...
		$total_to_pay = $total_to_pay * $coef;
		foreach ($addresses as $key => $val) {
			$addresses[$key] = $val * $coef;
		}
		// still not possible, skip payment
		if ($info['balance']-$txfee < $total_to_pay)
			return;
	}

	if($coin->symbol=='BTC')
	{
		global $cold_wallet_table;

		$balance = $info['balance'];
		$stats = getdbosql('db_stats', "1 order by time desc");

		$renter = dboscalar("select sum(balance) from renters");
		$pie = $balance - $total_to_pay - $renter - 1;

		debuglog("pie to split is $pie");
		if($pie>0)
		{
			foreach($cold_wallet_table as $coldwallet=>$percent)
			{
				$coldamount = round($pie * $percent, 8);
				if($coldamount < $min_payout) break;

				debuglog("paying cold wallet $coldwallet $coldamount");

				$addresses[$coldwallet] = $coldamount;
				$total_to_pay += $coldamount;
			}
		}
	}

	debuglog("paying $total_to_pay {$coin->symbol}");

	$payouts = array();
	foreach($users as $user)
	{
		$user = getdbo('db_accounts', $user->id);
		if(!$user) continue;
		if(!isset($addresses[$user->username])) continue;

		$payment_amount = bitcoinvaluetoa($addresses[$user->username]);

		$payout = new db_payouts;
		$payout->account_id = $user->id;
		$payout->time = time();
		$payout->amount = $payment_amount;
		$payout->fee = 0;
		$payout->idcoin = $coin->id;

		if ($payout->save()) {
			$payouts[$payout->id] = $user->id;

			$user->balance = bitcoinvaluetoa(floatval($user->balance) - $payment_amount);
			$user->save();
		}
	}

	// sometimes the wallet take too much time to answer, so use tx field to double check
	set_time_limit(120);

	// default account
	$account = $coin->account;

	if (!$coin->txmessage)
		$tx = $remote->sendmany($account, $addresses);
	else
		$tx = $remote->sendmany($account, $addresses, 1, YAAMP_SITE_NAME);

	$errmsg = NULL;
	if(!$tx) {
		debuglog("sendmany: unable to send $total_to_pay {$remote->error} ".json_encode($addresses));
		$errmsg = $remote->error;
	}
	else if(!is_string($tx)) {
		debuglog("sendmany: result is not a string tx=".json_encode($tx));
		$errmsg = json_encode($tx);
	}

	// save processed payouts (tx)
	foreach($payouts as $id => $uid) {
		$payout = getdbo('db_payouts', $id);
		if ($payout && $payout->id == $id) {
			$payout->errmsg = $errmsg;
			if (empty($errmsg)) {
				$payout->tx = $tx;
				$payout->completed = 1;
			}
			$payout->save();
		} else {
			debuglog("payout $id for $uid not found!");
		}
	}

	if (!empty($errmsg)) {
		return;
	}

	debuglog("{$coin->symbol} payment done");

    // A missing txid is an unknown broadcast outcome, not proof of failure.
    // Never delete, refund, or retry these records automatically.
}
