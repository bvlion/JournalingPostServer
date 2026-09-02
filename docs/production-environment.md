# 本番実行環境

## 前提

JournalingPostServerは、`BvlionBatch5`・`holidays-webhook-server`と同じXServerのレンタルサーバーへ配置する前提で構成しています。実行環境の調査結果は`BvlionBatch5`の`docs/production-environment.md`と共通です。

Issue #13で、この構成のServer実装をXServer本番環境へ配置しました。本番ドメインをHTTPSで公開し、本番`.env`・本番DB（既存migration適用済み）・失効データ削除の5分Cronを設定しました。配置前のtimeout実測（「本番timeout（`OPENAI_TIMEOUT_SECONDS`）の決定」）は、本番配置とは分離したアカウント内の検証専用ディレクトリで、本番の`public_html`・DB・cronに触れずに実施したものです。

ここに記載するのは実行環境そのものの制約と、Issue #13で確認した配置後の状態です。`BvlionBatch5`固有の運用判断（同プロジェクトが`/health`を作らないことなど）は、JournalingPostServerの制約として持ち込みません。

## PHP

- HTTP実行環境ではPHP 8.5系を使用する（本番サーバーパネルで 8.5.9 を確認済み）。
- CLIでは`/opt/php-8.5.5/bin/php`を明示して実行する。CLIの既定PHPは古い版を参照するため使用しない。
- `composer.json`の`config.platform.php`を`8.5.5`に固定し、ローカルで解決する依存関係が本番PHPと乖離しないようにする。

## PHP拡張

本構成が必要とする拡張は、HTTP・CLIの双方で利用できることを確認済みです。

| 拡張 | 用途 |
| --- | --- |
| `pdo_mysql` | MySQLへの接続 |
| `mbstring` | UTF-8文字列処理 |
| `json` | JSON入出力 |
| `openssl` | TLS通信 |
| `curl` | 外部API呼び出し（OpenAI Responses API。`OpenAi\CurlResponsesTransport`） |

## MySQL

- 本番データベースはMySQL 5.7系である。
- ローカル開発でも`mysql:5.7`を使用し、本番とバージョンを揃える。
- Serverはtimezoneやrecurrenceを解釈せず絶対時刻だけを扱うため、セッションタイムゾーンはUTC固定とする。MySQLのタイムゾーンテーブルが導入されている保証がないため、名前ではなく`+00:00`形式のオフセットで設定する（`JournalingPostServer\Database\ConnectionFactory`）。

## Composer

- Composer 2系を利用できる。
- 本番への依存関係導入には、リポジトリで管理する`composer.lock`を使用する。
- Composerは`/opt/php-8.5.5/bin/php`を明示して実行する。

## 配置構成

- ドメインのドキュメントルートは、当該ドメインの`public_html`ディレクトリである。
- アプリ本体（`bootstrap`・`src`・`bin`・`database`・`vendor`・`composer.*`・`.env`）は、アカウントホーム配下かつドキュメントルート外の専用ディレクトリへ配置する。
- 解析指示本文は実行時のプレーンテキストファイル（既定 `config/analysis-instruction.txt`）から読む。`.env` と同じ非公開ディレクトリへ置く（`ANALYSIS_INSTRUCTION_FILE` で `.env` と並べた別パスも指定できる）。旧 `.php` 形式も含めて Git 管理対象外。`config/analysis-instruction.example.txt` は架空値のひな形で、実データを含まない。
- ドキュメントルートへ配置するのは、`public/index.php`へのシンボリックリンクと、`public/.htaccess`をコピーした通常ファイルだけである。
- ドキュメントルート内のシンボリックリンク経由でも、PHPの`__DIR__`は実体側のディレクトリで解決されるため、`public/index.php`はコード変更なしで利用できる。
- 実際のアカウント名、ドメイン、絶対パス、認証情報はリポジトリへ記録しない。

## Apache

- `.htaccess`とRewriteを利用できる。
- `Authorization`ヘッダーは追加設定なしではPHPへ到達しない。`public/.htaccess`のRewriteで`HTTP_AUTHORIZATION`へ転送する。これはHosted APIの匿名installation認証（`Authorization: Bearer <API key>`）の前提である。
- 転送値は`index.php`への内部リダイレクトを経て`REDIRECT_HTTP_AUTHORIZATION`として届くことがある。`public/index.php`が両方を受け取れるようにしている。Issue #13の本番配置後smoke testで、Bearer認証した`POST /v1/analyses`が`401 unauthorized`にならず`200`を返すことを確認し、Apache経由の`Authorization`転送が成立している。
- Hosted APIはHTTPSでのみ提供する。Bearer API keyとJournalEntry本文が平文で流れないようにするためである。XServerの無料独自SSLでドメインにHTTPSを有効化する。`public/.htaccess`は平文HTTPのrequestをHTTPSへリダイレクトせず、Apache側で拒否する（`%{HTTPS}`が`on`でなければ`403`）。リダイレクトしてもrequestに含むBearer API keyとJournalEntry本文は既に平文で送信済みであり、AndroidもHTTPからのリダイレクト追従を行わず最初からHTTPSへ直接接続する（[Hosted解析API契約](hosted-analysis-api.md)）。Issue #13の本番配置後smoke testで、平文HTTPのHosted requestが処理されず`403`で拒否されることを確認した。

