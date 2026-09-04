# JournalingPostServer

JournalingPostServerは、Androidアプリ「JournalingPost」のHosted機能を担う最小構成のHTTPサーバーです。XServer上で動作させることを前提としています。

日記（JournalEntry）と解析結果（AnalysisResult）の原本は端末側にあり、サーバーは恒久保存しません。サーバーの責務は、Androidから受け取ったJournalEntryをAI解析して同じHTTP応答で結果を返すこと（Issue #4）だけです。AI解析はOpenAI Responses APIで行います。OpenAIへ送る解析指示本文は実行環境の設定として持ちます（「環境設定」）。

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
| Markdown変換 | michelf/php-markdown（プライバシーポリシーページのHTML生成のみ） |
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

## プライバシーポリシー

淡香のプライバシーポリシーはこのサーバーから公開します。

| 項目 | 内容 |
| --- | --- |
| 本文 | `resources/privacy-policy.md`（Markdown）。更新は通常のGit履歴として残る |
| 公開URL | `GET /privacy-policy` がその本文をHTMLページとして返す |
| 変換 | リクエスト内でMarkdown→HTMLへ変換する（michelf/php-markdown）。事前生成・生成物のcommitはしない |
| 参照元 | Androidアプリと Play Console が `https://<本番ドメイン>/privacy-policy` を参照する |

Hosted API（`/v1`）とは独立で、認証もJSON契約も伴わず、DBとOpenAIにも依存しません。公開は他の経路と同じくHTTPSのみで、平文HTTPは`public/.htaccess`が拒否します。本文をブラウザで確認するには、ローカル実行中に `http://127.0.0.1:8081/privacy-policy` を開きます。

生HTMLはそのまま出力せず、リンク先も`http(s)` / `mailto` / ページ内アンカーに限ります。本文はリポジトリ管理の信頼できる内容ですが、公開ページのため多層で扱います。

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
| `ANALYSIS_INSTRUCTION_FILE` | 任意。解析指示本文ファイルのパス。未指定なら `config/analysis-instruction.txt` |

`ANALYSIS_INSTRUCTION_FILE`以外はすべて必須です。未指定または空の場合は、秘密値を含めずに該当する環境変数名を示して起動を失敗させます。`ANALYSIS_FINGERPRINT_SECRET`は32文字未満、`OPENAI_TIMEOUT_SECONDS`は正の整数でない場合も同じように失敗します。`.env.example`の`OPENAI_API_KEY`など秘密系の値はすべて実データから生成していない架空値です。`OPENAI_TIMEOUT_SECONDS=45`は実測にもとづく本番相当値です。

OpenAIへ送る解析指示本文（system promptと分析ルール本文）だけは非公開値です。`.env`ではなく実行時のプレーンテキストファイルで持ち、`bootstrap/config.php`が読み取ります。**1行目をsystem prompt、残りを分析ルール本文**として扱います（間の空行は任意）。既定のパスは`config/analysis-instruction.txt`（`.env`と同じくGit管理対象外。旧`.php`形式も含めてignore）で、`ANALYSIS_INSTRUCTION_FILE`で別パス（`.env`と並べた非公開ディレクトリなど）へ差し替えられます。ファイルが無い・分析ルール本文が無い場合は、内容を出力せずに起動を失敗させます。この指示本文はServer側の固定設定であり、Androidから指定するAPIにはしません。

**ローカルで調整する場合**は、ひな形をコピーして直接編集し、実OpenAI解析を試せます。private repositoryは不要です。

```shell
cp config/analysis-instruction.example.txt config/analysis-instruction.txt
# config/analysis-instruction.txt を編集する
```

`config/analysis-instruction.example.txt`はローカル開発・テスト・`make check`用の架空値のひな形で、実データを含みません。

**本番へ供給する場合**は、GitHub Secretに解析指示本文（プレーンテキスト）だけを保持し、deploy時にその本文を実行環境の`config/analysis-instruction.txt`（または`ANALYSIS_INSTRUCTION_FILE`のパス）へ復元します。Secretの保管形式（base64等）やその復元はdeploy側の責務で、アプリはSecretを直接扱いません。

