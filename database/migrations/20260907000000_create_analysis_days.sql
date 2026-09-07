CREATE TABLE analysis_days (
    installation_id CHAR(36) NOT NULL,
    analysis_date CHAR(8) NOT NULL,
    PRIMARY KEY (installation_id, analysis_date),
    KEY idx_analysis_days_date (analysis_date),
    FOREIGN KEY (installation_id) REFERENCES installations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