## 外部通信（OpenAI）

- サーバーからOpenAI（`https://api.openai.com/v1/responses`）へのHTTPS outboundが必要である。AI解析はここでだけ外部通信する。
- 呼び出しはcurlで行い、OpenAI SDKは追加しない（`composer.json`の`ext-curl`）。TLSは必須で、平文HTTPへのリダイレクト追従はしない。
- `.env`に`OPENAI_API_KEY`（OpenAIのAPI key）と`OPENAI_TIMEOUT_SECONDS`（呼び出しのtimeout秒数、正の整数。実測により `45`）を設定する。未指定・空・`OPENAI_TIMEOUT_SECONDS`が正の整数でない場合、HTTPアプリ（`bootstrap/config.php`）は秘密値を含めずに起動を失敗させる。DBだけを使うCLI（`bin/migrate.php`・`bin/prune-expired-analyses.php`）はこれらを検証しない（「Cron」参照）。
- `OPENAI_API_KEY`の実値はリポジトリ・Issue・PR・デプロイログ・通常ログ・例外メッセージ・error responseへ出さない。
- `store: false`はServerが後からResponseを取得しないための設定であり、OpenAI側の全データ保持をゼロにする設定ではない。標準のAPI利用ではabuse monitoring logsにprompt / responseが最大30日保持され得る（API input / outputはデフォルトではmodel学習に使われない）。`/v1/responses`はZero Data Retention（ZDR）対象だが、ZDRはOpenAIの承認・設定が必要で、現在の実装はZDR有効を前提にしない。ZDR未設定では対応modelのextended prompt cachingによるprovider側の一時的なapplication stateが存在し得る。ZDRを有効化するかは今後の運用判断とする。詳細は[Hosted解析API契約](hosted-analysis-api.md)の「OpenAI側のデータ保持」。

### 本番timeout（`OPENAI_TIMEOUT_SECONDS`）の決定

XServer上の検証ディレクトリ（本番配置とは分離）で、PR #10のproduction実装（`OpenAiAnalyzer` / `CurlResponsesTransport`）をそのまま使い、実OpenAI Responses APIへ接続して測定した。生の数値と条件はIssue #4のコメントに記録している。

- 成功応答の所要時間は 1〜200 entry（payload 約3.6 KB〜約404 KiB）で 2.2〜4.3 秒。入力サイズにほぼ依存しない（`gpt-5.6-luna` + reasoning `none`）。
- 全成功応答が `status = completed` かつ strict schemaの7項目を満たした。
- サンプルは短時間内の少数回。高パーセンタイル・時間帯変動は未測定。
- 意図的なOpenAI側timeoutは発生させていない。

結論: 同期HTTPは成立する。`OPENAI_TIMEOUT_SECONDS = 45` の採用根拠は (1) 実OpenAI成功応答の実測最大が約4.3秒、(2) 少数サンプルで高パーセンタイル・時間帯変動を測れていないため十分な余裕をとる、の2点。web / FastCGI / front proxy の timeout は根拠に含めていない。Android read timeout推奨は 90 秒。

web `max_execution_time` は本番サーバーパネルで **30秒**（PHP 8.5.9 / `display_errors` OFF）。Issue #13 では 30秒 のまま維持した。Linux版PHPでは system call・stream operation・DB query 等の待機時間が `max_execution_time` の計測対象に含まれないため、OpenAI 呼び出し（curl / socket 待ち）や DB query の待機は 30秒 の対象外であり、この値を `OPENAI_TIMEOUT_SECONDS = 45` と単純比較して変更要否を判断しない。

実HTTP経路の wall-clock 側の上限（XServer の Web / FastCGI / front proxy 制約）については、Issue #13 の本番配置後 smoke test で **通常の成功ケースが本番 web request 内で完了し、外側の timeout で先に切られないこと**を確認した。遅いケースで Server 側の `504`（claim 非解放）が外側 timeout より先に発火することの実証（意図的な provider timeout / fault injection）は、Issue #13 の完了条件には含めない。

