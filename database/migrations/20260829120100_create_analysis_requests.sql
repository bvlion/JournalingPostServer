-- 解析requestのidempotency metadata。JournalEntry本文・AnalysisResult本文は
-- ここへ保存しない。request_fingerprintは正規化したrequestのSHA-256であり、
-- 本文を復元できない。
-- expires_atを過ぎた行は、解析requestの処理中と、XServer Cronから定期実行する
-- bin/prune-expired-analyses.php の両方で削除する。後者が無いと、requestが来なく
-- なった期間に失効した行が残り続ける。
CREATE TABLE analysis_requests (
    installation_id CHAR(36) NOT NULL,
    idempotency_key VARCHAR(64) NOT NULL,
    request_fingerprint CHAR(64) NOT NULL,
    started_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL DEFAULT NULL,
    expires_at DATETIME(6) NOT NULL,
    PRIMARY KEY (installation_id, idempotency_key),
    KEY idx_analysis_requests_expires_at (expires_at),
    CONSTRAINT fk_analysis_requests_installation
        FOREIGN KEY (installation_id) REFERENCES installations (id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
