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

AI provider呼び出しはIssue #4で実装しました。rate limit / usage / 登録endpointのabuse対策はIssue #5で扱います。

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
| `Idempotency-Key` | 必須 | 端末が生成する16〜64文字の`[A-Za-z0-9_-]`。UUID v4を想定。**大文字小文字を区別します** |

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

振り返り本文を単一の`text`にしているのは、Android側`AnalysisResult`が本文を1つのプレーンテキストとして持つためです。`text`には良かったこと / 嫌だったこと / 感情スコア / 感情タイプ / 要約 / AI アドバイス / タグの7項目を固定順で整形して入れます（good / badは箇条書き、空なら「なし」）。将来の構造化はフィールド追加（互換）で行います。

## AI provider（OpenAI）

Hosted解析はOpenAI Responses APIで行います（`JournalingPostServer\Analysis\OpenAi\OpenAiAnalyzer`）。

- endpoint: `POST https://api.openai.com/v1/responses`（curl拡張で呼び出す。OpenAI SDKは追加しない）
- model: `gpt-5.6-luna` / reasoning effort `none` / `max_output_tokens` 800 / `text.verbosity` `low`
- `text.format`: strict JSON Schema（`slack_log_emotion_analysis`）。出力は good / bad / score / emotion / summary / advice / tags の7項目
- `store: false`。生成Responseを後から`GET /v1/responses/{id}`で取得するための保存を無効にする設定で、現在の値のまま変更していません。OpenAI側のすべてのデータ保持をゼロにする設定ではありません（下記「OpenAI側のデータ保持」）

ServerはHTTP応答のtop-level `status`が`completed`のResponseだけを構造化結果の成功候補にします。`status`が`incomplete`（例: `incomplete_details.reason` = `max_output_tokens`）や`failed`のResponseは、schema-validなoutput_textを含んでいても成功にせず、OpenAI呼び出し済みで結果を確定できない失敗として扱います（claimを解放しない。下記「AIへ送信後、結果を確定できない失敗」）。

### OpenAIへ送る内容

- system prompt（固定文）と、分析ルール本文（固定文）＋対象期間のログ文字列。
- ログ文字列は`AnalysisRequest.entries`から組み立てます。entryを`recordedAt`昇順（UTCの絶対時刻）に並べ、1行ずつ`<recordedAt> <本文>`にします。
  - moodがあるentryの本文は次のように組み立てます。moodのみなら「気分は{emoji}とのこと」、noteもあればその後へtrimしたnoteを続けます。
  - noteのみのentryはnoteをそのまま使います。
  - `moodLabel`はAI入力へ含めません（現行のSlackログにも含まれていないため）。
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
- 設定は`.env`の`OPENAI_API_KEY`と`OPENAI_TIMEOUT_SECONDS`です。未指定・空・`OPENAI_TIMEOUT_SECONDS`が正の整数でない場合は、秘密値を含めずに起動を失敗させます。


## Idempotency / retry / timeout

### 契約

- `Idempotency-Key`はinstallationごとのスコープです。大文字小文字を区別するため、`Example_Key_1234`と`example_key_1234`は別のkeyです。
- Serverは検証後のrequestを正規化し、その鍵付きhash（HMAC-SHA-256）で同じkeyのrequestが同一内容かを判定します。timezone表記やキー順序の違いは同一とみなし、entryの内容・件数・順序の違いは別とみなします。
- 鍵にはServerだけが持つ秘密値を使い、hashはinstallation単位にscopeします。素のhashだと、mood 1件だけのrequestのように入力空間が狭い場合に、DBを読める側が候補を列挙して突き合わせ、JournalEntryの内容を言い当てられるためです。Androidはこの値を送らず、受け取りません。
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

保持期間切れの判定はcleanupの実行有無に依存しません。上記の判定時にも失効を確認し、失効していれば行と本文を削除してから新しいrequestとして扱います。失効した結果が`200`で返ることはありません。

完了済みの結果を返せるようにするため、Serverは解析結果本文を**引き渡しバッファ**（`analysis_deliveries`）へ保持期間の間だけ保持します。これはidempotency metadata（`analysis_requests`）とは別のテーブルで、原本ではありません。この保持がないと、responseがnetworkで失われた場合に、課金済みの解析結果を返せず再課金になります。

引き渡し済みの行を即時削除せず保持期間まで残すのは、削除するとその応答が失われた場合に同じ問題が再発するためです。保持期間の上限は削除方式によらず変わりません。

### 応答が返らなかった解析の扱い

処理中のままServerが停止しても、経過時間だけを根拠に新しいAI呼び出し権を与えません。前の処理が動き続けている保証が無く、与えると同じ解析を二重にAIへ投げるためです。

