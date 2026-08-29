# JournalingPostServer

JournalingPostServerは、Androidアプリ「JournalingPost」のHosted機能を担う最小構成のHTTPサーバーです。XServer上で動作させることを前提としています。

日記（JournalEntry）と解析結果（AnalysisResult）の原本は端末側にあり、サーバーは恒久保存しません。サーバーの責務は次の2つだけです。

1. 解析時刻ごろに対象端末へFCMを送る（Issue #3）
2. Androidから受け取ったJournalEntryをAI解析して結果を返す（Issue #4）

このリポジトリの現時点の内容は、Issue #1「Hosted解析サービスの基盤を構築する」で用意した**サーバーの土台のみ**です。Hosted機能そのものはまだ実装していません（[未実装のもの](#未実装のもの)を参照）。

## 技術構成

| 項目 | 採用 |
| --- | --- |
| 言語 | PHP 8.5系（本番CLIは`/opt/php-8.5.5/bin/php`） |
| Webフレームワーク | Slim 4（+ slim/psr7） |
| DBアクセス | PDO（`pdo_mysql`） |
| データベース | MySQL 5.7系 |
| 環境変数 | vlucas/phpdotenv |
| マイグレーション | SQLファイルベースの自作ランナー（`bin/migrate.php`） |
| テスト | PHPUnit（unit / integration） |
| コーディング規約 | PHP_CodeSniffer（PSR-12） |
| ローカル開発 | Docker Compose（PHP 8.5 CLI + MySQL 5.7） |
| CI | GitHub Actions（`make check`） |

方針は次のとおりです。

- APIはHTTPリクエスト内で処理を完了する同期処理とします。
- `/health`エンドポイントは実装しません。
- 不要な抽象化、DIコンテナ、基底Repository、ORM、過剰なClean Architectureは導入しません。
- 本番はDocker化しません。DockerはローカルPCの開発・検証でのみ使用します。

本番実行環境と配置構成は[本番実行環境](docs/production-environment.md)を参照してください。

## 環境設定

`.env.example`を`.env`としてコピーし、実行環境に合わせて値を設定します。`.env`はGit管理対象外です。

```shell
cp .env.example .env
```

| 環境変数 | 用途 |
| --- | --- |
| `APP_TIMEZONE` | アプリケーションおよびDBセッションのタイムゾーン |
| `DB_HOST` | データベースのホスト |
| `DB_PORT` | データベースのポート |
| `DB_NAME` | データベース名 |
| `DB_USER` | データベースのユーザー名 |
| `DB_PASSWORD` | データベースのパスワード |

すべて必須です。未指定または空の場合は、秘密値を含めずに該当する環境変数名を示して起動を失敗させます。

Push予約（`triggerAt`）は絶対時刻として扱うため、既定のタイムゾーンは`UTC`です。`.env.example`の値はすべて実データから生成していない架空値です。

## ローカル実行

ローカルPCに必要なのはDockerだけです。PHP・Composer・MySQLをローカルPCへインストールする必要はありません（`vendor`もDocker volume上に置くため、ホスト側には作られません）。

appコンテナをビルドし、依存関係をインストールします。

```shell
docker compose build app
docker compose run --rm --no-deps app composer install
```

アプリケーションを起動します。

```shell
docker compose up app
```

`http://127.0.0.1:8081/undefined-route`へアクセスすると、JSON形式の404エラーが返ります。現時点でAPIルートは1つも定義していないため、これがHTTPスタックの疎通確認になります。

停止します。

```shell
docker compose down
```

`database` volumeにより、`--volumes`を付けない停止では開発用DBのデータは失われません。

## データベースとマイグレーション

ローカル開発ではMySQL 5.7コンテナを使用します。データベースを起動し、未適用のマイグレーションを適用します。

```shell
docker compose up --detach database
docker compose run --rm app composer migrate
```

- マイグレーションSQLは`database/migrations`へ`YYYYMMDDHHMMSS_description.sql`形式で配置します。
- ファイル名の昇順で未適用のものだけを適用し、適用済みファイル名を`schema_migrations`テーブルへ記録します。同じコマンドを何度実行しても結果は変わりません。
- 適用済みファイルは変更せず、新しいファイルで差分を追加します。
- 本番データ、ダンプ、個人情報、秘密情報はマイグレーションへ含めません。

