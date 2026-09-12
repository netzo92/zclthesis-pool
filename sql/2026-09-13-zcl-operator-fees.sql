-- Apply with ZCL workers stopped, after both reward and payout ledger migrations.
-- NULL preserves uncertainty for pre-migration rows; never infer historical fees.
ALTER TABLE zcl_reward_rounds ADD fee_sat DECIMAL(24,0) NULL DEFAULT NULL;
ALTER TABLE zcl_payment_operations ADD network_fee_zat BIGINT NULL DEFAULT NULL;
ALTER TABLE zcl_payment_batches ADD purpose VARCHAR(16) NOT NULL DEFAULT 'miners';
ALTER TABLE zcl_payment_batches ADD operator_address VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL;
CREATE TABLE zcl_operator_credits (
    block_id INT UNSIGNED NOT NULL PRIMARY KEY,
    coin_id INT NOT NULL,
    amount_zat BIGINT NOT NULL,
    created_at BIGINT NOT NULL,
    KEY coin_credits (coin_id),
    CONSTRAINT zcl_operator_nonnegative CHECK (amount_zat >= 0)
) ENGINE=InnoDB;
