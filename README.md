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
- アプリ本体はドキュメントルート外へ配置し、`public_html`には`public/index.php`へのシンボリックリンクと`public/.htaccess`のコピーだけを置く
- `Authorization`ヘッダーは`.htaccess`のRewriteでPHPへ転送し、`public/index.php`が`REDIRECT_HTTP_AUTHORIZATION`からの受け取りにも対応する
- XServer Cronで失効データの削除（`bin/prune-expired-analyses.php`）を5分間隔で実行する。Cronの用途はこれだけである

**Issue #13でこのServer実装をXServer本番環境へ配置しました。** 本番DBへ既存migrationを適用し、本番 `.env`（`DB_*` / `ANALYSIS_FINGERPRINT_SECRET` / `OPENAI_API_KEY` / `OPENAI_TIMEOUT_SECONDS=45`）を設定し、失効データ削除の5分Cronを設定しました。HTTPS経路で `POST /v1/installations` の201、Bearer認証した `POST /v1/analyses` の実OpenAI解析200、同一 `Idempotency-Key` 再送での初回と同一response、Apache経由の `Authorization` ヘッダー転送、平文HTTPの403拒否、`bin/prune-expired-analyses.php` の本番DB接続をServer単体smoke testで確認済みです。web SAPIは PHP 8.5.9 / `display_errors` OFF、`max_execution_time` は 30秒 のままです。Linux版PHPではcurl・stream・DB query等の待機時間が `max_execution_time` の計測対象に含まれないため、この値を `OPENAI_TIMEOUT_SECONDS = 45` と単純比較しません。通常の成功ケースが本番web request内で完了することは確認済みで、意図的なprovider timeout / fault injectionはIssue #13の完了条件に含めていません。timeout実測（`OPENAI_TIMEOUT_SECONDS = 45` の決定）は、配置前に本番と分離した検証専用ディレクトリで実施したものです。

## 本番デプロイ

`v*` 形式のGitタグ（例: `v1.0.0`）をpushすると、GitHub Actions（`.github/workflows/deploy.yaml`）がそのタグの指すcommitをXServer本番環境へデプロイします。`main` へのpushやPull Requestではデプロイされません。初回の環境構築（「初回デプロイ」節）は手動で行い、以降の更新デプロイは `v*` タグのpushだけで完了します。実行履歴はGitHub Actionsのrunとして残ります。

### 配置の考え方

- ドキュメントルート（`public_html`）には公開してよいファイルだけを置きます（`public/index.php` へのシンボリックリンクと `public/.htaccess` のコピー）。
- アプリ本体は `public_html` 外の専用ディレクトリ（以下 `<app-directory>`）へ、**このpublic repositoryのgit checkout** として配置します。自動デプロイはこのcheckout上で `git fetch` と `git checkout --detach` を行い、タグの指すcommitへ切り替えます。実行時点の `origin/main` は使いません。
- `.env` と解析指示本文ファイル（既定 `config/analysis-instruction.txt`）はGit管理対象外です。`git checkout` はこれらのuntrackedファイルに触れないため、デプロイで失われません。`OPENAI_API_KEY` や `ANALYSIS_FINGERPRINT_SECRET` を含む `.env` の中身をworkflowは一切変更しません。
- 解析指示本文だけは、GitHub Secret `ANALYSIS_INSTRUCTION` に保持した本文をデプロイのたびに実行時ファイルへ書き戻します。Secretの搬送形式（Actions→SSH間はbase64）とその復元はdeploy側の責務で、アプリはSecretを直接扱いません（「環境設定」節）。

### 初回デプロイ

