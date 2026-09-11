# Hosted解析API契約

AndroidアプリJournalingPost（`bvlion/JournalingPost`）とJournalingPostServerが共有するHTTP契約です。

JournalEntryの最小内容契約は、Moodは絵文字だけ・名称だけ・両方のいずれでも可、noteだけでも可、Moodもnoteも無いentryは不可です。

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

自動解析でも同じ流れです。ServerがtriggerAtを持ってFCMでAndroidを起こす構成は採用しません。Serverが持たないのは、FCM token・`triggerAt`・ScheduledTrigger・Push予約・scheduler・timezone・recurrenceです。

AI provider呼び出し、成功した対象日単位の利用制御、provider call記録、Play Integrityによる登録確認を実装しています。

## 共通事項

| 項目 | 契約 |
| --- | --- |
| 通信 | 本番はHTTPSのみ。平文HTTPで呼び出さない（Serverはリダイレクトせず拒否する） |
| Base path | `/v1` |
| request body | `Content-Type: application/json`（UTF-8） |
| response body | `application/json; charset=utf-8` |
| 時刻表現 | RFC 3339。詳細は下記 |
| responseの時刻 | UTC・秒精度（`2026-08-29T09:00:05Z`） |
| 未知のフィールド | Serverは無視する。Android側の項目追加でServerの更新を必要としない |

Serverはtimezoneやrecurrenceを解釈しません。対象期間の計算はAndroid側の責務です。

### 通信

本番のHosted APIはHTTPSでだけ呼び出します。Bearer API keyとJournalEntry本文が平文で流れないようにするためで、平文HTTPでの送信は契約違反として扱います。Serverは平文HTTPのrequestをHTTPSへリダイレクトせず、Apache側で拒否します（リダイレクトしてもrequest自体は平文で送信済みのため）。Androidは平文HTTPへのfallbackやHTTPからのリダイレクト追従を行わず、最初からHTTPSへ直接接続してください。

ローカル開発（`http://127.0.0.1:8081`）だけは例外です。

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

## POST /v1/installations

Google Play配布の正規アプリであることをPlay Integrity Standard requestで確認してから登録します。Content-Typeはapplication/json、本文の上限は32 KiBです。

```json
{
  "registrationId": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "integrityToken": "<Google Playから取得したStandard Integrity token>"
}
```

- Androidは登録試行ごとに32 random bytesの小文字hexをregistrationIdとして生成します。人物・端末識別子ではなく、その登録試行だけの一時値です。Serverは保存しません。
- requestHashはUTF-8の `POST\n/v1/installations\n<packageName>\n<registrationId>` のSHA-256（小文字64桁hex）です。末尾改行は付けません。
- ServerがGoogleのdecodeIntegrityTokenで検証したrequestPackageNameとappIntegrity.packageNameが設定値に一致し、requestHashが一致し、timestampMillisが過去5分以内かつ未来でないことを要求します。
- appRecognitionVerdictはPLAY_RECOGNIZED、appLicensingVerdictはLICENSEDを要求します。deviceIntegrityは必須にしません。
- Standard requestの再利用防止により繰り返し復号されたtokenの判定がUNEVALUATEDになった場合も拒否します。登録応答を失った場合は新しいregistrationIdとtokenを取得してください。
- token・判定本文・Googleのユーザー情報は保存しません。IP制限、24時間制限、Device Recall、installationを跨ぐ識別子は導入しません。正規アプリのデータ消去や再インストールによる再登録は許容します。

