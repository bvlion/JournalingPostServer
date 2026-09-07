CREATE TABLE provider_calls (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    installation_id CHAR(36) NOT NULL,
    recorded_at DATETIME(6) NOT NULL,
    model VARCHAR(128) NULL,
    input_tokens BIGINT UNSIGNED NULL,
    cached_input_tokens BIGINT UNSIGNED NULL,
    output_tokens BIGINT UNSIGNED NULL,
    KEY idx_provider_calls_date (recorded_at),
    KEY idx_provider_calls_installation (installation_id),
    FOREIGN KEY (installation_id) REFERENCES installations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
