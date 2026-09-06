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
- アカウントホーム配下かつドキュメントルート外に、デプロイ専用ルート`<deploy-root>`を1つ置く。構成は`<deploy-root>/repo`（public repository `https://github.com/bvlion/JournalingPostServer.git` のgit clone）、`<deploy-root>/shared/.env`（恒久的な秘密設定）、`<deploy-root>/releases/<tag>/`（タグごとの完成リリース。`bootstrap`・`src`・`bin`・`database`・`resources`・`vendor`・`composer.*`・`.env` symlink・解析指示本文ファイルを含む）、`<deploy-root>/current`（公開中リリースへのsymlink）、`<deploy-root>/previous`（直前のデプロイ開始時に稼働していたリリースへのsymlink。rollbackの既定の戻し先）。詳細は`README.md`「本番デプロイ」。
- `v*`タグpushの自動デプロイは、新しい`releases/<tag>/`を完成させてから`current`を原子的に切り替える。`shared/.env`はデプロイが読み書きせず、各リリースへ`.env` symlinkとして貼るだけ。
- 解析指示本文は実行時のプレーンテキストファイル（既定 `config/analysis-instruction.txt`）から読む。デプロイが各リリース内のこのパスへ GitHub Secret から復元する。旧 `.php` 形式も含めて Git 管理対象外。`config/analysis-instruction.example.txt` は架空値のひな形で、実データを含まない。
- ドキュメントルートへ配置するのは、`<deploy-root>/current/public/index.php`へのシンボリックリンクと、そこからコピーした`public/.htaccess`の通常ファイルだけである。
- ドキュメントルート内のシンボリックリンク経由でも、PHPの`__DIR__`は実体側のディレクトリで解決されるため、`current`を切り替えると次のリクエストから新リリースが読まれる。`public/index.php`はコード変更なしで利用できる。
- プライバシーポリシーの公開ページ（`GET /privacy-policy`）は同じ`public/index.php`が処理する。本文`resources/privacy-policy.md`は各リリースへ`git checkout`で同梱されるため、追加の配置作業や事前生成は不要である。AndroidアプリとPlay Consoleは`https://<本番ドメイン>/privacy-policy`を参照する。
- 実際のアカウント名、ドメイン、絶対パス、認証情報はリポジトリへ記録しない。

## Apache

- `.htaccess`とRewriteを利用できる。
- `Authorization`ヘッダーは追加設定なしではPHPへ到達しない。`public/.htaccess`のRewriteで`HTTP_AUTHORIZATION`へ転送する。これはHosted APIの匿名installation認証（`Authorization: Bearer <API key>`）の前提である。
- 転送値は`index.php`への内部リダイレクトを経て`REDIRECT_HTTP_AUTHORIZATION`として届くことがある。`public/index.php`が両方を受け取れるようにしている。Issue #13の本番配置後smoke testで、Bearer認証した`POST /v1/analyses`が`401 unauthorized`にならず`200`を返すことを確認し、Apache経由の`Authorization`転送が成立している。
- Hosted APIはHTTPSでのみ提供する。Bearer API keyとJournalEntry本文が平文で流れないようにするためである。XServerの無料独自SSLでドメインにHTTPSを有効化する。`public/.htaccess`は平文HTTPのrequestをHTTPSへリダイレクトせず、Apache側で拒否する（`%{HTTPS}`が`on`でなければ`403`）。リダイレクトしてもrequestに含むBearer API keyとJournalEntry本文は既に平文で送信済みであり、AndroidもHTTPからのリダイレクト追従を行わず最初からHTTPSへ直接接続する（[Hosted解析API契約](hosted-analysis-api.md)）。Issue #13の本番配置後smoke testで、平文HTTPのHosted requestが処理されず`403`で拒否されることを確認した。
- この平文HTTP拒否は`.htaccess`全体に効くため、プライバシーポリシーページ（`GET /privacy-policy`）もHTTPSでのみ配信される。Play Consoleとリンク先URLはいずれも`https://`で登録する。

## 外部通信（OpenAI）

- サーバーからOpenAI（`https://api.openai.com/v1/responses`）へのHTTPS outboundが必要である。AI解析はここでだけ外部通信する。
- 呼び出しはcurlで行い、OpenAI SDKは追加しない（`composer.json`の`ext-curl`）。TLSは必須で、平文HTTPへのリダイレクト追従はしない。
- `.env`に`OPENAI_API_KEY`（OpenAIのAPI key）と`OPENAI_TIMEOUT_SECONDS`（呼び出しのtimeout秒数、正の整数。実測により `45`）を設定する。未指定・空・`OPENAI_TIMEOUT_SECONDS`が正の整数でない場合、HTTPアプリ（`bootstrap/config.php`）は秘密値を含めずに起動を失敗させる。DBだけを使うCLI（`bin/migrate.php`・`bin/prune-expired-analyses.php`）はこれらを検証しない（「Cron」参照）。
- `OPENAI_API_KEY`の実値はリポジトリ・Issue・PR・デプロイログ・通常ログ・例外メッセージ・error responseへ出さない。
- `store: false`はServerが後からResponseを取得しないための設定であり、OpenAI側の全データ保持をゼロにする設定ではない。標準のAPI利用ではabuse monitoring logsにprompt / responseが最大30日保持され得る（API input / outputはデフォルトではmodel学習に使われない）。`/v1/responses`はZero Data Retention（ZDR）対象だが、ZDRはOpenAIの承認・設定が必要で、現在の実装はZDR有効を前提にしない。ZDR未設定では対応modelのextended prompt cachingによるprovider側の一時的なapplication stateが存在し得る。ZDRを有効化するかは今後の運用判断とする。詳細は[Hosted解析API契約](hosted-analysis-api.md)の「OpenAI側のデータ保持」。

