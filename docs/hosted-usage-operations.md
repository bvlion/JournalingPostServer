# Hosted利用制御の運用

## 配置前の設定

本変更の本番接続・migration・Cron登録は運用者が行います。AI agentはSSH接続しません。Androidの変更はJournalingPost #86の対象です。旧AndroidはanalysisDateとIntegrity tokenを送信しないため、本変更と同時に利用可能な版へ更新する必要があります。

1. Play Consoleで対象アプリとGoogle Cloud projectを関連付け、Play Integrity APIを有効にします。同じprojectでサービスアカウントを用意します。[Googleの設定手順](https://developer.android.com/google/play/integrity/setup)に従ってください。
2. サービスアカウントJSONを公開ディレクトリとGit管理の外に配置し、PHP実行ユーザーだけが読めるようにします。`PLAY_INTEGRITY_CREDENTIALS_FILE`へそのパス、`PLAY_INTEGRITY_PACKAGE_NAME`へ対象アプリの正式なpackage nameを設定します。JSONや鍵をログ・GitHubへ出しません。OAuthにはPHPのOpenSSL拡張とcurlを使います。
3. 運用者が`php bin/migrate.php`を実行します。追加テーブルとanalysis_requestsの対象日列を作るだけで、既存の成功日や利用記録を推測して移行しません。旧版の処理が終了した状態で切り替えます。
4. 既存の`bin/prune-expired-analyses.php`の5分Cronを継続します。bufferの30分失効・最大35分での削除に加え、対象外の成功日、35日を超えたprovider callも削除します。Cron失敗を監視し、失敗時は運用者が復旧してください。

設定欠落・Googleの通信障害時は登録を503で拒否します。既存Bearer認証による解析やDB専用CLIはIntegrity設定・サービスに依存しません。登録に必要なGoogle向けHTTPS通信先は`oauth2.googleapis.com`と`playintegrity.googleapis.com`です。OAuth発行と復号を各20秒で打ち切り、自動的な再送はしません。

Standard tokenのGoogle側再利用防止、5分freshness、登録試行に結び付いたrequestHashを使用します。正規アプリの再インストール等で新しいtokenを取得する再登録は許容します。追加の人物識別子・IP制限はありません。

## 利用量と推定原価

cost metadataの定期削除は35日になる5分前から対象にします。5分Cronが正常に動く前提で、削除待ち時間を含めても保持が35日を超えないためです。

以下のコマンドは配置先を作業ディレクトリとして運用者が実行します。本番CLIには確認済みruntime（例：`/opt/php-8.5.5/bin/php`）を使います。

```sh
php bin/hosted-usage.php show
php bin/hosted-usage.php show <Support-ID>
```

保持期間内の全体またはinstallation単位のprovider call数、usage不明件数、model別token量をJSONで出力します。成功済み日数とは別の値です。provider callの記録日時は、providerの応答または結果不明を確認した後、月次処理と排他してDBへ記録したUTC日時です。前月末に開始して月次処理後に記録されたcallは当月分となり、次回集計へ入ります。未到達が確定した通信失敗はcallへ数えず、応答を得たHTTPエラーと送信後の結果不明はcallへ数えます。buffer再送・429・検証拒否はcallを増やしません。HTTP処理が強制終了して記録処理まで到達しない場合のcallは、このServerの記録だけでは把握できません。請求確定額はprovider側でも照合します。

modelとusageはprovider応答から取得できた値だけ記録します。不明値はNULLであり0ではありません。本文、request、response、promptは保存しません。[Responses APIのusage定義](https://developers.openai.com/api/reference/cli/resources/responses/methods/create)に従い、cached inputはinputの内数です。

原価を換算する場合、`PROVIDER_PRICES_FILE`にmodel名をキーとした非公開JSONのパスを設定します。`input`、`cached_input`、`output`はUSD/百万tokenです。以下は仕様説明用の架空単価であり実価格ではありません。

```json
{"example-model":{"input":1.0,"cached_input":0.1,"output":2.0}}
```

公式価格・契約を運用者が確認し、応答のmodel名に対応する単価を設定します。単価・usage・cached量のいずれかが不明なmodel群のestimated_usdはNULLです。既知のtoken集計と不明call件数は併記します。価格変更や特別な課金条件がある期間はこの単価換算と請求額が一致しないため、月次出力と使用した単価は運用者側で保管して確認します。

## 月次出力と削除

usageと単価が分かるcallだけの推定額はknown_usage_estimated_usdへ併記します。estimated_usdがNULLのとき、この部分額を全callの原価とは扱いません。

```sh
php bin/hosted-usage.php monthly
```

JSTの前月1日00:00以上・当月1日00:00未満の全体利用サマリーを標準出力へ出し、出力・flush成功後に対象の個別metadataを削除します。出力失敗時は削除しません。サマリーはServer DBへ保存しません。stdoutが失われた後の復元はできないため、運用者がSSH端末から非公開の保存先へ出力を受け取ります。出力後にDB commitが失敗した場合は、再実行で同じ集計が出ることがあります。

月初（JST）の運用作業として必ず実施してください。自動実行する場合は月初Cronの出力先を非公開ファイルにし、SSHから確認・回収後に運用者がファイルを削除します。実配置パスはリポジトリへ記載しません。月次処理を実行し損ねても独立した5分Cronが35日超過分を削除するため、後日の集計が欠けることがあります。Notion連携は本リポジトリの対象外です。

## buffer期限後の個別解除

通常お問い合わせでSupport IDと対象日を受け取ります。Support IDはAPI keyのSHA-256、小文字64桁hexそのものです。API key平文は受け取りません。

```sh
php bin/hosted-usage.php unlock <Support-ID> <yyyyMMdd>
```

直近7日の範囲内に限り、該当installationと対象日の成功記録と期限切れrequestだけを削除します。実行中requestや有効な30分bufferがあれば拒否します。他の日・installation・cost記録は変更しません。成功状態の解除は元に戻す操作を用意していないため、対象を確認してから実行します。公開unlock APIや専用サポート導線はありません。
