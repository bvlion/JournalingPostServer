<?php

declare(strict_types=1);

namespace JournalingPostServer\Analysis\OpenAi;

use RuntimeException;

/**
 * requestを送信した後、結果を確認できずに終わったtransport失敗。
 *
 * 接続確立後のtimeoutや応答受信の途絶であり、OpenAI側で処理・課金済みかを
 * Serverから確定できない。呼び出し元はclaimを解放し、追加call・課金が
 * 発生し得ることを許容して再試行可能にする。
 *
 * `timedOut`はcurlのtimeout（`CURLE_OPERATION_TIMEDOUT`）で終わったかを表す。
 * 呼び出し元はこれで504 analysis_timeoutと500 internal_errorを振り分ける。
 *
 * メッセージにcurlのerror番号以上の情報を含めない。
 */
final class OpenAiUnconfirmedException extends RuntimeException
{
    public function __construct(string $message, private bool $timedOut)
    {
        parent::__construct($message);
    }

    public function timedOut(): bool
    {
        return $this->timedOut;
    }
}
