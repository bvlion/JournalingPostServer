# Hosted解析API契約

AndroidアプリJournalingPost（`bvlion/JournalingPost`）とJournalingPostServerが共有するHTTP契約です。Issue #2で定義しました。

対応するAndroid側のIssueは`bvlion/JournalingPost#40`（Hosted解析の接続）と`#37`（AnalysisResultの端末保存）です。

## 位置づけ

JournalEntryとAnalysisResultの原本は端末にあります。Serverは解析時に対象期間のJournalEntryを受け取り、AI解析結果を同じHTTP応答で返すだけで、どちらの本文も恒久保存しません。

解析開始の主体は手動・自動ともAndroidです。実行タイミングの判断（timezone・recurrence・自動解析スケジュール）と、解析後の通知はAndroid側で行います。ServerはscheduleもPushも持ちません。

```text
Android                         Server
  JournalEntryをローカル保存
  ↓
  POST /v1/installations   →    匿名installationとAPI keyを発行
  ↓
  （解析タイミング）
  対象期間のJournalEntryを抽出
  ↓
  POST /v1/analyses        →    認証 → 検証 → idempotency → AI解析
                           ←    解析結果
  ↓
  AnalysisResultとしてローカル保存
  ↓
  必要ならAndroid側でローカル通知
```

自動解析でも同じ流れです。ServerがtriggerAtを持ってFCMでAndroidを起こす構成は採用しません（Issue #3を`not planned`でclose）。Serverが持たないのは、FCM token・`triggerAt`・ScheduledTrigger・Push予約・scheduler・timezone・recurrenceです。

AI provider実装はIssue #4、rate limit / usage / 登録endpointのabuse対策はIssue #5で扱います。

## 共通事項

| 項目 | 契約 |
| --- | --- |
| Base path | `/v1` |
| request body | `Content-Type: application/json`（UTF-8） |
| response body | `application/json; charset=utf-8` |
| 時刻表現 | RFC 3339。詳細は下記 |
| responseの時刻 | UTC・秒精度（`2026-08-29T09:00:05Z`） |
| 未知のフィールド | Serverは無視する。Android側の項目追加でServerの更新を必要としない |

Serverはtimezoneやrecurrenceを解釈しません。対象期間の計算はAndroid側の責務です。

### timestampの表記

requestのtimestampは`YYYY-MM-DDThh:mm:ss[.fff…]<offset>`だけを受け付けます。

- 区切りの`T`と、UTCを表す`Z`は大文字のみです。空白区切りは受け付けません。
- offsetは`Z`または`+09:00` / `-11:30`形式です。offsetの省略は受け付けません。
- 秒未満は1〜9桁の任意です。Serverはmicrosecond精度まで扱います。
- 存在しない暦日（`2026-02-30`、うるう年でない年の`02-29`）、範囲外の時刻（`24:00`、`:60`）、範囲外のoffset（`+24:00`、`+09:60`）は`validation_error`で拒否します。うるう秒（`:60`）は受け付けません。

Serverは受信時にUTCへ正規化します。responseのtimestampはUTC・秒精度です（`2026-08-29T09:00:05Z`）。

### 互換性の扱い

- フィールドの**追加**は互換とみなします。Androidは知らないフィールドを無視してください。
- フィールドの**削除**と**意味の変更**は非互換です。両リポジトリのIssueで合わせて変更します。

## 認証

匿名installation単位のBearer認証です。account・profile・メールアドレスは作りません。

- Serverが発行した高エントロピーのAPI key（`jpk_`＋256bitのbase64url、計47文字）を`Authorization: Bearer <API key>`で送ります。
- ServerはAPI keyのSHA-256だけを保存します。平文は登録応答でしか返しません。
- 端末が生成したUUIDなど、クライアントが値を選べる識別子を、それだけで認証情報として受け付けません。「このinstallationがHosted APIを利用してよい」ことを確認できないためです。
- Androidが保持するのはAPI keyだけです。Server内部のinstallation識別子はAPIへ出しません。Server側では、API keyを差し替えても解析requestのidempotency metadataとinstallationの対応を保てるように内部識別子が必要ですが、Androidから識別子を送る用途は無く、返せば端末側に不要な状態が増えるためです。

XServer（Apache）では`Authorization`ヘッダーが既定でPHPへ届きません。`public/.htaccess`のRewriteで転送し、`public/index.php`が`REDIRECT_HTTP_AUTHORIZATION`からの受け取りにも対応しています。

### API keyを失った場合

Serverはhashしか持たないため再発行できません。端末がAPI keyを失った場合は再登録し、新しいinstallationになります。過去のAnalysisResultは端末にあるため失われません。