その`Idempotency-Key`は保持期間（30分）で失効するまで`409 analysis_in_progress`を返し続け、失効後は新しい解析として受け付けます。

失効後に同じkeyで新しい解析が始まった後で、古い処理が遅れて終わることがあります。この場合、古い処理は新しい解析の完了記録も引き渡しバッファも書き換えません。完了記録・バッファ書き込み・解放のいずれも、自分が取得したclaim（取得時刻が一致し、まだ完了していない行）だけを対象にします。古い結果が新しいrequestの応答として返ることはありません。

OpenAI呼び出しがtimeoutした場合など、requestを送信した後で処理・課金済みかをServerから確定できない失敗では、claimを解放しません。解放すると同じkeyの即時retryがOpenAIを再実行して二重に課金し得るためです。この場合はその世代のclaimが保持期間（30分）で失効するまで`409 analysis_in_progress`を返し、失効後は新しい解析として受け付けます。詳細は次節。

### AIへ送信後、結果を確定できない失敗

Serverは解析の失敗を2種類に分けて扱います。

1. **AIが成功していないと確定できる失敗**（requestがOpenAIへ到達しなかった、OpenAIが4xx〈`429`等を含む〉を返した等。いずれも処理前の拒否と確定できます）。claimを解放し、同じ`Idempotency-Key`での再送をそのまま再実行できるようにします。応答は`503 analysis_unavailable`です。
2. **OpenAIへ送信後、処理・課金済みかServerから確定できない失敗**（送信後のtimeout・応答受信の途絶・**OpenAIの5xx応答**・2xxだが生成結果を利用できない）。claimを解放しません。timeoutは`504 analysis_timeout`、5xxは4xxと同じ`503 analysis_unavailable`、それ以外は`500 internal_error`を返します。AI解析が成功した後に応答の組み立てや完了記録でServer内部エラーが起きた場合（`500 internal_error`）も同じ扱いです。5xxは、OpenAIがrequestを受理・処理した後の一時的な5xxか処理前の拒否かをHTTPエラー応答だけからは確定できないため、生成・課金が行われた可能性がある側へ倒します。いずれもAI呼び出しは課金され得るため、解放してretryが即座にAIを再実行するのを避けます。

Androidの扱いは`504` / `500`の契約どおり、また5xx由来の`503`（`Retry-After`後に同じkeyで再送）も、いずれも**同じ`Idempotency-Key`で再送**します。新しいkeyへ切り替えないでください。新しいkeyはユーザーが意図した再解析のためのものです。

同じkeyでの再送は、保持期間（30分）の間`409 analysis_in_progress`になる場合があります。処理は動いていませんが、二重課金を避けるためclaimを保持している状態です。失効後の再送は新しい解析として受け付けます。

### timeout

- Serverは`OPENAI_TIMEOUT_SECONDS`でOpenAI呼び出しのtimeoutを設定します。これを超えると`504 analysis_timeout`を返します。実測（下記「本番timeoutの決定」）から **本番値は `45` 秒** とします。
- **Androidの読み取りtimeoutは `90` 秒を推奨します。** Serverが`504`を返すまでの上限は `OPENAI_TIMEOUT_SECONDS`（45秒）＋ request解析・応答整形・DB書き込みの数秒 ≈ 50秒で、90秒はその上に余裕を持たせた値です。Android側の実測後に短縮して構いません。
- timeout（`504`）したrequestは、`Retry-After`に従って同じ`Idempotency-Key`で再送してください。送信済みのAI呼び出しを二重課金しないため、Serverはその世代のclaimを保持し、保持期間（30分）内の再送は`409 analysis_in_progress`になり得ます。
- 保持期間（30分）を過ぎてからの再送は新しい解析になります。それより後にretryしないでください。

#### 本番timeoutの決定

XServer上でPR #10のproduction実装（`OpenAiAnalyzer` / `CurlResponsesTransport`）をそのまま使い、実OpenAI Responses APIへ接続して測定しました（`/opt/php-8.5.5/bin/php`、curl 7.61.1 / OpenSSL 1.1.1k、測定用curl timeout 180秒、架空のJournalEntry）。詳細と生の数値はIssue #4のコメントに記録しています。

| case | entry数 | request payload | 成功応答の所要時間 |
| --- | --- | --- | --- |
| 1 entry | 1 | 約3.6 KB | 約2.2秒 |
| 20 entries | 20 | 約5.2 KB | 約2.9〜4.2秒 |
| 100 entries | 100 | 約11.8 KB | 約2.9〜4.2秒 |
| 200 entries（約1000字note）| 200 | 約404 KiB | 約3.2〜4.3秒 |