DB接続だけを行うCLI（`bin/migrate.php`・`bin/prune-expired-analyses.php`）は`DB_*`だけを必須にし、`ANALYSIS_FINGERPRINT_SECRET` / `OPENAI_*` / 解析指示本文を検証しません（`bootstrap/database-config.php`）。API keyの失効対応などでOpenAI設定を空にしても、5分間隔の失効データ削除Cronが起動不能になって解析結果本文が保持期間を越えて残ることがないようにするためです。HTTPアプリ（`bootstrap/config.php`）は従来どおり全項目を必須検証します。

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

本番では、XServer Cronから5分間隔で実行します。Cronの作業ディレクトリは配置ディレクトリとは限らないため、移動してから実行します。公開中リリースを指す `current` symlink経由にして、リリースを切り替えても追従するようにします（`<deploy-root>`は「本番デプロイ」で決めた実際のパスに置き換えます）。

```shell
cd <deploy-root>/current && /opt/php-8.5.5/bin/php bin/prune-expired-analyses.php
```

出力は削除件数だけで、本文やinstallation識別子をログへ残しません。このCronは`DB_*`だけを必要とし、`OPENAI_*` / `ANALYSIS_FINGERPRINT_SECRET`が欠落・空でも実行できます。`current` の実体が入れ替わっても、`bin/prune-expired-analyses.php` は各リリースへ同梱されるため動作します。

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

`tests/Integration/HostedAnalysisApiTest.php`は、`Analyzer` seamでAI providerを差し替えてAPI境界（認証・検証・idempotency・error契約・保持期間）を検証します。実OpenAI Analyzerは`tests/Unit/OpenAiAnalyzerTest.php`（request構築・入力整形・応答解析・失敗時の扱い）とHosted APIの一部testが、実OpenAIへ接続しないfake transportで検証します。解析指示本文はテスト内で架空値を注入します。

### 検証環境（`make check`）

構文確認・コーディング規約・マイグレーション適用・テストを、開発環境から分離された検証専用のCompose環境で一括実行できます。

```shell
make check
```

- `make check`は`compose.check.yaml`を検証専用のCompose projectで実行します。開発用`compose.yaml`のcontainer・network・volume・host port（8081番）とは別のprojectであり、開発用の`database` / `vendor` volumeを共有しません。
- 開発用のproject名はComposeがチェックアウト先のディレクトリ名から導出します。検証用のproject名は`journalingpostserver-check-<チェックアウトの絶対パスのhash>`で、ディレクトリ名に依存しません。そのため、このチェックアウトの開発用projectとも、別のチェックアウトの開発用projectとも一致しません。hashは決定的なので`make check-clean`でも同じprojectを対象にできます（`make check` / `make check-clean` は実行前にこれを確認します）。
- 検証用appコンテナは実`.env`を読み込みません。`.env.example`の架空値だけを`/app/.env`へread-onlyで重ね、Composeの変数展開にも`--env-file .env.example`を使用します。解析指示本文も同様に、架空値の`config/analysis-instruction.example.txt`を`config/analysis-instruction.txt`へread-onlyで重ねます。
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
- アプリ本体はドキュメントルート外の `<deploy-root>/releases/<tag>/` へリリース単位で配置し、`public_html`には公開中リリースを指す `current` symlink経由の`public/index.php`へのシンボリックリンクと`public/.htaccess`のコピーだけを置く（「本番デプロイ」）
- `Authorization`ヘッダーは`.htaccess`のRewriteでPHPへ転送し、`public/index.php`が`REDIRECT_HTTP_AUTHORIZATION`からの受け取りにも対応する
- XServer Cronで失効データの削除（`bin/prune-expired-analyses.php`）を`<deploy-root>/current`から5分間隔で実行する。Cronの用途はこれだけである