### 検討して採らなかった方式

| 方式 | 採らなかった理由 |
| --- | --- |
| Android Keystoreの署名 | 鍵が端末外へ出ない強さはあるが、PHP側の署名検証・nonce・時刻ずれ対応が増える。installation単位のrate limit（#5）で足りる想定 |
| Play Integrity / App Check | 端末とアプリの正当性まで確認できるが、PHP側にGoogle依存と鍵管理が増える。#2の最小構成には重い |

登録endpoint自体のrate limitとabuse対策はIssue #5で扱います。#2の時点では登録を無制限に受け付けます。

## POST /v1/installations

匿名installationを登録し、認証情報を発行します。request bodyは不要です。

**Response 201**

```json
{
  "installation": {
    "apiKey": "jpk_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX"
  }
}
```

`apiKey`が返るのはこの応答だけです。Androidはこの値だけを保存します。

## POST /v1/analyses

対象期間のJournalEntryを解析します。認証と`Idempotency-Key`が必要です。

**Request headers**

| Header | 必須 | 内容 |
| --- | --- | --- |
| `Authorization` | 必須 | `Bearer <API key>` |
| `Content-Type` | 必須 | `application/json` |
| `Idempotency-Key` | 必須 | 端末が生成する16〜64文字の`[A-Za-z0-9_-]`。UUID v4を想定 |

**Request body**

```json
{
  "period": {
    "start": "2026-08-29T00:00:00Z",
    "end": "2026-08-29T09:00:00Z"
  },
  "entries": [
    {
      "recordedAt": "2026-08-29T01:15:00Z",
      "mood": { "emoji": "😐", "label": "ふつう" }
    },
    {
      "recordedAt": "2026-08-29T05:40:00Z",
      "mood": { "emoji": "🙂", "label": "すこし上向き" },
      "note": "架空のメモ"
    },
    {
      "recordedAt": "2026-08-29T08:05:00Z",
      "note": "架空のメモ"
    }
  ]
}
```

| フィールド | 必須 | 制約 |
| --- | --- | --- |
| `period.start` / `period.end` | 必須 | RFC 3339。`start < end` |
| `entries` | 必須 | 1〜200件 |
| `entries[].recordedAt` | 必須 | RFC 3339 |
| `entries[].mood` | 任意 | 指定する場合は`emoji`（1〜16文字）と`label`（1〜100文字）の両方が必要 |
| `entries[].note` | 任意 | 1〜2000文字。空白のみは未指定と同じに扱う |

- moodのみのentry（`note`なし）とnoteのみのentry（`mood`なし）の双方を送れます。
- Android側`JournalEntry`の`id` / `source` / `deliveryStatus` / `moodId`は解析に不要なため送りません。`moodEmoji` / `moodLabel`は記録時点のsnapshotをそのまま送ります。
- `entries`が`period`の範囲内かをServerは検証しません。対象期間の切り出しはAndroid側の責務です。
- `entries`の順序は解析上の意味を持ちませんが、idempotency判定には影響します（後述）。
- `entries`が0件の場合はAI呼び出しを行わず`validation_error`を返します。対象期間に記録が無い場合、Androidは解析requestを送りません。
- request body全体の上限は1 MiBです。

**Response 200**

```json
{
  "analysis": {
    "period": {
      "start": "2026-08-29T00:00:00Z",
      "end": "2026-08-29T09:00:00Z"
    },
    "analyzedAt": "2026-08-29T09:00:05Z",
    "entryCount": 3,
    "model": "example/analysis-model",
    "text": "..."
  }
}
```

| フィールド | 内容 |
| --- | --- |
| `period` | 解析対象期間。requestの値をUTC・秒精度へ正規化して返す |
| `analyzedAt` | 解析完了時刻 |
| `entryCount` | 解析に使ったentry数 |
| `model` | 解析に使ったAI model識別子。Issue #4が値を決める |
| `text` | 振り返り本文（プレーンテキスト） |

Android側`AnalysisResult`が必要とする「対象期間」「解析日時」「解析結果」はこの応答から作れます。「解析方法 / 種別」はAndroid側が持つ区分（Hosted / Custom Webhook）であり、Serverは指定しません。`model`は補足情報です。

振り返り本文を単一の`text`にしているのは、prompt設計（Issue #4）が構造を確定していないためです。将来の構造化はフィールド追加（互換）で行います。

## Idempotency / retry / timeout

### 契約