- 全成功応答が `status = completed` かつ strict schemaの7項目を満たしました（実APIに対するPR #10のstatus判定・schema検証も兼ねています）。
- 所要時間は入力サイズにほぼ依存せず 2.2〜4.3秒。`gpt-5.6-luna` + reasoning `none` の応答は短くばらつきも小さいです。
- サンプルは短時間内の少数回で、高パーセンタイル・時間帯変動は未測定です。

結論: **同期HTTPは成立します。** 非同期化は不要です。

- `OPENAI_TIMEOUT_SECONDS = 45` の採用根拠は次の2点です。web / FastCGI / front proxy の timeout は確認していません。
  - 実OpenAI成功応答の実測最大が約4.3秒。
  - 少数サンプルで高パーセンタイル・時間帯変動を測れていないため、十分な余裕をとる。
  - 本番監視で45秒に近づく応答が出たら見直します。
- Android read timeout = 90秒（上記）。

web `max_execution_time`・XServer front proxy の read timeout・Android read timeout は、いずれも未確認または端末側の設定です。本番配置前の前提条件として、**45秒の provider timeout ＋ request 処理の overhead を、これら外側の timeout がすべて上回ること**を確認します。この条件を満たした場合にだけ、遅いケースで Server 側の `504 analysis_timeout`（claim 非解放）が外側の timeout より先に発火し、二重課金を避けられます。CLI PHP は `max_execution_time = 0`（無制限）ですが API は web SAPI で動くため、web 側の値をサーバーパネルの PHP 設定で確認します。OpenAI 側のリクエスト timeout は意図的に発生させていません（`max_output_tokens: 800` / `reasoning: none` で生成は短く、超過時は `status: incomplete` として扱われます）。

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
| 500 | `internal_error` | Server側の想定外エラー | 間隔を空けて同じkeyで再送（保持期間内は`409 analysis_in_progress`になる場合がある。上記参照） |
| 503 | `analysis_unavailable` | AI providerが利用できない（provider未到達・4xx・5xx） | `Retry-After`後に同じkeyで再送（5xx由来の場合は保持期間内は`409 analysis_in_progress`になり得る） |
| 504 | `analysis_timeout` | AI解析が`OPENAI_TIMEOUT_SECONDS`内に終わらない | 同じkeyで再送（保持期間内は`409 analysis_in_progress`になる場合がある） |

`429`は契約として予約しています（Issue #5で実装）。`504`はIssue #4で実装しました。

エラーの種類にかかわらず、AndroidはJournalEntryをローカルに保持し続けます。解析に失敗しても記録は失われません。

## Serverが保持するデータと保持期間

| テーブル | 内容 | 失効 |
| --- | --- | --- |
| `installations` | Server内部のinstallation識別子、API keyのSHA-256、作成日時 | 失効しない（installationが使われている間） |
| `analysis_requests` | installation識別子、`Idempotency-Key`、requestの鍵付きhash、開始・完了・失効日時 | 解析完了から30分。完了しなかった場合は開始から30分 |
| `analysis_deliveries` | 解析結果のresponse body | `analysis_requests`の行と一緒に失効・削除 |

- JournalEntry本文をDBへ保存しません。request処理中のメモリ上にだけ存在します。
- AnalysisResult本文の原本はServerに置きません。再送へ同じ結果を返すためだけに、引き渡しバッファへ保持期間の間だけ残します。
- `analysis_requests`に入るのは正規化requestの鍵付きhash（HMAC-SHA-256）だけで、本文は復元できません。鍵はDBの外（環境変数）にあるため、DBだけを読める状態では本文の候補を列挙して突き合わせることもできません。鍵はinstallation単位にscopeしているため、installationを跨いで同じ内容のrequestを突き合わせることもできません。
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

- Issue #4で`OpenAi\OpenAiAnalyzer`（curl transport）を既定実装として追加しました。この文書のrequest / response契約は変わりません。
- テストは`Analyzer`をこのseamで差し替え、実OpenAIへ接続しません。
- XServer / PHP / OpenAI APIの実測により同期処理が成立しないと分かった場合にだけ、非同期化を検討します。その場合も`POST /v1/analyses`は受付として残し、結果取得を追加する形を優先します。先回りして非同期基盤を作りません。

## このIssueで実装していないこと

- provider呼び出し自体の打ち切り（Serverはtimeout時にconnection側で打ち切り、`504`を返す。呼び出しのキャンセル通知はOpenAIへ送らない）
- rate limit、usage集計、登録endpointのabuse対策（Issue #5）
- account / profile、timezone、recurrence、entitlement、広告状態
- JournalEntry / AnalysisResultのクラウド保存

FCM token・`triggerAt`・ScheduledTrigger・Push予約・Server側schedulerは、このIssueで実装していないものではなく、最終仕様として持たないものです（Issue #3を`not planned`でclose）。