**Issue #13でこのServer実装をXServer本番環境へ配置しました。** 本番DBへ既存migrationを適用し、本番 `.env`（`DB_*` / `ANALYSIS_FINGERPRINT_SECRET` / `OPENAI_API_KEY` / `OPENAI_TIMEOUT_SECONDS=45`）を設定し、失効データ削除の5分Cronを設定しました。HTTPS経路で `POST /v1/installations` の201、Bearer認証した `POST /v1/analyses` の実OpenAI解析200、同一 `Idempotency-Key` 再送での初回と同一response、Apache経由の `Authorization` ヘッダー転送、平文HTTPの403拒否、`bin/prune-expired-analyses.php` の本番DB接続をServer単体smoke testで確認済みです。web SAPIは PHP 8.5.9 / `display_errors` OFF、`max_execution_time` は 30秒 のままです。Linux版PHPではcurl・stream・DB query等の待機時間が `max_execution_time` の計測対象に含まれないため、この値を `OPENAI_TIMEOUT_SECONDS = 45` と単純比較しません。通常の成功ケースが本番web request内で完了することは確認済みで、意図的なprovider timeout / fault injectionはIssue #13の完了条件に含めていません。timeout実測（`OPENAI_TIMEOUT_SECONDS = 45` の決定）は、配置前に本番と分離した検証専用ディレクトリで実施したものです。

## 本番デプロイ

`v*` 形式のGitタグ（例: `v1.0.0`）をpushすると、GitHub Actions（`.github/workflows/deploy.yaml`）がそのタグの指すcommitをXServer本番環境へリリースします。`main` へのpushやPull Requestではデプロイされません。初回の環境構築（「初回セットアップ」節）は手動で行い、以降のリリースは `v*` タグのpushだけで完了します。実行履歴はGitHub Actionsのrunとして残ります。

### 配置の考え方（リリースディレクトリ + 公開先symlink）

XServerのアカウントホーム配下・ドキュメントルート外に、デプロイ専用のルート（以下 `<deploy-root>`）を1つ置きます。

```
<deploy-root>/
  repo/                      このpublic repositoryのgit clone（デプロイが fetch に使う）
  shared/.env                恒久的な秘密設定。デプロイは読み書きしない
  releases/<tag>/            タグごとの完成リリース（git checkout + vendor + 解析指示本文）
  current -> releases/<tag>  公開中リリースへのsymlink（初回リリース前は存在しない）
  previous -> releases/<tag> 直前のデプロイ開始時に稼働していたリリース（rollbackの既定の戻し先）
```

- ドキュメントルート（`public_html`）には2つだけを置きます。`index.php` は `<deploy-root>/current/public/index.php` へのシンボリックリンク、`.htaccess` は同じ場所からコピーした通常ファイルです。`current` を差し替えると、次のリクエストから新しいリリースが読まれます（PHPの `__DIR__` はsymlinkの実体側で解決されるため、`public/index.php` はコード変更なしで動きます）。
- デプロイ対象は、`origin/main` から到達可能な（PR・CI・mergeを経た）commitに限ります。未マージのcommitへ付けたタグはworkflowと本番ホストの両方で拒否されます。
- 通常のリリース更新は、稼働中リリースを必ず含んで前進します。タグの指すcommitが稼働中リリースのcommitの子孫であることを必須にし、**稼働中より古いタグも、mainには入っているが稼働中と分岐したタグ（比較不能）も拒否します**。意図的な過去リリースへの復帰は `bin/rollback-release.sh` で行います。
- デプロイは、対象タグのコード・`composer.lock` どおりの依存関係・解析指示本文の復元・マイグレーション適用・起動検証まで **新しい `releases/<tag>/` で完成させてから**、`current` symlinkを原子的に切り替えます。切替前に失敗した場合、稼働中のリリースには一切影響しません。
- 切替の直前に、そのとき稼働していたリリースを `previous` symlinkへ記録します。切替後の未認証チェックで異常を検出した場合は、この `previous`（＝そのデプロイ開始時に稼働していたリリース）へ自動で `current` を戻します（本番ホスト側スモークチェックが401以外を得たとき、またはそれが結果不明でGitHub Actions側の疎通確認が明確な失敗を検出したとき）。コード側の問題が後から判明した場合は、`bin/rollback-release.sh` で `previous` または明示指定した過去リリースへ `current` を戻せます。
- `shared/.env`（`DB_*` / `OPENAI_API_KEY` / `ANALYSIS_FINGERPRINT_SECRET` 等）はデプロイが作成・変更・削除しません。各リリースへは `.env` symlinkとして貼るだけです。
- 解析指示本文だけは、GitHub Secret `ANALYSIS_INSTRUCTION` に保持した本文を、デプロイのたびに **新しいリリース内の** 実行時ファイル（既定 `config/analysis-instruction.txt`）へ書き戻します。搬送形式（Actions→SSH間はbase64）と復元はdeploy側の責務で、アプリはSecretを直接扱いません（「環境設定」節）。
- 本番DBは全リリースで共有します。マイグレーションは新リリースへ戻しても旧リリースが動作不能にならない範囲（additive-only）に限ります（`database/migrations/README.md`）。