## Cron

- XServer Cronを利用できる。
- 失効した解析metadataと解析結果の引き渡しバッファの削除に使用する。解析requestの処理中にも削除するが、requestが来なくなった期間はそれだけでは動かないため、解析結果本文が保持期間を越えて残らないようにするにはCronが必要である。
- `bin/prune-expired-analyses.php`は`DB_*`だけを必要とする（`bootstrap/database-config.php`）。`OPENAI_API_KEY`の失効対応などで`OPENAI_*` / `ANALYSIS_FINGERPRINT_SECRET`を空にしても、また解析指示本文が供給されていなくても、このCronは起動でき、失効した本文の削除保証を維持する。
- Cronの作業ディレクトリはアプリ本体の配置ディレクトリである保証がない。スクリプトのパスを相対で書かず、配置ディレクトリへ移動してから実行する。5分間隔で次を実行する（`<アプリ本体の配置ディレクトリ>`は「配置構成」で決めた実際のパスに置き換える。実際のパスはリポジトリへ記録しない）。

```shell
cd <アプリ本体の配置ディレクトリ> && /opt/php-8.5.5/bin/php bin/prune-expired-analyses.php
```

- Cronの用途はこの削除だけである。ServerはPush予約やscheduler機能を持たない。
- Issue #13 の本番配置で、この削除Cronを5分間隔で設定済みである。`bin/prune-expired-analyses.php` を本番DBへ接続して手動相当で実行し、正常終了することも確認した。

## 秘密情報

- `.env`はドキュメントルート外のアプリ本体ディレクトリへ置き、Web経由で読めない位置に配置する。解析指示本文ファイル（`config/analysis-instruction.txt`）も同様に配置する。
- `OPENAI_API_KEY`はOpenAI Responses APIのAPI keyである。実際の値はリポジトリ・Issue・PR・デプロイログへ記録しない。
- OpenAIへ送る解析指示本文（system promptと分析ルール本文）は非公開値である。GitHub Secretにはprompt本文だけを保持する（PHPコードやファイル全体は入れない）。deploy時にSecretの本文を実行環境の`config/analysis-instruction.txt`（または`ANALYSIS_INSTRUCTION_FILE`のパス）へプレーンテキストで復元する。Secretの保管形式（base64等）とその復元はdeploy側の責務で、アプリはSecretを直接扱わない。実際の内容はリポジトリ・Issue・PR・デプロイログ・通常ログ・error responseへ記録しない。ファイルが無い・分析ルール本文が無い場合は、内容を出力せずに起動を失敗させる。
- `OPENAI_TIMEOUT_SECONDS`は秘密値ではない。実測により `45`（「外部通信（OpenAI）」参照）。
- `ANALYSIS_FINGERPRINT_SECRET`は解析requestのfingerprintを鍵付きにするための秘密値である。32文字以上のランダム値を1度だけ生成し、本番deployを跨いで同じ値を使う。`/opt/php-8.5.5/bin/php -r 'echo base64_encode(random_bytes(48)), PHP_EOL;'`などで生成する。
- この値が変わると、保持期間（30分）内の再送が別内容と判定されて`409 idempotency_key_reuse`になる。無停止で入れ替える手順は用意していないため、必要な場合は影響が保持期間内に収まることを前提に行う。
- 実際の値はリポジトリ、Issue、PR、デプロイログへ記録しない。DBのバックアップと同じ場所へ保管しない（同じ場所にあると、鍵付きにした意味が失われる）。

## 運用上の制約

- 秘密情報をIssue、リポジトリ、デプロイログへ出力しない。
- JournalEntry本文・prompt・AnalysisResult本文をDBおよび通常ログへ残さない。解析結果は再送へ同じ結果を返すための引き渡しバッファにだけ、保持期間の間だけ残す（[Hosted解析API契約](hosted-analysis-api.md)を参照）。
- installationのAPI keyはSHA-256だけを保存し、平文を保存・出力しない。API keyは256bitの乱数であり、候補を列挙できないため鍵付きにする必要はない。
- 解析requestのfingerprintは`ANALYSIS_FINGERPRINT_SECRET`による鍵付きhashにする。requestの内容は入力空間が狭くなり得るため、素のhashではDBから候補を突き合わせられる。

## 行っていないこと

- デプロイ自動化（`deploy.yaml`相当）。本番配置自体は Issue #13 で実施済み。
- 意図的な provider timeout / fault injection の実証（遅いケースで Server 側 `504` が外側 timeout より先に発火することの確認。Issue #13 の完了条件外）
