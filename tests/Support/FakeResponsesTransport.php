<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Support;

use JournalingPostServer\Analysis\OpenAi\OpenAiUnconfirmedException;
use JournalingPostServer\Analysis\OpenAi\OpenAiUnreachableException;
use JournalingPostServer\Analysis\OpenAi\ResponsesTransport;
use JournalingPostServer\Analysis\OpenAi\TransportResult;
use RuntimeException;

/**
 * 実OpenAIへ接続せずに`OpenAiAnalyzer`を検証するためのtransport。
 *
 * 送信されたrequest（URL・ヘッダー・body）を記録し、あらかじめ設定した結果を
 * 返すか、transport失敗を投げる。
 */
final class FakeResponsesTransport implements ResponsesTransport
{
    public int $callCount = 0;

    public ?string $lastUrl = null;

    /** @var array<string, string>|null */
    public ?array $lastHeaders = null;

    public ?string $lastBody = null;

    /** @var callable(): TransportResult */
    private $behaviour;

    public function __construct()
    {
        $this->behaviour = static fn (): TransportResult => new TransportResult(
            200,
            '{}',
        );
    }

    public function willReturn(int $status, string $body): void
    {
        $this->behaviour = static fn (): TransportResult => new TransportResult(
            $status,
            $body,
        );
    }

    public function willThrow(RuntimeException $exception): void
    {
        $this->behaviour = static function () use ($exception): TransportResult {
            throw $exception;
        };
    }

    public function willTimeOut(): void
    {
        $this->willThrow(
            new OpenAiUnconfirmedException('fake timeout', timedOut: true),
        );
    }

    public function willBeUnreachable(): void
    {
        $this->willThrow(new OpenAiUnreachableException('fake unreachable'));
    }

    public function post(string $url, array $headers, string $body): TransportResult
    {
        $this->callCount++;
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;
        $this->lastBody = $body;

        return ($this->behaviour)();
    }
}