- `Idempotency-Key`はinstallationごとのスコープです。
- Serverは検証後のrequestを正規化してSHA-256を取り、同じkeyのrequestが同一内容かを判定します。timezone表記やキー順序の違いは同一とみなし、entryの内容・件数・順序の違いは別とみなします。
- **network timeout等での再送**は、同じ`Idempotency-Key`と同じbodyで送ります。AIは再度呼ばれません。
- **ユーザーが意図した再解析**は、新しい`Idempotency-Key`で送ります。AIが再度呼ばれます。

### 再送に対するServerの応答

| 状態 | 応答 |
| --- | --- |
| 未処理 | AI解析を実行し`200` |
| 処理中（完了していない） | `409 analysis_in_progress` + `Retry-After: 15` |
| 完了済み・保持期間内 | 初回と同じbodyを`200`で返す（AIは呼ばない） |
| 同じkeyで別内容 | `409 idempotency_key_reuse` |
| 保持期間切れ | 新しいrequestとして扱い、AI解析を実行して`200` |

完了済みの結果を返せるようにするため、Serverは解析結果本文を**引き渡しバッファ**（`analysis_deliveries`）へ保持期間の間だけ保持します。これはidempotency metadata（`analysis_requests`）とは別のテーブルで、原本ではありません。この保持がないと、responseがnetworkで失われた場合に、課金済みの解析結果を返せず再課金になります。

引き渡し済みの行を即時削除せず保持期間まで残すのは、削除するとその応答が失われた場合に同じ問題が再発するためです。保持期間の上限は削除方式によらず変わりません。

### 応答が返らなかった解析の扱い

処理中のままServerが停止しても、経過時間だけを根拠に新しいAI呼び出し権を与えません。前の処理が動き続けている保証が無く、与えると同じ解析を二重にAIへ投げるためです。

その`Idempotency-Key`は保持期間（30分）で失効するまで`409 analysis_in_progress`を返し続け、失効後は新しい解析として受け付けます。

失効後に同じkeyで新しい解析が始まった後で、古い処理が遅れて終わることがあります。この場合、古い処理は新しい解析の完了記録も引き渡しバッファも書き換えません。完了記録・バッファ書き込み・解放のいずれも、自分が取得したclaim（取得時刻が一致し、まだ完了していない行）だけを対象にします。古い結果が新しいrequestの応答として返ることはありません。

AI provider側のtimeoutを前提にした早期の復帰や、provider呼び出し自体の打ち切りは、実providerのtimeout特性を確認できるIssue #4で判断します。#2ではDB側のclaim所有条件までを契約とし、provider固有の制御を先回りして実装しません。

### timeout

- Androidの読み取りtimeoutは120秒を目安にしてください。実際の上限はIssue #4でAI providerの応答時間を実測して決めます。
- timeoutしたrequestは、`Retry-After`に従って同じ`Idempotency-Key`で再送してください。
- 保持期間（30分）を過ぎてからの再送は新しい解析になります。それより後にretryしないでください。

## Error response

すべてのエラーが同じ形です。

```json
{
  "error": {
    "code": "validation_error",
    "message": "The request does not satisfy the analysis request contract.",
    "details": ["entries[1].mood.label: must be a non-empty string."]
  }
}
```

- `code`で分岐します。`message`と`details`は原因調査用の固定文で、ユーザーへ表示する文言ではありません。
- `details`は`validation_error`のときだけ付き、フィールドパスと違反内容だけを含みます。受け取った値（JournalEntry本文）は含みません。
- 未知の`code`はHTTP statusの区分で扱ってください。
- JSONのrootがobjectかどうかで`400`と`422`を分けます。root自体がobjectでなければ`400 invalid_request`、rootはobjectでその中身が契約に反する場合は`422 validation_error`です。空のobject（`{}`）は後者、空の配列（`[]`）は前者です。

| Status | `code` | 意味 | Androidの扱い |
| --- | --- | --- | --- |
| 400 | `invalid_request` | JSONとして解釈できない、JSONのrootがobjectでない（配列・文字列・数値・`null`）、`Idempotency-Key`が欠落・形式不正 | retryしない |
| 401 | `unauthorized` | API keyが無い・不正・未登録 | 再登録を検討する |
| 404 | `not_found` | 未定義のpath | retryしない |
| 405 | `method_not_allowed` | pathに対して不正なHTTP method | retryしない |
| 409 | `analysis_in_progress` | 同じkeyの解析が処理中 | `Retry-After`後に同じkeyで再送 |
| 409 | `idempotency_key_reuse` | 同じkeyを別内容のrequestで使った | クライアントの誤り。retryしない |
| 409 | `analysis_result_unavailable` | 完了記録はあるが結果を返せない | 新しいkeyでの再解析が必要 |
| 413 | `payload_too_large` | request bodyが上限超過 | 対象期間を分けて送る |
| 415 | `unsupported_media_type` | `Content-Type`が`application/json`でない | retryしない |
| 422 | `validation_error` | request契約違反 | retryしない |
| 429 | `rate_limited` | 利用上限（**Issue #5で実装**） | `Retry-After`後に同じkeyで再送 |
| 500 | `internal_error` | Server側の想定外エラー | 間隔を空けて同じkeyで再送 |
| 503 | `analysis_unavailable` | AI providerが利用できない | `Retry-After`後に同じkeyで再送 |
| 504 | `analysis_timeout` | AI解析が時間内に終わらない（**Issue #4で実装**） | 同じkeyで再送 |

