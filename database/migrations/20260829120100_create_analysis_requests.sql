-- 解析requestのidempotency metadata。JournalEntry本文・AnalysisResult本文は
-- ここへ保存しない。request_fingerprintは正規化したrequestの鍵付きhash
-- （HMAC-SHA-256）であり、本文を復元できない。鍵はDBの外（環境変数
-- ANALYSIS_FINGERPRINT_SECRET）にあり、installation単位にscopeしているため、
-- DBだけを読める状態では本文の候補を列挙して突き合わせることもできない。
-- expires_atを過ぎた行は、解析requestの処理中と、XServer Cronから定期実行する
-- bin/prune-expired-analyses.php の両方で削除する。後者が無いと、requestが来なく
-- なった期間に失効した行が残り続ける。
CREATE TABLE analysis_requests (
    installation_id CHAR(36) NOT NULL,
    -- API契約の`Idempotency-Key`は`[A-Za-z0-9_-]`で大文字小文字を区別する。
    -- テーブル既定のutf8mb4_unicode_ciでは大小だけ異なるkeyが同一と判定され、
    -- 別のkeyへcached responseを返してしまうため、この列だけbinary照合にする。
    -- analysis_deliveriesの同名列も同じ照合にする（複合FKの前提）。
    idempotency_key VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
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