### 初回セットアップ

1. `<deploy-root>` の骨組みを作り、このリポジトリを `repo/` へcloneします。

    ```shell
    mkdir -p <deploy-root>/releases <deploy-root>/shared
    git clone https://github.com/bvlion/JournalingPostServer.git <deploy-root>/repo
    ```

2. 専用のComposerを、検証済みチェックサムで非公開ツールディレクトリへ配置します（共有Composerは使用・更新しない）。ツールディレクトリの絶対パスは、貼り付け後の対話プロンプトで入力します。

    ```shell
    (
        set -euo pipefail

        read -rp "Composerを配置する専用ツールディレクトリの絶対パスを入力してEnter: " TOOLS_DIRECTORY
        mkdir -p "$TOOLS_DIRECTORY"
        cd "$TOOLS_DIRECTORY"

        COMPOSER_SETUP_FILE="composer-setup.php"
        trap 'rm -f "$COMPOSER_SETUP_FILE"' EXIT

        EXPECTED_SIGNATURE="$(/opt/php-8.5.5/bin/php -r "echo file_get_contents('https://composer.github.io/installer.sig');")"
        if [ -z "$EXPECTED_SIGNATURE" ]; then
            echo 'ERROR: Could not retrieve the expected Composer installer signature.' >&2
            exit 1
        fi

        if ! /opt/php-8.5.5/bin/php -r "exit(copy('https://getcomposer.org/installer', '$COMPOSER_SETUP_FILE') ? 0 : 1);"; then
            echo 'ERROR: Could not download the Composer installer.' >&2
            exit 1
        fi

        ACTUAL_SIGNATURE="$(/opt/php-8.5.5/bin/php -r "echo hash_file('sha384', '$COMPOSER_SETUP_FILE');")"
        if [ "$EXPECTED_SIGNATURE" != "$ACTUAL_SIGNATURE" ]; then
            echo 'ERROR: Composer installer signature mismatch.' >&2
            exit 1
        fi

        /opt/php-8.5.5/bin/php "$COMPOSER_SETUP_FILE" --install-dir="$TOOLS_DIRECTORY" --filename=composer.phar
    )
    ```

3. `<deploy-root>/shared/.env` を用意します。`.env.example` をコピーし「環境設定」節に従って本番値を設定します。`ANALYSIS_INSTRUCTION_FILE` は設定しません（リリース内の既定パスを使うため）。

    ```shell
    cp <deploy-root>/repo/.env.example <deploy-root>/shared/.env
    chmod 600 <deploy-root>/shared/.env
    ```

4. デプロイ専用のSSH鍵ペアを作成し、公開鍵をXServer側の対象アカウントの `~/.ssh/authorized_keys` へ登録します。

5. 「必要なGitHub Secrets」をすべて登録します（秘密値を表示しない手順はPRコメントに掲載）。

6. `v*` タグを作成・pushして最初のリリースを作ります（「通常のリリース手順」）。`<deploy-root>/current` と `releases/<tag>/` が作られます。

7. 公開先を新方式へ向けます。既存の `public_html` を新しい `current` 経由へ切り替えます。

    ```shell
    ln -sfn <deploy-root>/current/public/index.php <public_html>/index.php
    cp <deploy-root>/current/public/.htaccess <public_html>/.htaccess
    ```

