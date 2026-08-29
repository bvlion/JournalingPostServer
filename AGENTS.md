# AGENTS.md

このファイルはリポジトリ全体に適用します。

## このリポジトリの位置づけ

- JournalingPostServerは、Androidアプリ「JournalingPost」のHosted機能だけを担う最小構成のHTTP APIサーバーです。
- 日記（JournalEntry）と解析結果（AnalysisResult）の原本は端末側にあります。サーバーはそれらを恒久保存しません。
- サーバーの責務は、Push予約に基づくFCM送信（Issue #3）と、受け取ったJournalEntryのAI解析（Issue #4）だけです。

## 技術方針

- WebアプリケーションフレームワークにはSlim 4を使用します。
- データベースアクセスにはPDOを使用します。
- APIはHTTPリクエスト内で処理を完了する同期処理を第一候補とします。ただしこれは恒久的な制約ではありません。Issue #4で実際のAI処理時間やXServer / HTTPの制約により同期処理が成立しないと実測できた場合に限り、非同期化を検討します。
- 不要な抽象化を導入しません。
- DIコンテナを導入しません。
- 基底Repositoryを導入しません。
- 過剰なClean Architectureを導入しません。
- ORMを導入しません。
- 本番はDocker化しません。DockerはローカルPCの開発・検証でのみ使用します。

## データの取り扱い

- JournalEntry本文、prompt、AnalysisResult本文をデータベースへ保存しません。
- 上記の本文を通常ログ・例外メッセージ・テスト出力へ出しません。
- サーバーが保持してよいのは、匿名installation識別子、FCM token、Hosted API用の最小認証情報、Push予約の`triggerAt`、重複防止用の短期metadataまでです。
- 名前・メールアドレス・profile・timezone・解析スケジュールのルール・entitlement・広告状態を保持しません。
- 将来必要になりそうなテーブルを先回りして作成しません。テーブルは必要になったIssueで追加します。

## 判断と実装

- 既存リポジトリや具体的な実装が情報源として示された場合は、対象コードを確認してから判断します。
- 対象コードを確認できない場合は、その旨を明示します。
- 対象コードから確認した事実と一般論を区別し、混同しません。
- 将来の拡張を想定した先行実装は行いません。
- Issueの完了条件を満たす最小差分で実装します。
- 同一Issueの依頼範囲を、類似箇所へ勝手に横展開しません。

## ブランチとPR

- 1 Issue・1 branch・1 PRを原則とします。
- 1つのPRへ複数Issueの変更を混在させません。
- ユーザーの明示的な承認を得るまでPRをマージしません。

## Docker運用の安全ルール

- 開発用（`compose.yaml`）のCompose project名は固定しません。Composeがチェックアウト先のディレクトリ名から導出した名前をそのまま使います。別のcloneやgit worktreeが自然に別projectへ分離され、既存の開発環境を共有・再作成しないためです。`compose.yaml`へ`name:`を追加しません。
- 検証用（`make check`）のproject名も固定しません。Makefileが、Composeの解決した開発用project名へ`-check`を付けて導出し、`-p`で明示します。導出のため開発用と同じ名前にはならず、`down --volumes`が開発用のcontainer・network・volumeを対象にすることはありません。
- 作業対象のcontainer・network・volumeがどのprojectのものか分からない場合は、`docker compose ls`で確認してから操作します。
- 同じDocker Desktop上で他プロジェクトのcontainer・network・volumeが動作しています。他プロジェクトのリソースへ触れません。
- 起動中の開発用Docker環境（container・network・volume）を、利用者の明示的な許可なしに再作成・削除しません。
- 開発用の`database` volumeを、利用者の明示的な許可なしに削除しません（`docker compose down --volumes`を無断で実行しません）。
- 実`.env`、実データベース、FCM・AI providerなど実外部サービスへ、利用者の明示的な許可なしに接続しません。
- 通常の検証には`make check`を使用します。`make check`は検証専用のCompose project（開発用project名 + `-check`）だけを使い、開発用のcontainer・network・volume・host portには触れません。
- 上記について判断できない場合は、実行せず作業を止めて確認します。

## 本番環境の安全ルール

- 利用者の明示的な指示がない限り、XServer本番環境のファイル・データベース・cron・秘密情報へ触れません。
- 本番デプロイを勝手に実行しません。

## Public運用

- 本番値、個人情報、秘密情報をリポジトリへ記録しません。
- Issue、PR、テスト、fixture、ログ、SQLにも実データを含めません。
- サンプル、fixture、テストデータには、実データから生成していない架空の値を使用します。

## 報告

- 事故・中断・確認事項は日本語で具体的に報告します。