### 本番timeout（`OPENAI_TIMEOUT_SECONDS`）の決定

XServer上の検証ディレクトリ（本番配置とは分離）で、PR #10のproduction実装（`OpenAiAnalyzer` / `CurlResponsesTransport`）をそのまま使い、実OpenAI Responses APIへ接続して測定した。生の数値と条件はIssue #4のコメントに記録している。

- 成功応答の所要時間は 1〜200 entry（payload 約3.6 KB〜約404 KiB）で 2.2〜4.3 秒。入力サイズにほぼ依存しない（`gpt-5.6-luna` + reasoning `none`）。
- 全成功応答が `status = completed` かつ測定時点のstrict schema（7項目）を満たした。現在のschemaは5項目である。
- サンプルは短時間内の少数回。高パーセンタイル・時間帯変動は未測定。
- 意図的なOpenAI側timeoutは発生させていない。

結論: 同期HTTPは成立する。`OPENAI_TIMEOUT_SECONDS = 45` の採用根拠は (1) 実OpenAI成功応答の実測最大が約4.3秒、(2) 少数サンプルで高パーセンタイル・時間帯変動を測れていないため十分な余裕をとる、の2点。web / FastCGI / front proxy の timeout は根拠に含めていない。Android read timeout推奨は 90 秒。

web `max_execution_time` は本番サーバーパネルで **30秒**（PHP 8.5.9 / `display_errors` OFF）。Issue #13 では 30秒 のまま維持した。Linux版PHPでは system call・stream operation・DB query 等の待機時間が `max_execution_time` の計測対象に含まれないため、OpenAI 呼び出し（curl / socket 待ち）や DB query の待機は 30秒 の対象外であり、この値を `OPENAI_TIMEOUT_SECONDS = 45` と単純比較して変更要否を判断しない。

実HTTP経路の wall-clock 側の上限（XServer の Web / FastCGI / front proxy 制約）については、Issue #13 の本番配置後 smoke test で **通常の成功ケースが本番 web request 内で完了し、外側の timeout で先に切られないこと**を確認した。遅いケースで Server 側の `504`（claim 非解放）が外側 timeout より先に発火することの実証（意図的な provider timeout / fault injection）は、Issue #13 の完了条件には含めない。

## SSHとデプロイ

- XServerはSSH接続を利用できる。デプロイ専用のSSH鍵ペアを1つ作成し、公開鍵を対象アカウントの`~/.ssh/authorized_keys`へ登録する。
- `v*`形式のタグをpushすると、GitHub Actions（`.github/workflows/deploy.yaml`）がこの鍵でXServerへSSH接続し、`bin/deploy-remote.sh`を実行する。処理は「新`releases/<tag>/`をclone → `shared/.env` symlink → 解析指示本文をSecretから復元 → `composer install --no-dev` → `bin/migrate.php` → `bin/check-config.php`で起動検証 → 稼働中リリースを`previous`へ記録 → `public/.htaccess`反映 → `current`を原子的に切替 → 切替後スモークチェック（失敗時は`previous`へ自動復帰） → 古いリリースを整理（`current`と`previous`は保持）」。
- リリース対象は`origin/main`から到達可能な（PR・CI・mergeを経た）commitに限る。未マージのcommitを指すタグは、workflow（`Verify the tag is on main`）と`bin/deploy-remote.sh`の両方で拒否する。
- 通常のリリース更新は稼働中リリースを含んで前進する。`bin/deploy-remote.sh`は、稼働中リリースのcommitがタグのcommitの祖先であること（＝タグが稼働中の子孫）を必須にし、稼働中より古いタグも、mainには入っているが稼働中と分岐したタグ（比較不能）も拒否する。`concurrency`は同時実行の防止のみでFIFO順を保証しないため。
- 本番ホスト側の切替後スモークチェックがホストから公開URLへ到達できず結果不明のときは、GitHub Actions側の疎通確認（外部経路。`--connect-timeout`/`--max-time`で有限時間に完了する）が明確な失敗を検出した場合に、workflowが`bin/rollback-release.sh`を引数なしでSSH経由実行し、`previous`（＝そのデプロイ開始時に稼働していたリリース）へ戻す。切替済みの不良リリースが`current`に残らないようにするため。
- コード側の問題が後から判明した場合は、利用者が`bin/rollback-release.sh`を本番ホストで実行し、`current`を`previous`または明示指定した過去リリースへ戻す（DBは戻さない。additive-only運用が前提）。
- SSH接続情報・絶対パス・本番URL・解析指示本文はすべてGitHub Secretsに置き、リポジトリ・Issue・PR・デプロイログへ記録しない。必要なSecretの一覧は`README.md`「必要なGitHub Secrets」。
- 秘密値はSSHのコマンドライン引数へ載せず、標準入力経由でリモートシェルへ渡す（リモートホストの`ps`へ現れないようにするため）。host key検証は`DEPLOY_SSH_KNOWN_HOSTS`で常に有効にし、`StrictHostKeyChecking=no`等での無効化はしない。
- 同一本番環境への同時デプロイはworkflowの`concurrency`グループで直列化する。
- AI agentはこのSSH接続を行わない（`AGENTS.md`「本番環境・SSHの安全ルール」）。鍵の生成・登録、Secret登録、初回セットアップ、タグpush、ロールバックはいずれも利用者が実行する。

