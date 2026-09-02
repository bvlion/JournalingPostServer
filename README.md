# JournalingPostServer

JournalingPostServerは、Androidアプリ「JournalingPost」のHosted機能を担う最小構成のHTTPサーバーです。XServer上で動作させることを前提としています。

日記（JournalEntry）と解析結果（AnalysisResult）の原本は端末側にあり、サーバーは恒久保存しません。サーバーの責務は、Androidから受け取ったJournalEntryをAI解析して同じHTTP応答で結果を返すこと（Issue #4）だけです。AI解析はOpenAI Responses APIで行います。

解析開始の主体は手動・自動ともAndroidです。実行タイミング（timezone・recurrence・自動解析スケジュール）の判断と解析後の通知はAndroid側で行うため、サーバーはscheduler・Pushサーバーになりません。FCM・`triggerAt`・Push予約は使用しません（Issue #3で採用しないと決定）。

実装済みなのは、サーバーの土台（Issue #7）、Hosted解析APIの契約・匿名installation認証・idempotency（Issue #2）、Hosted AI解析（Issue #4）です。Hosted Server全体は親Issue #1 で管理しています。実OpenAI / XServerでのtimeout実測を行い、`OPENAI_TIMEOUT_SECONDS` は `45` 秒に決定しました（[Hosted解析API契約](docs/hosted-analysis-api.md)の「本番timeoutの決定」）。Issue #13でこのServer実装をXServer本番環境へ配置し、HTTPS経路でのServer単体smoke test（installation登録・実OpenAI解析・idempotency再送・`Authorization` 転送・平文HTTP拒否・失効データ削除Cron）まで確認しました。

API契約は[Hosted解析API契約](docs/hosted-analysis-api.md)にまとめています。AndroidとServerはこの文書を共有します。

## 技術構成

| 項目 | 採用 |
| --- | --- |
| 言語 | PHP 8.5系（本番CLIは`/opt/php-8.5.5/bin/php`） |
| Webフレームワーク | Slim 4（+ slim/psr7） |
| DBアクセス | PDO（`pdo_mysql`） |
| データベース | MySQL 5.7系 |
| 環境変数 | vlucas/phpdotenv |
| マイグレーション | SQLファイルベースの自作ランナー（`bin/migrate.php`） |
| 時刻の扱い | UTC固定（設定項目にしない） |
| テスト | PHPUnit（unit / integration） |
| コーディング規約 | PHP_CodeSniffer（PSR-12） |
| ローカル開発 | Docker Compose（PHP 8.5 CLI + MySQL 5.7） |
| CI | GitHub Actions（`make check`） |

方針は次のとおりです。

- APIはHTTPリクエスト内で処理を完了する同期処理を第一候補とします（恒久的な制約ではありません。Issue #4で同期処理が成立しないと実測できた場合に限り、非同期化を検討します）。
- 不要な抽象化、DIコンテナ、基底Repository、ORM、過剰なClean Architectureは導入しません。
- 本番はDocker化しません。DockerはローカルPCの開発・検証でのみ使用します。

本番実行環境と配置構成は[本番実行環境](docs/production-environment.md)を参照してください。

## API

| Endpoint | 内容 |
| --- | --- |
| `POST /v1/installations` | 匿名installationを登録し、Hosted API用のAPI keyを発行する（Androidが保持するのはこのAPI keyだけ） |
| `POST /v1/analyses` | 対象期間のJournalEntryを受け取り、AI解析結果を同じ応答で返す |

`POST /v1/analyses`は`Authorization: Bearer <API key>`と`Idempotency-Key`を必要とします。request / responseのschema、error契約、retry / idempotency、保持期間は[Hosted解析API契約](docs/hosted-analysis-api.md)を参照してください。

