# 本番実行環境

## 前提

JournalingPostServerは、`BvlionBatch5`・`holidays-webhook-server`と同じXServerのレンタルサーバーへ配置する前提で構成しています。実行環境の調査結果は`BvlionBatch5`の`docs/production-environment.md`と共通です。

本番ドメイン・本番`.env`・本番DB・cronへの配置・設定は行っていません。timeout実測（「本番timeout（`OPENAI_TIMEOUT_SECONDS`）の決定」）だけは、本番配置とは分離したアカウント内の検証専用ディレクトリで、本番の`public_html`・DB・cronに触れずに実施しました。以下は「この構成が将来そのまま載せられる」ことを示すための前提の記録です。

ここに記載するのは実行環境そのものの制約だけです。`BvlionBatch5`固有の運用判断（同プロジェクトが`/health`を作らないことなど）は、JournalingPostServerの制約として持ち込みません。

## PHP

- HTTP実行環境ではPHP 8.5系を使用する。
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
- ドキュメントルートへ配置するのは、`public/index.php`へのシンボリックリンクと、`public/.htaccess`をコピーした通常ファイルだけである。
- ドキュメントルート内のシンボリックリンク経由でも、PHPの`__DIR__`は実体側のディレクトリで解決されるため、`public/index.php`はコード変更なしで利用できる。
- 実際のアカウント名、ドメイン、絶対パス、認証情報はリポジトリへ記録しない。

## Apache

- `.htaccess`とRewriteを利用できる。
- `Authorization`ヘッダーは追加設定なしではPHPへ到達しない。`public/.htaccess`のRewriteで`HTTP_AUTHORIZATION`へ転送する。これはHosted APIの匿名installation認証（`Authorization: Bearer <API key>`）の前提である。
- 転送値は`index.php`への内部リダイレクトを経て`REDIRECT_HTTP_AUTHORIZATION`として届くことがある。`public/index.php`が両方を受け取れるようにしている。本番配置後は、`POST /v1/analyses`が`401 unauthorized`にならないことで転送が効いているか確認できる。
- Hosted APIはHTTPSでのみ提供する。Bearer API keyとJournalEntry本文が平文で流れないようにするためである。XServerの無料独自SSLでドメインにHTTPSを有効化する。`public/.htaccess`は平文HTTPのrequestをHTTPSへリダイレクトせず、Apache側で拒否する（`%{HTTPS}`が`on`でなければ`403`）。リダイレクトしてもrequestに含むBearer API keyとJournalEntry本文は既に平文で送信済みであり、AndroidもHTTPからのリダイレクト追従を行わず最初からHTTPSへ直接接続する（[Hosted解析API契約](hosted-analysis-api.md)）。配置時に、平文HTTPの`POST /v1/analyses`が処理されず拒否されることを確認する。

## 外部通信（OpenAI）

- サーバーからOpenAI（`https://api.openai.com/v1/responses`）へのHTTPS outboundが必要である。AI解析はここでだけ外部通信する。
- 呼び出しはcurlで行い、OpenAI SDKは追加しない（`composer.json`の`ext-curl`）。TLSは必須で、平文HTTPへのリダイレクト追従はしない。
- `.env`に`OPENAI_API_KEY`（OpenAIのAPI key）と`OPENAI_TIMEOUT_SECONDS`（呼び出しのtimeout秒数、正の整数。実測により `45`）を設定する。未指定・空・`OPENAI_TIMEOUT_SECONDS`が正の整数でない場合、秘密値を含めずに起動を失敗させる。
- `OPENAI_API_KEY`の実値はリポジトリ・Issue・PR・デプロイログ・通常ログ・例外メッセージ・error responseへ出さない。
- `store: false`はServerが後からResponseを取得しないための設定であり、OpenAI側の全データ保持をゼロにする設定ではない。標準のAPI利用ではabuse monitoring logsにprompt / responseが最大30日保持され得る（API input / outputはデフォルトではmodel学習に使われない）。`/v1/responses`はZero Data Retention（ZDR）対象だが、ZDRはOpenAIの承認・設定が必要で、現在の実装はZDR有効を前提にしない。ZDR未設定では対応modelのextended prompt cachingによるprovider側の一時的なapplication stateが存在し得る。詳細は[Hosted解析API契約](hosted-analysis-api.md)の「OpenAI側のデータ保持」。ZDRを有効化するかは配置時に判断する（未設定）。

