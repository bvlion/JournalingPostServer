<?php

declare(strict_types=1);

namespace JournalingPostServer\Analysis;

use JournalingPostServer\Http\ApiException;
use RuntimeException;

/**
 * AIへrequestを送信した後、処理・課金済みかServerから確定できない失敗。
 *
 * `Analyzer`がこれを投げると、`CreateAnalysisAction`はclaimを解放しない。解放
 * すると同じIdempotency-Keyの即時retryがOpenAIを再実行して二重に課金し得る
 * ためである（送信後のtimeout・応答受信の途絶・2xxだが生成結果を利用できない
 * 場合）。claimは保持期間で失効するまで残り、その間の再送は
 * `409 analysis_in_progress`になる。
 *
 * `response()`はAndroidへ返す確定済みのerror契約（`504 analysis_timeout` /
 * `500 internal_error`）である。provider固有の状態はここへ持ち込まず、
 * idempotency repositoryにも渡さない。
 */
final class AnalysisResultUnconfirmedException extends RuntimeException
{
    public function __construct(
        private ApiException $response,
        string $reason,
    ) {
        parent::__construct($reason);
    }

    public function response(): ApiException
    {
        return $this->response;
    }
}