`POST /v1/analyses`は認証・検証・idempotencyを通したうえでOpenAI Responses APIを呼び、7項目を整形したプレーンテキストを返します。provider利用不能は`503 analysis_unavailable`、送信後に結果を確定できない失敗（timeout等）は`504 analysis_timeout` / `500 internal_error`で、後者はclaimを解放せず二重課金を避けます。

## 環境設定

`.env.example`を`.env`としてコピーし、実行環境に合わせて値を設定します。`.env`はGit管理対象外です。

```shell
cp .env.example .env
```

| 環境変数 | 用途 |
| --- | --- |
| `DB_HOST` | データベースのホスト |
| `DB_PORT` | データベースのポート |
| `DB_NAME` | データベース名 |
| `DB_USER` | データベースのユーザー名 |
| `DB_PASSWORD` | データベースのパスワード |
| `ANALYSIS_FINGERPRINT_SECRET` | 解析requestのfingerprintを鍵付きにするための秘密値（32文字以上） |
| `OPENAI_API_KEY` | OpenAI Responses APIのAPI key |
| `OPENAI_TIMEOUT_SECONDS` | OpenAI呼び出しのtimeout秒数（正の整数）。実測により本番値は `45`（[本番timeoutの決定](docs/hosted-analysis-api.md#timeout)） |

すべて必須です。未指定または空の場合は、秘密値を含めずに該当する環境変数名を示して起動を失敗させます。`ANALYSIS_FINGERPRINT_SECRET`は32文字未満、`OPENAI_TIMEOUT_SECONDS`は正の整数でない場合も同じように失敗します。`.env.example`の`OPENAI_API_KEY`など秘密系の値はすべて実データから生成していない架空値です。`OPENAI_TIMEOUT_SECONDS=45`は実測にもとづく本番相当値です。

DB接続だけを行うCLI（`bin/migrate.php`・`bin/prune-expired-analyses.php`）は`DB_*`だけを必須にし、`ANALYSIS_FINGERPRINT_SECRET` / `OPENAI_*`を検証しません（`bootstrap/database-config.php`）。API keyの失効対応などでOpenAI設定を空にしても、5分間隔の失効データ削除Cronが起動不能になって解析結果本文が保持期間を越えて残ることがないようにするためです。HTTPアプリ（`bootstrap/config.php`）は従来どおり全項目を必須検証します。

`ANALYSIS_FINGERPRINT_SECRET`は、DBを読める状態からJournalEntryの内容を候補照合で言い当てられないようにするためのものです（[Hosted解析API契約](docs/hosted-analysis-api.md)の「Serverが保持するデータと保持期間」を参照）。ランダム値を1度だけ生成し、**deployを跨いで同じ値を使います**。値が変わると、保持期間（30分）内の再送が別内容と判定されて`409 idempotency_key_reuse`になります。

```shell
php -r 'echo base64_encode(random_bytes(48)), PHP_EOL;'
```

タイムゾーンは環境変数にしていません。Hosted Serverはユーザーのtimezoneやrecurrenceを解釈せず、絶対時刻だけを扱うため、PHPの既定タイムゾーンとMySQLのセッションタイムゾーンをUTCへ固定しています。表示のためのtimezone変換は端末側の責務です。

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

疎通確認には匿名installationの登録を使えます。

```shell
curl -i -X POST http://127.0.0.1:8081/v1/installations
```

未定義のパスへアクセスすると、同じ形のJSONエラー（`{"error": {"code": "not_found", ...}}`）が返ります。

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
- ファイル名の昇順で未適用のものだけを適用し、適用済みファイル名を`schema_migrations`テーブルへ記録します。正常終了したマイグレーションは記録済みとして読み飛ばされるため、同じコマンドを再実行しても二重に適用されません。
- 適用済みファイルは変更せず、新しいファイルで差分を追加します。
- 本番データ、ダンプ、個人情報、秘密情報はマイグレーションへ含めません。

### 異常終了時の復旧

SQLの適用と`schema_migrations`への記録は別のステートメントです。MySQLのDDLはトランザクションで巻き戻せないため、SQLを適用した後・記録する前にプロセスやDB接続が落ちると、スキーマだけが変更され記録が残らない状態になり得ます。この場合、次回の実行では同じファイルが未適用とみなされ、`CREATE TABLE`や`ALTER TABLE`が失敗します。

このランナーは復旧を自動化しません。個人開発規模・手動デプロイ・SQLファイルベースという前提に対して、in-progress状態の管理、ロック機構、自動リカバリはServerを不必要に複雑にするため導入していません。異常終了した場合は、DBの実際の適用状態を確認したうえで手動で復旧します。

- スキーマが適用済みであれば、該当ファイル名を`schema_migrations`へ手動でINSERTする。
- スキーマが部分的にしか適用されていなければ、その部分を手動で戻してから再実行する。

現在のテーブルは次のとおりです。**将来必要になりそうなテーブルは先回りして作成しません。**

| テーブル | 内容 | 追加したIssue |
| --- | --- | --- |
| `schema_migrations` | 適用済みマイグレーションの記録（`database/schema_migrations.sql`） | #7 |
| `installations` | Server内部のinstallation識別子とAPI keyのSHA-256 | #2 |
| `analysis_requests` | 解析requestのidempotency metadata（本文は含まず、鍵付きhashだけ） | #2 |
| `analysis_deliveries` | 再送へ同じ結果を返すための解析結果の引き渡しバッファ | #2 |

JournalEntry本文はDBへ保存しません。解析結果本文もServerの原本にはせず、引き渡しバッファへ保持期間（解析完了から30分）の間だけ残します。詳細は[Hosted解析API契約](docs/hosted-analysis-api.md)の「Serverが保持するデータと保持期間」を参照してください。

### 失効データの削除

失効した解析metadataと引き渡しバッファは、解析requestの処理中に削除します。ただしそれだけでは、requestが来なくなった期間に解析結果本文が保持期間を越えて残り続けます。定期実行で削除してください。

```shell
docker compose run --rm app composer prune
```

本番では、XServer Cronから5分間隔で実行します。Cronの作業ディレクトリはアプリ本体の配置ディレクトリとは限らないため、移動してから実行します（`<アプリ本体の配置ディレクトリ>`は実際のパスに置き換えます）。

```shell
cd <アプリ本体の配置ディレクトリ> && /opt/php-8.5.5/bin/php bin/prune-expired-analyses.php
```

出力は削除件数だけで、本文やinstallation識別子をログへ残しません。このCronは`DB_*`だけを必要とし、`OPENAI_*` / `ANALYSIS_FINGERPRINT_SECRET`が欠落・空でも実行できます。

マイグレーション機構そのものの動作確認は、`tests/Integration/MigrationRunnerTest.php`が一時ディレクトリへその場限りのマイグレーションを生成し、適用・記録・再実行・ファイル名順の適用を検証します。動作確認だけを目的とした永続テーブルは`database/migrations`へ置きません。

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

- `tests/Unit`: DBを必要としないテスト（設定読み込み、UTC固定、接続失敗時の情報漏洩防止、ルーティングのエラー応答、解析requestの検証、API keyの形式）
- `tests/Integration`: MySQLコンテナへ実接続するテスト（接続設定、一時マイグレーションによるマイグレーション機構の検証、Hosted解析APIの契約）

`tests/Integration/HostedAnalysisApiTest.php`は、`Analyzer` seamでAI providerを差し替えてAPI境界（認証・検証・idempotency・error契約・保持期間）を検証します。実OpenAI Analyzerは`tests/Unit/OpenAiAnalyzerTest.php`（request構築・入力整形・応答解析・失敗時の扱い）とHosted APIの一部testが、実OpenAIへ接続しないfake transportで検証します。

### 検証環境（`make check`）

構文確認・コーディング規約・マイグレーション適用・テストを、開発環境から分離された検証専用のCompose環境で一括実行できます。

```shell
make check
```

- `make check`は`compose.check.yaml`を検証専用のCompose projectで実行します。開発用`compose.yaml`のcontainer・network・volume・host port（8081番）とは別のprojectであり、開発用の`database` / `vendor` volumeを共有しません。
- 開発用のproject名はComposeがチェックアウト先のディレクトリ名から導出します。検証用のproject名は`journalingpostserver-check-<チェックアウトの絶対パスのhash>`で、ディレクトリ名に依存しません。そのため、このチェックアウトの開発用projectとも、別のチェックアウトの開発用projectとも一致しません。hashは決定的なので`make check-clean`でも同じprojectを対象にできます（`make check` / `make check-clean` は実行前にこれを確認します）。
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
- HTTPはPHP 8.5系（本番サーバーパネルで 8.5.9 / `display_errors` OFF を確認済み）、CLIは`/opt/php-8.5.5/bin/php`を明示して実行
- MySQL 5.7系
- Composer 2系。依存関係はリポジトリの`composer.lock`どおりに導入
- `pdo_mysql` / `mbstring` / `json` / `openssl` / `curl`が利用可能
- サーバーからOpenAI（`api.openai.com`）へのHTTPS outboundが必要（AI解析。curlで呼ぶ）
- `.env`に`OPENAI_API_KEY`と`OPENAI_TIMEOUT_SECONDS`（実測により`45`）を設定する（[Hosted解析API契約](docs/hosted-analysis-api.md)の「本番timeoutの決定」）
- Hosted APIはHTTPSでのみ提供する。平文HTTPのrequestは`.htaccess`でリダイレクトせず拒否する（Bearer API keyとJournalEntry本文を平文HTTPで送らせない）
- アプリ本体はドキュメントルート外へ配置し、`public_html`には`public/index.php`へのシンボリックリンクと`public/.htaccess`のコピーだけを置く
- `Authorization`ヘッダーは`.htaccess`のRewriteでPHPへ転送し、`public/index.php`が`REDIRECT_HTTP_AUTHORIZATION`からの受け取りにも対応する
- XServer Cronで失効データの削除（`bin/prune-expired-analyses.php`）を5分間隔で実行する。Cronの用途はこれだけである

**Issue #13でこのServer実装をXServer本番環境へ配置しました。** 本番DBへ既存migrationを適用し、本番 `.env`（`DB_*` / `ANALYSIS_FINGERPRINT_SECRET` / `OPENAI_API_KEY` / `OPENAI_TIMEOUT_SECONDS=45`）を設定し、失効データ削除の5分Cronを設定しました。HTTPS経路で `POST /v1/installations` の201、Bearer認証した `POST /v1/analyses` の実OpenAI解析200、同一 `Idempotency-Key` 再送での初回と同一response、Apache経由の `Authorization` ヘッダー転送、平文HTTPの403拒否、`bin/prune-expired-analyses.php` の本番DB接続をServer単体smoke testで確認済みです。web SAPIは PHP 8.5.9 / `display_errors` OFF、`max_execution_time` は 30秒 のままです。Linux版PHPではcurl・stream・DB query等の待機時間が `max_execution_time` の計測対象に含まれないため、この値を `OPENAI_TIMEOUT_SECONDS = 45` と単純比較しません。通常の成功ケースが本番web request内で完了することは確認済みで、意図的なprovider timeout / fault injectionはIssue #13の完了条件に含めていません。timeout実測（`OPENAI_TIMEOUT_SECONDS = 45` の決定）は、配置前に本番と分離した検証専用ディレクトリで実施したものです。

## 未実装のもの

次はいずれも未実装で、後続Issueで扱います。

- rate limit、usage集計、コスト制御、installation登録のabuse対策（#5）
- `/health`（作るかどうか未決定）
- account / profile、timezone、recurrence、entitlement、広告
- 非同期job queue、Cloud Functions / Cloud Run
- デプロイ自動化（本番配置自体はIssue #13で実施済み）
