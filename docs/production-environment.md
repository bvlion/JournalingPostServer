# 本番実行環境

## 前提

JournalingPostServerは、`BvlionBatch5`・`holidays-webhook-server`と同じXServerのレンタルサーバーへ配置する前提で構成しています。実行環境の調査結果は`BvlionBatch5`の`docs/production-environment.md`と共通です。

本Issue（#1）では本番環境への配置・設定・接続は一切行っていません。以下は「この構成が将来そのまま載せられる」ことを示すための前提の記録です。

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
| `curl` | 外部API呼び出し（FCM・AI providerはIssue #3 / #4） |

## MySQL

- 本番データベースはMySQL 5.7系である。
- ローカル開発でも`mysql:5.7`を使用し、本番とバージョンを揃える。
- MySQLのタイムゾーンテーブルが導入されている保証がないため、セッションタイムゾーンは名前ではなく`+00:00`形式のオフセットで設定する（`JournalingPostServer\Database\ConnectionFactory`）。

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
- `Authorization`ヘッダーは追加設定なしではPHPへ到達しない。`public/.htaccess`のRewriteで`HTTP_AUTHORIZATION`へ転送する。Issue #2で決める匿名installation認証の前提となる。

## Cron

- XServer Cronを利用できる。Push予約の到来判定（Issue #3）で使用する予定であり、本Issueでは設定しない。

## 運用上の制約

- `/health`エンドポイントは作成しない。
- 秘密情報をIssue、リポジトリ、デプロイログへ出力しない。
- JournalEntry本文・prompt・AnalysisResult本文をDBおよび通常ログへ残さない。

## 本Issueで行っていないこと

- 本番環境へのデプロイ、およびデプロイ自動化（`deploy.yaml`相当）
- XServer上のファイル・DB・cron・秘密情報の変更
