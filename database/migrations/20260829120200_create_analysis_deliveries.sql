-- 解析結果の引き渡しバッファ。responseがnetworkで失われた場合に、同じ
-- Idempotency-Keyの再送へ同じ結果を返してAIの重複課金を防ぐためだけに使う。
-- 端末が原本であり、ここは原本ではない。
--
-- idempotency metadata（analysis_requests）と本文を同じ行へ混ぜないよう、
-- テーブルを分離している。analysis_requestsの行が保持期間を過ぎて削除されると、
-- ON DELETE CASCADEでこの行も消える。
CREATE TABLE analysis_deliveries (
    installation_id CHAR(36) NOT NULL,
    -- analysis_requests.idempotency_keyと同じbinary照合にする。複合FKは
    -- 参照元と参照先で型・照合が一致している必要がある。
    idempotency_key VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    response_body MEDIUMTEXT NOT NULL,
    PRIMARY KEY (installation_id, idempotency_key),
    CONSTRAINT fk_analysis_deliveries_request
        FOREIGN KEY (installation_id, idempotency_key)
        REFERENCES analysis_requests (installation_id, idempotency_key)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
