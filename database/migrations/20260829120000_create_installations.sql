-- 匿名installation。account / profileは持たず、Hosted APIを利用してよい
-- installationであることを確認するための最小情報だけを保持する。
-- api_key_hashはServerが発行したAPI keyのSHA-256（16進64文字）で、平文は保存しない。
CREATE TABLE installations (
    id CHAR(36) NOT NULL,
    api_key_hash CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    last_used_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_installations_api_key_hash (api_key_hash)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