1. `<app-directory>` を作成し、このリポジトリをcloneします。

    ```shell
    mkdir -p <app-directory>
    cd <app-directory>
    git clone https://github.com/bvlion/JournalingPostServer.git .
    ```

    Issue #13の手動配置で `<app-directory>` に既にファイルがある場合は、その場でgit管理下へ移します（既存の `.env` ・ `config/analysis-instruction.txt` はtracked対象外なので残ります）。

    ```shell
    cd <app-directory>
    git init
    git remote add origin https://github.com/bvlion/JournalingPostServer.git
    git fetch origin
    git checkout -f main
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

3. 本番用の依存関係をインストールします（開発用を含めず `composer.lock` どおり）。`<tools-directory>` は手順2で入力したパスと同じにします。

    ```shell
    cd <app-directory>
    /opt/php-8.5.5/bin/php <tools-directory>/composer.phar install --no-dev --optimize-autoloader --classmap-authoritative
    ```

4. `.env` を配置します。`.env.example` をコピーし「環境設定」節に従って本番値を設定します。

    ```shell
    cp .env.example .env
    chmod 600 .env
    ```

5. 解析指示本文ファイルを配置します。自動デプロイがここをGitHub Secret `ANALYSIS_INSTRUCTION` の本文で毎回上書きするため、内容は初回にダミーでも構いませんが、ファイル自体は必要です。既定パスは `<app-directory>/config/analysis-instruction.txt`。

    ```shell
    cp config/analysis-instruction.example.txt config/analysis-instruction.txt
    chmod 600 config/analysis-instruction.txt
    ```

    `.env` と並べた別パスへ置く場合は、`.env` の `ANALYSIS_INSTRUCTION_FILE` にそのパスを設定し、後述の `DEPLOY_INSTRUCTION_PATH` Secretも同じパスにします。

6. `public_html` 側に公開ファイルを配置します（`index.php` はシンボリックリンク、`.htaccess` は通常ファイル）。

    ```shell
    ln -s <app-directory>/public/index.php <public_html-directory>/index.php
    cp <app-directory>/public/.htaccess <public_html-directory>/.htaccess
    ```

7. 権限を設定します（ディレクトリ755 / ファイル644 / `.env` と解析指示本文ファイルは600が目安。実行ユーザーに合わせて調整）。

8. マイグレーションを適用します。

    ```shell
    /opt/php-8.5.5/bin/php bin/migrate.php
    ```

9. 疎通確認します（「疎通確認」節）。

    ```shell
    bin/check-deploy-connectivity.sh https://<domain>
    ```

### 自動デプロイ（`v*` タグpush）

`v*` 形式のタグをpushすると、`.github/workflows/deploy.yaml` が次を実行します（本番ホスト側の処理は `bin/deploy-remote.sh`）。

1. 本番 `<app-directory>` にtracked変更が無いことを確認します。ある場合は上書き・resetせずデプロイを失敗させます。
2. pushされたタグをfetchし、そのタグが最終的に指すcommit（annotated / lightweightのいずれでも同じ）へ本番checkoutを `git checkout --detach` で切り替えます。
3. GitHub Secret `ANALYSIS_INSTRUCTION` の解析指示本文を、`DEPLOY_INSTRUCTION_PATH` の実行時ファイルへ平文で書き戻します。1行目（system prompt）または分析ルール本文が空なら失敗させます。内容はデプロイログへ出しません。
4. 専用Composerと `/opt/php-8.5.5/bin/php` で `composer install --no-dev --optimize-autoloader --classmap-authoritative` を実行します。
5. `/opt/php-8.5.5/bin/php bin/migrate.php` で未適用マイグレーションを適用します。
6. `<app-directory>/public/.htaccess` を `public_html` 側へ上書きコピーします。`index.php` のシンボリックリンクは初回作成時のものを再利用します。
7. `bin/check-deploy-connectivity.sh` で未認証の `POST /v1/analyses` が401、未定義パスへのGETが404であることを確認します。1件でも異なればworkflow全体を失敗させます。

本番の `.env` はworkflowから作成・コピー・上書き・削除しません。SSHの秘密鍵・接続先・絶対パス・本番URL・解析指示本文は、いずれもGitHub Secretsから取得し、リポジトリへは記録しません。同一本番環境への同時デプロイは `concurrency` グループで直列化します。SSH host key verificationは `DEPLOY_SSH_KNOWN_HOSTS` を使い必ず有効にし、`StrictHostKeyChecking=no` 等での無効化は行いません。

#### 通常のリリース手順

CIを通過した `main` のcommitへタグを作成してpushするだけで、そのcommitがそのまま本番へ反映されます。

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
| `DEPLOY_PATH` | 本番アプリ本体（`<app-directory>`）の絶対パス |
| `DEPLOY_COMPOSER_PATH` | 本番に配置済みの専用 `composer.phar` の絶対パス |
| `DEPLOY_PUBLIC_PATH` | 公開ディレクトリ（`public_html`）の絶対パス |
| `DEPLOY_INSTRUCTION_PATH` | 解析指示本文を書き戻す実行時ファイルの絶対パス（既定運用では `<app-directory>/config/analysis-instruction.txt`） |
| `ANALYSIS_INSTRUCTION` | OpenAIへ送る解析指示本文（プレーンテキスト。1行目 = system prompt、2行目以降 = 分析ルール本文） |
| `DEPLOY_BASE_URL` | デプロイ後の未認証疎通確認に使う本番URL（例: `https://<domain>`） |

#### 初回設定（もちおさん側の作業）

1. デプロイ専用のSSH鍵ペアを作成し、公開鍵をXServer側の対象アカウントの `~/.ssh/authorized_keys` へ登録します。
2. 上記「初回デプロイ」を実施し、`<app-directory>` をgit checkoutとして用意します。
3. 上記の必要なGitHub Secretsをすべて登録します（値の作り方は本節末尾の手順を参照）。
4. `v*` 形式のタグを作成・pushし、GitHub Actionsのデプロイが成功することを確認します。

### ロールバック

- **アプリコード**: 直前の安定タグへ戻すため、その安定commitに新しい `v*` タグ（例: `v1.0.1`）を付けてpushし、通常の自動デプロイで反映します。緊急時は本番 `<app-directory>` で直接 `git checkout --detach <安定commit>` → その時点の `composer.lock` で `composer install` を再実行します。
- **マイグレーション**: `bin/migrate.php` にロールバックはありません。データベースのバックアップからの復元、または追加のマイグレーションで是正し、適用済みファイルは変更しません。
- **公開停止**: `public_html/index.php` のシンボリックリンクを外すか、`.htaccess` を退避すれば公開を止められます。

## 未実装のもの

次はいずれも未実装で、後続Issueで扱います。

- rate limit、usage集計、コスト制御、installation登録のabuse対策（#5）
- `/health`（作るかどうか未決定）
- account / profile、timezone、recurrence、entitlement、広告
- 非同期job queue、Cloud Functions / Cloud Run
