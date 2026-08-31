<?php

declare(strict_types=1);

namespace JournalingPostServer\Analysis\OpenAi;

/**
 * OpenAI Responses APIとのHTTP交換が最後まで完了した結果。
 *
 * `status`が2xxかどうかは呼び出し元が判断する。非2xxのbodyもここへ入るが、
 * その内容（providerのerror body）を例外メッセージ・通常ログ・error responseへ
 * 出さないのは呼び出し元の責務である。
 */
final class TransportResult
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }
}
