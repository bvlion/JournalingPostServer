# AGENTS.md

このファイルはリポジトリ全体に適用します。

## このリポジトリの位置づけ

- JournalingPostServerは、Androidアプリ「JournalingPost」のHosted機能だけを担う最小構成のHTTP APIサーバーです。
- 日記（JournalEntry）と解析結果（AnalysisResult）の原本は端末側にあります。サーバーはそれらを恒久保存しません。
- サーバーの責務は、Androidから受け取ったJournalEntryのAI解析（Issue #4）だけです。
- 解析開始の主体は手動・自動ともAndroidです。サーバーはscheduler・Pushサーバーになりません。実行タイミング（timezone / recurrence / 自動解析スケジュール）の判断と、解析後の通知はAndroid側の責務です。
- FCMを使用しません。FCM token、`triggerAt`、ScheduledTrigger、Push予約、Server側schedulerを持ちません（Issue #3で採用しないと決定）。

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

- JournalEntry本文とpromptをデータベースへ保存しません。例外はありません。
- AnalysisResult本文をデータベースへ恒久保存しません。サーバーはどの場合も原本になりません。
- AnalysisResult本文の唯一の例外は、network応答の消失による重複AI課金を防ぐためのretry delivery bufferです。次をすべて満たす場合にだけ許可します。
  - 期限を持ち、期限切れの本文を返しません。
  - trafficの有無にかかわらず、期限切れの本文がデータベースへ残り続けない削除の仕組みを備えます。
  - 本文をidempotency / usageのmetadataと同じ行へ混ぜず、テーブルを分離します。
  - 保持期間の上限をドキュメントへ明記します。
- 上記の本文を通常ログ・例外メッセージ・テスト出力へ出しません。
- サーバーが保持してよいのは、Server内部の匿名installation識別子、Hosted API用の最小認証情報（API keyのhash）、重複防止用の短期metadata、および上記のretry delivery bufferまでです。
- 名前・メールアドレス・profile・timezone・recurrence・解析スケジュールのルール・`triggerAt`・FCM token・entitlement・広告状態を保持しません。
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
- 検証用（`make check`）のproject名も固定しません。Makefileが`journalingpostserver-check-<チェックアウトの絶対パスのhash>`を導出し、`-p`で明示します。ディレクトリ名に依存しないため、このチェックアウトの開発用projectとも、他のチェックアウトの開発用projectとも一致しません。`down --volumes`が開発用のcontainer・network・volumeを対象にすることはありません。
- 作業対象のcontainer・network・volumeがどのprojectのものか分からない場合は、`docker compose ls`で確認してから操作します。
- 同じDocker Desktop上で他プロジェクトのcontainer・network・volumeが動作しています。他プロジェクトのリソースへ触れません。
- 起動中の開発用Docker環境（container・network・volume）を、利用者の明示的な許可なしに再作成・削除しません。
- 開発用の`database` volumeを、利用者の明示的な許可なしに削除しません（`docker compose down --volumes`を無断で実行しません）。
- 実`.env`、実データベース、AI providerなど実外部サービスへ、利用者の明示的な許可なしに接続しません。
- 通常の検証には`make check`を使用します。`make check`は検証専用のCompose projectだけを使い、開発用のcontainer・network・volume・host portには触れません。
- 上記について判断できない場合は、実行せず作業を止めて確認します。

## 本番環境・SSHの安全ルール

- AI agent（Claude Code、Codex、ChatGPTその他の自動実行主体）は、XServerを含むいかなるremote hostに対しても`ssh` / `scp` / `sftp` / remote shellを伴う`rsync`等を実行しません。利用者からremote環境での調査・測定・作業を依頼された場合でも、AI agentが直接接続する許可とは解釈しません。
- AI agentは、既存の`~/.ssh`、SSH private key、`ssh-agent`、`SSH_AUTH_SOCK`、ControlMaster socket、SSH config等のローカルSSH認証資材を、remote接続のために参照・利用しません。既存認証情報が利用可能であること自体を接続許可とみなしません。
- remote環境で必要な操作は、AI agentが実行コマンドと確認観点を提示し、利用者が自分でSSH接続して実行します。AI agentは、利用者から秘密値や本番固有値を除いた実行結果を受け取って次の判断を行います。
- 一時SSH鍵が必要な場合も、AI agentは作成・登録・使用・削除を直接行いません。必要な手順とコマンドを利用者へ提示し、鍵の生成、XServer側への公開鍵登録、接続、失効・削除は利用者が実行します。
- AI agentはSSH鍵、`authorized_keys`、known_hosts、SSH configその他の認証・接続設定を変更・削除・ローテーションしません。変更が必要な場合は理由と手順を提示し、利用者が実行します。
- 利用者の明示的な指示がない限り、XServer本番環境のファイル・データベース・cron・秘密情報へ触れません。本番デプロイを勝手に実行しません。
- 実secretを含む`.env`等のファイルについて、AI agentが値を推測・補正・自己修復して書き換えることを禁止します。形式不正や読み込み失敗を検知した場合は、秘密値を表示せずに作業を止め、利用者が安全に作り直せるコマンドを提示します。
- 本番ホスト名、SSH接続先、アカウント名、実ドメイン、絶対パスその他の本番固有値をPublicなIssue、PR、commit message、テスト、ログへ記録しません。検証結果をGitHubへ残す場合は、本番固有値を一般化・伏字化してから記録します。

## Public運用

- 本番値、個人情報、秘密情報をリポジトリへ記録しません。
- Issue、PR、テスト、fixture、ログ、SQLにも実データを含めません。
- サンプル、fixture、テストデータには、実データから生成していない架空の値を使用します。

## 報告

報告は日本語で行います。記録する価値のある内容だけをGitHubへ残し、状態を見れば分かることを重複して書きません。

### GitHubへ残すもの

- reviewの指摘に対する判断（対応する / 対応しない、およびその理由）。
- 非自明な修正理由。なぜその実装を選んだか、他の案を採らなかった理由。
- 後から参照する価値がある検証結果。再現手順、実際の出力、対比した挙動など。

これらは、その指摘に対応するreview threadへ返信として残します。threadで完結する内容を、別途PR commentへ重複して書きません。PR commentは、特定のthreadに紐づかない判断経緯や、PR全体にかかる注意事項を残す場合にだけ使用します。

### GitHubへ残さないもの

- CI結果、`mergeable` / `mergeStateStatus`、unresolved threadの件数、head SHAなど、GitHubを見れば分かる状態。
- 指示された作業を実施しただけの事実（「指示されたので修正しました」「テストが通りました」等）。

判断や検証結果の裏付けとしてCI結果へ言及する場合は、run URLなど参照先を示す形にとどめます。

### チャットへの報告

- 作業完了の報告は、PRを確認できる程度に簡潔にします。GitHubへ残した内容をチャットへ再掲しません。
- ただし、次のいずれかに該当する場合は、チャットで具体的に報告します。
  - 作業が完了せず止まっている（blocker）。
  - 利用者の判断・承認が必要である。
  - 事故、想定外の中断、破壊的操作が発生した、または発生しうる。
