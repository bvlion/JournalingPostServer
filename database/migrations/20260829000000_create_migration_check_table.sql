-- マイグレーション機構が実際に動作することを確認するためだけのテーブル。
-- 業務データは保持しない。Issue #2 / #3 で最初の実テーブル
-- (installation / scheduled trigger) を追加する際に、DROP するマイグレーションを
-- 追加して削除する。
CREATE TABLE IF NOT EXISTS migration_check (
    id TINYINT UNSIGNED NOT NULL,
    checked_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