8. 失効データ削除Cronの作業ディレクトリを `current` 経由へ更新します（「失効データの削除」節）。

    ```shell
    cd <deploy-root>/current && /opt/php-8.5.5/bin/php bin/prune-expired-analyses.php
    ```

9. 疎通確認します（「疎通確認」節）。

    ```shell
    bin/check-deploy-connectivity.sh https://<domain>
    ```

Issue #13 の手動配置（単一ディレクトリ）からの移行手順は、PR #3 のコメントに秘密値を表示しない形でまとめています。旧ディレクトリは、新 `current` で数リリース安定するまで残しておけます。

### 自動デプロイ（`v*` タグpush）

`v*` 形式のタグをpushすると、`.github/workflows/deploy.yaml` が次を実行します（本番ホスト側の処理は `bin/deploy-remote.sh`）。

1. **main 限定ガード**: タグの指すcommitが `origin/main` から到達可能（＝PR・CI・mergeを経ている）ことを確認します。未マージ・未レビューのcommitを指すタグは、SSH接続前にworkflowを失敗させます。本番ホスト側でも `bin/deploy-remote.sh` が同じ確認を行います。
2. `<deploy-root>/repo` で `origin` と対象タグをfetchし、タグが指すcommitがworkflowの確定したcommitと一致することを確認します。
3. **順序ガード**: `current` が指す稼働中リリースのcommitが、タグの指すcommitの祖先である（＝タグが稼働中の子孫として前進している）ことを必須にします。稼働中より古いタグも、mainには入っているが稼働中と分岐したタグ（比較不能）も拒否します。`concurrency` は同時実行を防ぐだけでFIFO順を保証しないため、遅れて実行された古い／分岐タグで本番が巻き戻らないようにします。
4. `releases/<tag>/` を作り直し、`repo` からcloneしてタグのcommitへ `git checkout --detach` します。
5. `shared/.env` をリリースへsymlinkします。
6. GitHub Secret `ANALYSIS_INSTRUCTION` の解析指示本文を、リリース内の実行時ファイルへ平文で書き戻します（内容はログへ出しません）。1行目（空白のみも不可）または分析ルール本文が空なら失敗させます。
7. 専用Composerと `/opt/php-8.5.5/bin/php` で `composer install --no-dev --optimize-autoloader --classmap-authoritative` をリリースへ実行します。
8. `/opt/php-8.5.5/bin/php bin/migrate.php` で未適用マイグレーションを本番DBへ適用します。
9. `/opt/php-8.5.5/bin/php bin/check-config.php` で、アプリ本体と同じ設定読み込み（`bootstrap/config.php`）によりリリースが起動可能なことを検証します。解析指示本文の判定は実行時と完全に一致します。ここまで通ってはじめて公開先を切り替えます。
10. いま稼働しているリリースを `previous` symlinkへ記録し、`releases/<tag>/public/.htaccess` を `public_html` へコピーして、`current` symlinkを新リリースへ原子的に切り替えます。
11. `DEPLOY_BASE_URL` が設定されていれば、未認証の `POST /v1/analyses` が401であることを確認します。401でないHTTP応答が返った場合は、`previous` へ `current` を自動で戻してworkflowを失敗させます（ホストから本番URLへ到達できず結果が得られない場合は戻さず、次のGitHub Actions側チェックに委ねます）。
12. `bin/check-deploy-connectivity.sh`（GitHub Actions側）で未認証の `POST /v1/analyses` が401、未定義パスへのGETが404であることを外部経路から確認します。curlは `--connect-timeout` / `--max-time` を課し、接続後に応答が返らなくても有限時間で失敗として完了します。
13. 手順10の切替が成功したうえで手順12が明確な失敗を検出した場合は、`bin/rollback-release.sh`（引数なし）をSSH経由で実行して `previous`（＝そのデプロイ開始時に稼働していたリリース）へ `current` を戻し、workflowは失敗のままにします。
14. 過去リリースは新しい順に `DEPLOY_KEEP_RELEASES` 個（既定5）を残し、それ以外を削除します（`current` と `previous` は常に保持）。