`429`と`504`は契約として予約しています。#2の実装では返しません。

エラーの種類にかかわらず、AndroidはJournalEntryをローカルに保持し続けます。解析に失敗しても記録は失われません。

## Serverが保持するデータと保持期間

| テーブル | 内容 | 失効 |
| --- | --- | --- |
| `installations` | Server内部のinstallation識別子、API keyのSHA-256、作成日時 | 失効しない（installationが使われている間） |
| `analysis_requests` | installation識別子、`Idempotency-Key`、requestのSHA-256、開始・完了・失効日時 | 解析完了から30分。完了しなかった場合は開始から30分 |
| `analysis_deliveries` | 解析結果のresponse body | `analysis_requests`の行と一緒に失効・削除 |

- JournalEntry本文をDBへ保存しません。request処理中のメモリ上にだけ存在します。
- AnalysisResult本文の原本はServerに置きません。再送へ同じ結果を返すためだけに、引き渡しバッファへ保持期間の間だけ残します。
- `analysis_requests`に入るのは正規化requestのSHA-256だけで、本文は復元できません。
- 本文・prompt・API keyを通常ログ、例外メッセージ、error responseへ出しません。
- 名前、メールアドレス、profile、timezone、解析スケジュールのルール、entitlement、広告状態は保持しません。
- `installations`の削除は`analysis_requests`と`analysis_deliveries`へ`ON DELETE CASCADE`で波及します。使われなくなったinstallationの削除方針は、実運用の状況を見てIssue #5で決めます。

`analysis_requests`の完了記録と`analysis_deliveries`への書き込みは、そのclaimを取得した処理だけが行えます（取得時刻の一致と未完了であることが条件）。失効・削除された後に同じkeyで作られた新しいclaimを、古い処理が完了扱いにしたり上書きしたりしません。

### 保持期間の保証

失効した行は次の2経路で削除します。

1. 解析requestの処理中（`AnalysisRequestRepository::purgeExpired()`）。失効した結果を返さないよう、idempotencyの判定前に行います。
2. XServer Cronからの`bin/prune-expired-analyses.php`（5分間隔）。

2が必要なのは、解析requestが来なくなった期間に1が動かないためです。1だけではtrafficが途絶えた時点の解析結果本文が保持期間を越えて残り続けます。

したがって、解析結果本文がDB上に存在しうる最大時間は「保持期間30分＋cron間隔5分」の35分です。失効後の結果をAPIが返すことはありません（1が判定前に削除するため）。

## API境界

同期request / responseを前提にしています。job queueも解析結果DBも作りません。

AI呼び出しは`JournalingPostServer\Analysis\Analyzer`の1点に閉じています。認証・request検証・idempotency・error契約はこのinterfaceの実装に依存しません。

- Issue #4はこのinterfaceのAI provider実装を追加します。この文書のrequest / response契約は変わりません。
- #2の時点の既定実装は`UnavailableAnalyzer`で、認証・検証・idempotencyを通したうえでAI呼び出しの直前に`503 analysis_unavailable`を返します。
- Issue #4でXServer / PHP / AI APIの実測により同期処理が成立しないと分かった場合にだけ、非同期化を検討します。その場合も`POST /v1/analyses`は受付として残し、結果取得を追加する形を優先します。先回りして非同期基盤を作りません。

## このIssueで実装していないこと

- AI provider呼び出しとprompt（Issue #4）
- AI provider固有のtimeoutと呼び出しの打ち切り（Issue #4）
- rate limit、usage集計、登録endpointのabuse対策（Issue #5）
- account / profile、timezone、recurrence、entitlement、広告状態
- JournalEntry / AnalysisResultのクラウド保存

FCM token・`triggerAt`・ScheduledTrigger・Push予約・Server側schedulerは、このIssueで実装していないものではなく、最終仕様として持たないものです（Issue #3を`not planned`でclose）。
