#!/usr/bin/env bash
set -eu
cd "$(dirname "$0")/../.."
for mode in unset disabled string integer enabled; do
  php tests/payout/gates.php "$mode"
done
for file in yiimp2/services/PaymentService.php yiimp2/jobs/earnings/PaymentsJob.php yiimp2/commands/PayoutController.php web/yaamp/core/backend/payment.php web/yaamp/core/backend/system.php web/yaamp/commands/PayoutCommand.php; do
  php -l "$file"
done