本番の `shared/.env` はworkflowから作成・コピー・上書き・削除しません。SSHの秘密鍵・接続先・絶対パス・本番URL・解析指示本文は、いずれもGitHub Secretsから取得し、リポジトリへは記録しません。同一本番環境への同時デプロイは `concurrency` グループで直列化します。SSH host key verificationは `DEPLOY_SSH_KNOWN_HOSTS` を使い必ず有効にし、`StrictHostKeyChecking=no` 等での無効化は行いません。

#### 通常のリリース手順

CIを通過した `main` のcommitへタグを作成してpushするだけで、そのcommitがそのまま本番へリリースされます。

```shell
git tag v1.0.0
git push origin v1.0.0
```

#### 必要なGitHub Secrets

| Secret | 用途 |
| --- | --- |
| `DEPLOY_SSH_HOST` | XServerのSSH接続先ホスト |
| `DEPLOY_SSH_PORT` | SSH接続ポート |
| `DEPLOY_SSH_USER` | SSH接続ユーザー名 |
| `DEPLOY_SSH_PRIVATE_KEY` | デプロイ専用のSSH秘密鍵 |
| `DEPLOY_SSH_KNOWN_HOSTS` | 接続先のhost keyを検証するknown_hostsエントリ |
| `DEPLOY_ROOT` | デプロイ専用ルート（`<deploy-root>`）の絶対パス |
| `DEPLOY_COMPOSER_PATH` | 本番に配置済みの専用 `composer.phar` の絶対パス |
| `DEPLOY_PUBLIC_PATH` | 公開ディレクトリ（`public_html`）の絶対パス |
| `ANALYSIS_INSTRUCTION` | OpenAIへ送る解析指示本文（プレーンテキスト。1行目 = system prompt、2行目以降 = 分析ルール本文） |
| `DEPLOY_BASE_URL` | デプロイ後の未認証スモークチェック / 疎通確認に使う本番URL（例: `https://<domain>`） |

解析指示本文をリリース内の既定パス以外へ置きたい場合は、`bin/deploy-remote.sh` の `DEPLOY_INSTRUCTION_RELPATH`（リリース相対）を変更し、`shared/.env` の `ANALYSIS_INSTRUCTION_FILE` を同じ相対解決になるよう揃えます。既定のままで問題ありません。

### ロールバック

- **アプリコード**: 本番ホストで `bin/rollback-release.sh` を実行し、`current` を既存の過去リリースへ原子的に戻します。**引数を省略すると `previous`（＝直前のデプロイ開始時に稼働していたリリース）へ**、引数で版名を渡すとその版へ戻します（利用者による明示選択）。リリースディレクトリはそのまま再利用するため、`composer install` の再実行は不要です。切替後の疎通確認で明確な失敗が出た場合は、自動デプロイがこのスクリプトを引数なしでSSH経由実行し、同じ `previous` へ戻します。

    ```shell
    cd <deploy-root>/current
    DEPLOY_ROOT=<deploy-root> DEPLOY_PUBLIC_PATH=<public_html> bin/rollback-release.sh
    # 版を指定する場合: DEPLOY_ROOT=... DEPLOY_PUBLIC_PATH=... bin/rollback-release.sh v1.0.0
    ```

    `previous` が無い（初回デプロイ直後など）場合、引数なしの実行は版名の明示を求めて中止します。より新しいcommitへ「進める」形の是正は、CIとmergeを経た `main` のcommitへ新しい `v*` タグをpushします（順序ガードにより古い／分岐タグは拒否されます）。
- **マイグレーション**: `bin/migrate.php` にロールバックはありません。additive-only運用のため旧リリースは動作を続けられます。データ面の是正が必要な場合は、バックアップからの復元または追加のマイグレーションで行い、適用済みファイルは変更しません。
- **公開停止**: `public_html/index.php` のシンボリックリンクを外すか、`.htaccess` を退避すれば公開を止められます。

## 未実装のもの

次はいずれも未実装で、後続Issueで扱います。

- rate limit、usage集計、コスト制御、installation登録のabuse対策（#5）
- `/health`（作るかどうか未決定）
- account / profile、timezone、recurrence、entitlement、広告
- 非同期job queue、Cloud Functions / Cloud Run
