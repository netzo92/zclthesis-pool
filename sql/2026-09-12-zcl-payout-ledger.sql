-- Apply once to a new, empty pool before accepting shares. Do not round/migrate a
-- live financial ledger without reconciliation. All affected tables must be InnoDB.
ALTER TABLE accounts MODIFY balance DECIMAL(24,8) NOT NULL DEFAULT 0;
ALTER TABLE payouts MODIFY amount DECIMAL(24,8) NULL, MODIFY fee DECIMAL(24,8) NULL;

CREATE TABLE zcl_payment_control (
    coin_id INT NOT NULL PRIMARY KEY,
    active_batch CHAR(32) NULL,
    lease_token CHAR(32) NULL,
    lease_until BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB;
CREATE TABLE zcl_payment_batches (
    id CHAR(32) NOT NULL PRIMARY KEY,
    coin_id INT NOT NULL,
    state VARCHAR(32) NOT NULL,
    amount_zat BIGINT NOT NULL,
    config_json TEXT NOT NULL,
    active_operation CHAR(32) NULL,
    error TEXT NULL,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    KEY coin_state (coin_id, state)
) ENGINE=InnoDB;
CREATE TABLE zcl_payment_items (
    batch_id CHAR(32) NOT NULL,
    account_id INT NOT NULL,
    address VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount_zat BIGINT NOT NULL,
    payout_id INT NOT NULL,
    PRIMARY KEY (batch_id, account_id),
    UNIQUE KEY payout_item (payout_id)
) ENGINE=InnoDB;
CREATE TABLE zcl_payment_operations (
    id CHAR(32) NOT NULL PRIMARY KEY,
    batch_id CHAR(32) NOT NULL,
    kind VARCHAR(16) NOT NULL,
    state VARCHAR(32) NOT NULL,
    request_json TEXT NOT NULL,
    opid VARCHAR(128) NULL,
    txid CHAR(64) NULL,
    error TEXT NULL,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    UNIQUE KEY operation_id (opid),
    KEY batch_operation (batch_id)
) ENGINE=InnoDB;