現在のテーブルは次の2つだけです。

| テーブル | 用途 |
| --- | --- |
| `schema_migrations` | 適用済みマイグレーションの記録（`database/schema_migrations.sql`） |
| `migration_check` | マイグレーション機構の動作確認専用。業務データは持たない |

`migration_check`は、マイグレーションが実際に適用されることを確認するためだけの足場です。Issue #2 / #3で最初の実テーブル（匿名installation・Push予約）を追加する際に、`DROP TABLE`するマイグレーションを追加して削除します。**将来必要になりそうなテーブルは先回りして作成しません。**

本番環境では、環境変数と依存関係を設定した後、XServerのPHP 8.5.5を明示して適用します。

```shell
/opt/php-8.5.5/bin/php bin/migrate.php
```

## テストと品質チェック

個別に実行する場合は次のとおりです。

```shell
docker compose run --rm --no-deps app composer lint      # PHP構文チェック
docker compose run --rm --no-deps app composer style     # PSR-12（phpcs）
docker compose run --rm --no-deps app composer test:unit # unit testのみ（DB不要）
docker compose run --rm app composer test                # unit + integration（DB必要）
```

テストは2つのtestsuiteに分かれています。

- `tests/Unit`: DBを必要としないテスト（設定読み込み、接続失敗時の情報漏洩防止、Slimの404応答）
- `tests/Integration`: MySQLコンテナへ実接続するテスト（接続設定、マイグレーションの適用と再実行）

### 検証環境（`make check`）

構文確認・コーディング規約・マイグレーション適用・テストを、開発環境から分離された検証専用のCompose環境で一括実行できます。

```shell
make check
```

- `make check`は`compose.check.yaml`を検証専用のCompose project（`journalingpostserver-check`）で実行します。開発用`compose.yaml`のcontainer・network・volume・host port（8081番）とは別のprojectであり、開発用の`database` / `vendor` volumeを共有しません。
- 検証用appコンテナは実`.env`を読み込みません。`.env.example`の架空値だけを`/app/.env`へread-onlyで重ね、Composeの変数展開にも`--env-file .env.example`を使用します。
- 成功・失敗にかかわらず、終了時に検証専用projectのcontainer・network・volumeだけをcleanupします。開発中の環境には影響しません。
- GitHub Actions（`.github/workflows/ci.yaml`）も同じ`make check`を使用し、加えて`git diff --check`を実行します。

異常終了でcleanupが行われなかった場合は、検証専用projectだけを手動でcleanupできます。

```shell
make check-clean
```

開発用DBのデータを完全に削除する`docker compose down --volumes`は破壊的な操作です。実行前に必ず内容を確認してください。

## 本番環境の前提

詳細は[本番実行環境](docs/production-environment.md)にまとめています。要点は次のとおりです。

- XServerのレンタルサーバー（`BvlionBatch5` / `holidays-webhook-server`と同一環境）
- HTTPはPHP 8.5系、CLIは`/opt/php-8.5.5/bin/php`を明示して実行
- MySQL 5.7系
- Composer 2系。依存関係はリポジトリの`composer.lock`どおりに導入
- `pdo_mysql` / `mbstring` / `json` / `openssl` / `curl`が利用可能
- アプリ本体はドキュメントルート外へ配置し、`public_html`には`public/index.php`へのシンボリックリンクと`public/.htaccess`のコピーだけを置く
- `Authorization`ヘッダーは`.htaccess`のRewriteでPHPへ転送する
- XServer CronはIssue #3で使用予定

**本Issue（#1）では本番デプロイを行っていません。** XServer上のファイル・DB・cron・秘密情報にも触れていません。

## 未実装のもの

Issue #1の範囲はサーバーの土台までです。次はいずれも未実装で、後続Issueで扱います。

- Hosted解析APIとAndroid間のデータ契約（#2）
- 匿名installation認証方式の決定（#2）
- Push予約API・FCM送信・XServer Cronトリガー（#3）
- Hosted AI解析、OpenAI等のAI provider連携（#4）
- rate limit、usage集計、コスト制御（#5）
- 実データ用テーブル（installation / scheduled trigger など）
- APIルート全般（現時点でルートは1つも定義していません）
- account / profile、timezone、recurrence、entitlement、広告
- 非同期job queue、Cloud Functions / Cloud Run
- 本番デプロイおよびデプロイ自動化
