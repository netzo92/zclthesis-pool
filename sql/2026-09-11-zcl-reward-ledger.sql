-- Apply with workers stopped. Existing unjournaled earnings require manual
-- reconciliation; this migration deliberately does not invent allocation history.
ALTER TABLE earnings MODIFY amount DECIMAL(24,8) NULL DEFAULT NULL;
ALTER TABLE blocks MODIFY amount DECIMAL(24,8) NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS zcl_reward_rounds (
    block_id INT UNSIGNED NOT NULL PRIMARY KEY,
    coin_id INT NOT NULL,
    blockhash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reward_sat DECIMAL(24,0) NOT NULL,
    credited_sat DECIMAL(24,0) NOT NULL,
    retained_sat DECIMAL(24,0) NOT NULL,
    share_count BIGINT NOT NULL,
    last_share_id BIGINT NOT NULL,
    difficulty DECIMAL(60,24) NOT NULL,
    created_at INT NOT NULL,
    UNIQUE KEY coin_block (coin_id, blockhash),
    CONSTRAINT zcl_round_conservation CHECK (reward_sat = credited_sat + retained_sat),
    CONSTRAINT zcl_round_nonnegative CHECK (credited_sat >= 0 AND retained_sat >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS zcl_accounting_holds (
    coin_id INT NOT NULL PRIMARY KEY,
    reason VARCHAR(255) NOT NULL,
    created_at INT NOT NULL
) ENGINE=InnoDB;
