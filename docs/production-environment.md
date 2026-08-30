# 本番実行環境

## 前提

JournalingPostServerは、`BvlionBatch5`・`holidays-webhook-server`と同じXServerのレンタルサーバーへ配置する前提で構成しています。実行環境の調査結果は`BvlionBatch5`の`docs/production-environment.md`と共通です。

本番環境への配置・設定・接続は一切行っていません。以下は「この構成が将来そのまま載せられる」ことを示すための前提の記録です。

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
| `curl` | 外部API呼び出し（AI providerはIssue #4） |

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
- Hosted APIはHTTPSでのみ提供する。Bearer API keyとJournalEntry本文が平文で流れないようにするためである。XServerの無料独自SSLでドメインにHTTPSを有効化し、`.htaccess`で平文HTTPをHTTPSへリダイレクトする。配置時に、平文HTTPで`POST /v1/analyses`を処理しないことを確認する。

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
- XServer上のファイル・DB・cron・秘密情報の変更