### 本番timeout（`OPENAI_TIMEOUT_SECONDS`）の決定

XServer上の検証ディレクトリ（本番配置とは分離）で、PR #10のproduction実装（`OpenAiAnalyzer` / `CurlResponsesTransport`）をそのまま使い、実OpenAI Responses APIへ接続して測定した。生の数値と条件はIssue #4のコメントに記録している。

- 成功応答の所要時間は 1〜200 entry（payload 約3.6 KB〜約404 KiB）で 2.2〜4.3 秒。入力サイズにほぼ依存しない（`gpt-5.6-luna` + reasoning `none`）。
- 全成功応答が `status = completed` かつ strict schemaの7項目を満たした。
- サンプルは短時間内の少数回。高パーセンタイル・時間帯変動は未測定。
- 意図的なOpenAI側timeoutは発生させていない。

結論: 同期HTTPは成立する。`OPENAI_TIMEOUT_SECONDS = 45` の採用根拠は (1) 実OpenAI成功応答の実測最大が約4.3秒、(2) 少数サンプルで高パーセンタイル・時間帯変動を測れていないため十分な余裕をとる、の2点。web / FastCGI / front proxy の timeout は根拠に含めていない。Android read timeout推奨は 90 秒。

web `max_execution_time`・front proxy の read timeout は read-only では確認できていない（CLI PHP は `max_execution_time = 0` だが API は web SAPI（FastCGI）で動く。web の値はサーバーパネルのPHP設定で管理され読めるファイルには無い）。本番配置前の前提条件として、**45秒の provider timeout ＋ request 処理の overhead を、web `max_execution_time`・front proxy・Android read timeout がすべて上回ること**を確認する。この条件を満たした場合にだけ、遅いケースで Server 側の `504` が外側の timeout より先に発火し claim を保持できる。配置後の smoke test で、実サイズの `POST /v1/analyses` が web request 内で完了し遅いケースで `504` が返ることも確認する。

## Cron

- XServer Cronを利用できる。
- 失効した解析metadataと解析結果の引き渡しバッファの削除に使用する。解析requestの処理中にも削除するが、requestが来なくなった期間はそれだけでは動かないため、解析結果本文が保持期間を越えて残らないようにするにはCronが必要である。
- Cronの作業ディレクトリはアプリ本体の配置ディレクトリである保証がない。スクリプトのパスを相対で書かず、配置ディレクトリへ移動してから実行する。5分間隔で次を実行する（`<アプリ本体の配置ディレクトリ>`は「配置構成」で決めた実際のパスに置き換える。実際のパスはリポジトリへ記録しない）。

```shell
cd <アプリ本体の配置ディレクトリ> && /opt/php-8.5.5/bin/php bin/prune-expired-analyses.php
```

- Cronの用途はこの削除だけである。ServerはPush予約やscheduler機能を持たない。
- 本番Cronは未設定である。配置時に設定する。設定しない場合、解析結果本文が保持期間を越えてDBへ残る。

## 秘密情報

- `.env`はドキュメントルート外のアプリ本体ディレクトリへ置き、Web経由で読めない位置に配置する。
- `OPENAI_API_KEY`はOpenAI Responses APIのAPI keyである。実際の値はリポジトリ・Issue・PR・デプロイログへ記録しない。
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

- 本番環境へのデプロイ、およびデプロイ自動化（`deploy.yaml`相当）
- 本番の`public_html`・DB・cron・本番`.env`の変更（timeout実測は検証専用ディレクトリで実施）
- XServerサーバーパネルでの web `max_execution_time` の確認・調整（配置前に実施する）
- OpenAIアカウント側のZero Data Retention（ZDR）の申請・有効化
