<?php

declare(strict_types=1);

namespace JournalingPostServer\Analysis\OpenAi;

use RuntimeException;

/**
 * requestがOpenAIへ到達していないと判断できるtransport失敗。
 *
 * 名前解決・接続確立・TLSハンドシェイクの前段で失敗した場合であり、AIは呼ばれて
 * いない。呼び出し元は確定失敗として扱い、claimを解放して同じIdempotency-Keyでの
 * 再試行をそのまま許可してよい。
 *
 * メッセージにcurlのerror番号以上の情報（URL・request body・API key）を含めない。
 */
final class OpenAiUnreachableException extends RuntimeException
{
}