根拠：[Google Standard request](https://developer.android.com/google/play/integrity/standard)、[判定項目](https://developer.android.com/google/play/integrity/verdicts)。

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

analysisDateはHosted専用の必須文字列（yyyyMMdd）です。Hosted HTTP APIはJST当日を含む直近7日だけ受け付け、未来日・7日前以前は422にします。ローカル比較専用CLIは固定した過去入力を繰り返し使うため、この受付期間制限を適用しません。periodは結果の対象期間として維持し、利用制御には使用しません。Custom Webhookの契約には追加しません。

すべてのrecordedAtはanalysisDateのUTC 00:00の前後24時間以内（境界を含む）、最古と最新の差も24時間以内とします。timezoneを送信・保存・推測せず、厳密なローカル日付一致は要求しません。下の例は2026-08-29が受付範囲内の日に使う架空値です。

**Request headers**

| Header | 必須 | 内容 |
| --- | --- | --- |
| `Authorization` | 必須 | `Bearer <API key>` |
| `Content-Type` | 必須 | `application/json` |
| `Idempotency-Key` | 必須 | 端末が生成する16〜64文字の`[A-Za-z0-9_-]`。UUID v4を想定。**大文字小文字を区別します** |

**Request body**

```json
{
  "analysisDate": "20260829",
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
      "mood": { "emoji": "", "label": "すこし上向き" },
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
| `entries[].mood` | 任意 | Moodがあるentryだけ指定。`emoji`（16文字以内）と`label`（100文字以内）の文字列で、少なくとも一方が空白以外（もう一方は空文字または省略でよい） |
| `entries[].note` | 任意 | 2000文字以内。空白のみは未指定と同じに扱う |

- 各entryは利用者が記録した意味のある内容を最低1つ持ちます。許可する状態はMoodのみ / Mood + note / noteのみで、Moodもnoteも無い（日時だけの）entryは`422 validation_error`です。
- Moodは絵文字だけ・名称だけ・両方のいずれでも送れます。noteのみのentry（`mood`なし）も正規の状態です。
- Android側`JournalEntry`の`id` / `source` / `deliveryStatus` / `moodId`は解析に不要なため送りません。`mood`は記録時点のsnapshotをそのまま送ります。ServerはMoodの同一性を判定しません。
- `entries`が`period`の範囲内かをServerは検証しません。対象期間の切り出しはAndroid側の責務です。
- `entries`の順序は解析上の意味を持ちませんが、idempotency判定には影響します（後述）。
- `entries`が0件の場合はAI呼び出しを行わず`validation_error`を返します。対象期間に記録が無い場合、Androidは解析requestを送りません。
- request body全体の上限は1 MiBです。超過分をServerがメモリへ読み込まないよう、`Content-Length`が上限を超えていればbodyを読まずに`413`を返します。`Content-Length`が無い場合や実際のbodyと一致しない場合も、上限までしか読まずに`413`を返します。

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
    "model": "gpt-5.6-luna",
    "text": "..."
  }
}
```

| フィールド | 内容 |
| --- | --- |
| `period` | 解析対象期間。requestの値をUTC・秒精度へ正規化して返す |
| `analyzedAt` | 解析完了時刻 |
| `entryCount` | 解析に使ったentry数 |
| `model` | 解析に使ったAI model識別子。Hosted解析は`gpt-5.6-luna`（OpenAI Responses API）を使う。補足情報 |
| `text` | 振り返り本文（プレーンテキスト） |

Android側`AnalysisResult`が必要とする「対象期間」「解析日時」「解析結果」はこの応答から作れます。「解析方法 / 種別」はAndroid側が持つ区分（Hosted / Custom Webhook）であり、Serverは指定しません。`model`は補足情報です。

振り返り本文を単一の`text`にしているのは、Android側`AnalysisResult`が本文を1つのプレーンテキストとして持つためです。`text`には要約 / 良かったこと / 嫌だったこと / 感情 / AI アドバイスの5項目を固定順で整形して入れます（good / badは箇条書き、空なら「なし」）。要約を本文先頭へ置くため、Android側はHosted固有の解析をせず、そのまま一覧プレビューに利用できます。将来の構造化はフィールド追加（互換）で行います。

## AI provider（OpenAI）

Hosted解析はOpenAI Responses APIで行います（`JournalingPostServer\Analysis\OpenAi\OpenAiAnalyzer`）。system promptと分析ルール本文は実行時のプレーンテキストファイルから読み込みます。

- endpoint: `POST https://api.openai.com/v1/responses`（curl拡張で呼び出す。OpenAI SDKは追加しない）
- model: `gpt-5.6-luna` / reasoning effort `none` / `max_output_tokens` 800 / `text.verbosity` `low`
- `text.format`: strict JSON Schema（`slack_log_emotion_analysis`）。出力は good / bad / emotion / summary / advice の5項目（emotionは感情タイプと0〜100の感情スコアを含む）
- `store: false`。生成Responseを後から`GET /v1/responses/{id}`で取得するための保存を無効にする設定で、現在の値のまま変更していません。OpenAI側のすべてのデータ保持をゼロにする設定ではありません（下記「OpenAI側のデータ保持」）

ServerはHTTP応答のtop-level `status`が`completed`のResponseだけを構造化結果の成功候補にします。`status`が`incomplete`（例: `incomplete_details.reason` = `max_output_tokens`）や`failed`のResponseは、schema-validなoutput_textを含んでいても成功にせず、OpenAI呼び出し済みで結果を確定できない失敗として扱います（claimを解放して再試行可能にする。下記「AIへ送信後、結果を確定できない失敗」）。

### OpenAIへ送る内容

- system prompt（固定文）と、分析ルール本文（固定文）＋対象期間のログ文字列。system promptと分析ルール本文はServer側の固定設定で、`bootstrap/config.php`が実行時のプレーンテキストファイルから読み取ります（1行目 = system prompt、残り = 分析ルール本文）。Androidから指定するAPIにはしません（[本番実行環境](production-environment.md)の「秘密情報」、[環境設定](../README.md#環境設定)）。
- ログ文字列は`AnalysisRequest.entries`から組み立てます。entryを`recordedAt`昇順（UTCの絶対時刻）に並べ、1行ずつ`<recordedAt> <本文>`にします。
  - moodがあるentryの本文は次のように組み立てます。moodに絵文字があれば絵文字、無ければ名称（`label`）を使い、moodのみなら「気分は{X}とのこと」、noteもあればその後へtrimしたnoteを続けます。
  - noteのみのentryはnoteをそのまま使います。
  - 絵文字だけのMoodは絵文字、名称だけのMoodは名称が解析材料になります。日時だけのログ行は作りません（そのようなentryは`422`で拒否されます）。
- 送らないもの: `Idempotency-Key`、`ANALYSIS_FINGERPRINT_SECRET`、installation識別子、API key hash。`OPENAI_API_KEY`は`Authorization`ヘッダーにのみ使い、bodyへは入れません。

### OpenAI側のデータ保持

`store: false`はServerが後からResponseを取得しないための設定にすぎません。OpenAIのData Controls上、標準のAPI利用では次が該当し得ます。実装はこれらを前提にした設計です。

- API input / output はデフォルトではmodel学習に使用されません。
- 標準のAPI利用ではabuse monitoring logsにprompt / responseが含まれ得て、最大30日保持され得ます。
- `/v1/responses`はZero Data Retention（ZDR）の対象ですが、ZDRはOpenAIの承認・アカウント設定が必要です。現在のServer実装はZDRが有効であることを前提にしません。
- ZDR未設定では、対応modelのextended prompt cachingによりOpenAI側に一時的なapplication stateが存在し得ます。

ZDRを有効化する場合はデプロイ運用（`docs/production-environment.md`）で扱います。ZDRの有無でServerのrequest / responseとerror契約は変わりません。

### secretとprovider error

- `OPENAI_API_KEY`の実値を、repository・response・通常ログ・例外メッセージへ出しません。
- OpenAIがHTTPエラーを返した場合、そのresponse bodyを例外文・ログ・error responseへ出さず、固定のerror契約（`503 analysis_unavailable`）へ変換します。4xxはclaimを解放して再実行可能にし、5xxはHTTPエラー応答だけからは生成・課金の有無を確定できないためclaimを解放しません（応答は同じ`503`）。詳細は「AIへ送信後、結果を確定できない失敗」。
- 設定は`.env`の`OPENAI_API_KEY`と`OPENAI_TIMEOUT_SECONDS`です。未指定・空・`OPENAI_TIMEOUT_SECONDS`が正の整数でない場合は、HTTPアプリの起動を秘密値を含めずに失敗させます。DBだけを使うCLI（`bin/migrate.php`・`bin/prune-expired-analyses.php`）はこれらを検証しないため、`OPENAI_API_KEY`を空にしても失効データ削除Cronは動き続けます。

## Idempotency / retry / timeout

### 契約

- `Idempotency-Key`はinstallationごとのスコープです。大文字小文字を区別するため、`Example_Key_1234`と`example_key_1234`は別のkeyです。
- Serverは検証後のrequestを正規化し、その鍵付きhash（HMAC-SHA-256）で同じkeyのrequestが同一内容かを判定します。timezone表記やキー順序の違いは同一とみなし、entryの内容・件数・順序の違いは別とみなします。
- 鍵にはServerだけが持つ秘密値を使い、hashはinstallation単位にscopeします。素のhashだと、mood 1件だけのrequestのように入力空間が狭い場合に、DBを読める側が候補を列挙して突き合わせ、JournalEntryの内容を言い当てられるためです。Androidはこの値を送らず、受け取りません。
- **network timeout等での再送**は、同じ`Idempotency-Key`と同じbodyで送ります。成功結果がbufferにあればAIは再度呼ばれません。結果不明の失敗後は再実行するため、追加課金が発生し得ます。
- 同じinstallation + analysisDateで成功した解析は1回だけです。新しいkeyでも成功済み日は429 rate_limitedです。別の日次・月次回数bucketはありません。

### 再送に対するServerの応答

| 状態 | 応答 |
| --- | --- |
| 未処理 | AI解析を実行し`200` |
| 処理中（完了していない） | `409 analysis_in_progress` + `Retry-After: 15` |
| 完了済み・保持期間内 | 初回と同じbodyを`200`で返す（AIは呼ばない） |
| 同じkeyで別内容 | `409 idempotency_key_reuse` |
| 保持期間切れ・成功済み日 | `429 rate_limited`（AIは呼ばない） |
| 保持期間切れ・成功していない日 | 受付可能日の範囲内なら新しい解析を実行 |

保持期間切れの判定はcleanupの実行有無に依存しません。上記の判定時にも失効を確認し、失効していれば行と本文を削除してから新しいrequestとして扱います。失効した結果が`200`で返ることはありません。

完了済みの結果を返せるようにするため、Serverは解析結果本文を**引き渡しバッファ**（`analysis_deliveries`）へ保持期間の間だけ保持します。これはidempotency metadata（`analysis_requests`）とは別のテーブルで、原本ではありません。この保持がないと、responseがnetworkで失われた場合に、課金済みの解析結果を返せず再課金になります。

引き渡し済みの行を即時削除せず保持期間まで残すのは、削除するとその応答が失われた場合に同じ問題が再発するためです。保持期間の上限は削除方式によらず変わりません。

### 応答が返らなかった解析の扱い

処理中のままServerが停止しても、経過時間だけを根拠に新しいAI呼び出し権を与えません。前の処理が動き続けている保証が無く、与えると同じ解析を二重にAIへ投げるためです。

その`Idempotency-Key`は保持期間（30分）で失効するまで`409 analysis_in_progress`を返し続け、失効後は新しい解析として受け付けます。

失効後に同じkeyで新しい解析が始まった後で、古い処理が遅れて終わることがあります。この場合、古い処理は新しい解析の完了記録も引き渡しバッファも書き換えません。完了記録・バッファ書き込み・解放のいずれも、自分が取得したclaim（取得時刻が一致し、まだ完了していない行）だけを対象にします。古い結果が新しいrequestの応答として返ることはありません。

### AIへ送信後、結果を確定できない失敗

未到達、provider 4xx、送信後のtimeout、受信途絶、provider 5xx、利用可能な結果を取得できない2xxは、claimを解放して再試行可能にします。結果不明を成功済み日として消費しません。timeoutは504 analysis_timeout、provider 4xx/5xx・未到達は503 analysis_unavailable、それ以外の結果不明は500 internal_errorです。

AI成功後は、応答の組み立てやbuffer保存より先に成功済み日を記録します。成功済み日は再実行せず、buffer期限後は通常お問い合わせから運用者が確認します。既知の失敗を30分間抑止することはありません。プロセスが強制終了し失敗処理自体が走らない場合、未完了claimは開始から30分で失効します。

### timeout

- Serverは`OPENAI_TIMEOUT_SECONDS`でOpenAI呼び出しのtimeoutを設定します。これを超えると`504 analysis_timeout`を返します。実測（下記「本番timeoutの決定」）から **本番値は `45` 秒** とします。
- **Androidの読み取りtimeoutは `90` 秒を推奨します。** Serverが`504`を返すまでの上限は `OPENAI_TIMEOUT_SECONDS`（45秒）＋ request解析・応答整形・DB書き込みの数秒 ≈ 50秒で、90秒はその上に余裕を持たせた値です。Android側の実測後に短縮して構いません。
- timeoutしたrequestは同じkeyで再試行可能です。Androidの短時間自動retryはnetwork / timeout / 5xx / analysis_in_progressに限り、30分bufferを活かす範囲に留めます。429は自動retryしません。Android実装はJournalingPost #86の対象です。

#### 本番timeoutの決定

XServer上でproduction実装（`OpenAiAnalyzer` / `CurlResponsesTransport`）をそのまま使い、実OpenAI Responses APIへ接続して測定しました（`/opt/php-8.5.5/bin/php`、curl 7.61.1 / OpenSSL 1.1.1k、測定用curl timeout 180秒、架空のJournalEntry）。測定条件と結果は以下のとおりです。

| case | entry数 | request payload | 成功応答の所要時間 |
| --- | --- | --- | --- |
| 1 entry | 1 | 約3.6 KB | 約2.2秒 |
| 20 entries | 20 | 約5.2 KB | 約2.9〜4.2秒 |
| 100 entries | 100 | 約11.8 KB | 約2.9〜4.2秒 |
| 200 entries（約1000字note）| 200 | 約404 KiB | 約3.2〜4.3秒 |

- 全成功応答が `status = completed` かつ測定時点のstrict schema（7項目）を満たしました（実APIに対するstatus判定・schema検証も兼ねています）。現在のschemaは上記の5項目です。
- 所要時間は入力サイズにほぼ依存せず 2.2〜4.3秒。`gpt-5.6-luna` + reasoning `none` の応答は短くばらつきも小さいです。
- サンプルは短時間内の少数回で、高パーセンタイル・時間帯変動は未測定です。

結論: **同期HTTPは成立します。** 非同期化は不要です。

- `OPENAI_TIMEOUT_SECONDS = 45` の採用根拠は次の2点です（この45秒の根拠に web / FastCGI / front proxy の timeout は含めていません）。
  - 実OpenAI成功応答の実測最大が約4.3秒。
  - 少数サンプルで高パーセンタイル・時間帯変動を測れていないため、十分な余裕をとる。
  - 本番監視で45秒に近づく応答が出たら見直します。
- Android read timeout = 90秒（上記）。

web `max_execution_time` は本番サーバーパネルで **30秒** を確認しました（PHP 8.5.9 / `display_errors` OFF）。30秒のまま維持しています。Linux版PHPでは system call・stream operation・DB query 等の待機時間が `max_execution_time` の計測対象に含まれないため、OpenAI 呼び出し（curl / socket 待ち）の待機時間は 30秒 の対象外であり、この値を `OPENAI_TIMEOUT_SECONDS = 45` と単純比較しません。CLI PHP は `max_execution_time = 0`（無制限）ですが API は web SAPI で動きます。

本番配置後のsmoke testで、実サイズの `POST /v1/analyses` が本番 web request 内で完了し、通常の成功ケースが XServer の Web / FastCGI / front proxy の wall-clock timeout で先に切られないことを確認しました。遅いケースで Server 側の `504 analysis_timeout`（結果不明時は再試行可能）が外側の timeout より先に発火することの実証（意図的な provider timeout / fault injection）は、この確認の対象外です。OpenAI 側のリクエスト timeout は意図的に発生させていません（`max_output_tokens: 800` / `reasoning: none` で生成は短く、超過時は `status: incomplete` として扱われます）。

## Error response

すべてのエラーが同じ形です。

```json
{
  "error": {
    "code": "validation_error",
    "message": "The request does not satisfy the analysis request contract.",
    "details": ["entries[1].mood: must have a non-empty emoji or label."]
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
| 409 | `analysis_result_unavailable` | 完了記録はあるが結果を返せない | 通常お問い合わせでSupport IDと対象日を連絡 |
| 413 | `payload_too_large` | request bodyが上限超過 | 対象期間を分けて送る |
| 415 | `unsupported_media_type` | `Content-Type`が`application/json`でない | retryしない |
| 422 | `validation_error` | request契約違反 | retryしない |
| 429 | `rate_limited` | 同じinstallationの対象日が成功済み | 自動retryしない。Retry-Afterは付けない |
| 403 | `registration_rejected` | Play Integrityの判定が条件を満たさない | 正規のPlay配布版とライセンスを確認 |
| 503 | `registration_unavailable` | 登録確認サービス・設定が利用不能 | 新しいtokenで再試行 |
| 500 | `internal_error` | Server側の想定外エラー | 間隔を空けて同じkeyで再送（保持期間内は`409 analysis_in_progress`になる場合がある。上記参照） |
| 503 | `analysis_unavailable` | AI providerが利用できない（provider未到達・4xx・5xx） | `Retry-After`後に同じkeyで再送 |
| 504 | `analysis_timeout` | AI解析が`OPENAI_TIMEOUT_SECONDS`内に終わらない | 同じkeyで再送（追加provider callが発生し得る） |



エラーの種類にかかわらず、AndroidはJournalEntryをローカルに保持し続けます。解析に失敗しても記録は失われません。

## Serverが保持するデータと保持期間

| テーブル | 内容 | 失効 |
| --- | --- | --- |
| `installations` | Server内部のinstallation識別子、API keyのSHA-256、作成日時 | 失効しない（installationが使われている間） |
| `analysis_requests` | installation識別子、analysisDate、`Idempotency-Key`、requestの鍵付きhash、開始・完了・失効日時 | 解析完了から30分。失敗時は解放。強制終了等で残った場合は開始から30分 |
| `analysis_deliveries` | 解析結果のresponse body | `analysis_requests`の行と一緒に失効・削除 |
| `analysis_days` | installation識別子、成功済みanalysisDate | JST直近7日の対象外になった後に定期削除 |
| `provider_calls` | installation識別子、呼出日時、取得できたmodel・input/cached input/output token数 | 前月分を月次出力後に削除。定期cleanupでも35日超過分を削除 |

- JournalEntry本文をDBへ保存しません。request処理中のメモリ上にだけ存在します。
- AnalysisResult本文の原本はServerに置きません。再送へ同じ結果を返すためだけに、引き渡しバッファへ保持期間の間だけ残します。
- `analysis_requests`に入るのは正規化requestの鍵付きhash（HMAC-SHA-256）だけで、本文は復元できません。鍵はDBの外（環境変数）にあるため、DBだけを読める状態では本文の候補を列挙して突き合わせることもできません。鍵はinstallation単位にscopeしているため、installationを跨いで同じ内容のrequestを突き合わせることもできません。
- 本文・prompt・API keyを通常ログ、例外メッセージ、error responseへ出しません。
- 名前、メールアドレス、profile、timezone、解析スケジュールのルール、entitlement、広告状態は保持しません。
- `installations`の削除は`analysis_requests`と`analysis_deliveries`へ`ON DELETE CASCADE`で波及します。使われなくなったinstallationの削除方針は、実運用の状況を見て決めます。

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

- `OpenAi\OpenAiAnalyzer`（curl transport）を既定実装として追加しました。system promptと分析ルール本文は実行環境の設定から読み込みます。この文書のrequest / response契約は変わりません。
- テストは`Analyzer`をこのseamで差し替え、実OpenAIへ接続しません。
- XServer / PHP / OpenAI APIの実測により同期処理が成立しないと分かった場合にだけ、非同期化を検討します。その場合も`POST /v1/analyses`は受付として残し、結果取得を追加する形を優先します。先回りして非同期基盤を作りません。

## 未実装・対象外

- provider呼び出し自体の打ち切り（Serverはtimeout時にconnection側で打ち切り、`504`を返す。呼び出しのキャンセル通知はOpenAIへ送らない）
- account / profile、timezone、recurrence、entitlement、広告状態
- JournalEntry / AnalysisResultのクラウド保存

FCM token・`triggerAt`・ScheduledTrigger・Push予約・Server側schedulerは、未実装という位置づけではなく、最終仕様として持たないものです。

利用記録の確認・原価換算・月次出力・個別解除の手順は[Hosted利用制御の運用](hosted-usage-operations.md)を参照してください。