## Cron

- XServer Cronを利用できる。
- 失効した解析metadataと解析結果の引き渡しバッファの削除に使用する。解析requestの処理中にも削除するが、requestが来なくなった期間はそれだけでは動かないため、解析結果本文が保持期間を越えて残らないようにするにはCronが必要である。
- `bin/prune-expired-analyses.php`は`DB_*`だけを必要とする（`bootstrap/database-config.php`）。`OPENAI_API_KEY`の失効対応などで`OPENAI_*` / `ANALYSIS_FINGERPRINT_SECRET`を空にしても、また解析指示本文が供給されていなくても、このCronは起動でき、失効した本文の削除保証を維持する。
- Cronの作業ディレクトリは配置ディレクトリである保証がない。スクリプトのパスを相対で書かず、公開中リリースを指す`current` symlink経由で移動してから実行する。リリースを切り替えても追従する。5分間隔で次を実行する（`<deploy-root>`は「配置構成」で決めた実際のパスに置き換える。実際のパスはリポジトリへ記録しない）。

```shell
cd <deploy-root>/current && /opt/php-8.5.5/bin/php bin/prune-expired-analyses.php
```

- Cronの用途はこの削除だけである。ServerはPush予約やscheduler機能を持たない。
- Issue #13 の本番配置で、この削除Cronを5分間隔で設定済みである。`bin/prune-expired-analyses.php` を本番DBへ接続して手動相当で実行し、正常終了することも確認した。リリースディレクトリ方式へ移行する際は、Cronの`cd`先を`<deploy-root>/current`へ更新する。

## 秘密情報

- `.env`はドキュメントルート外の`<deploy-root>/shared/.env`へ置き、Web経由で読めない位置に配置する。各リリースへは`.env` symlinkとして貼る。解析指示本文ファイル（既定 `config/analysis-instruction.txt`）は各リリース内に置く。
- `OPENAI_API_KEY`はOpenAI Responses APIのAPI keyである。実際の値はリポジトリ・Issue・PR・デプロイログへ記録しない。
- OpenAIへ送る解析指示本文（system promptと分析ルール本文）は非公開値である。GitHub Secret `ANALYSIS_INSTRUCTION` にprompt本文だけを保持する（PHPコードやファイル全体は入れない）。`v*`タグpushの自動デプロイ（`bin/deploy-remote.sh`）が、この本文を新リリース内の`config/analysis-instruction.txt`（`DEPLOY_INSTRUCTION_RELPATH`。既定はアプリの既定パスと一致）へプレーンテキストで復元する。稼働中リリースのファイルには触れないため、内容が不正でも稼働中には影響しない。Actions→SSH間はbase64で搬送するが、アプリはSecretも搬送形式も扱わない。1行目（空白のみを含む）または分析ルール本文が空ならデプロイを失敗させ、`bin/check-config.php`がアプリと同じ`bootstrap/config.php`で切替前に再検証する。実際の内容はリポジトリ・Issue・PR・デプロイログ・通常ログ・error responseへ記録しない。ファイルが無い・分析ルール本文が無い場合は、内容を出力せずに起動を失敗させる。
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

- 意図的な provider timeout / fault injection の実証（遅いケースで Server 側 `504` が外側 timeout より先に発火することの確認。Issue #13 の完了条件外）
- AI agent による本番環境への接続・デプロイ実行。デプロイ機構の実装と、production非接続で確認できる範囲の検証（`make check`、deploy scriptの構文・shellcheck、release作成/切替/失敗時挙動のローカル模擬）はrepository側で行い、鍵の生成・Secret登録・初回セットアップ・タグpush・ロールバックは利用者が実行する（`AGENTS.md`）。
