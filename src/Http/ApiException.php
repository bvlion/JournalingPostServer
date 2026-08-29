<?php

declare(strict_types=1);

namespace JournalingPostServer\Http;

use RuntimeException;

/**
 * error responseの契約（HTTP status・`error.code`・任意のheader）を運ぶ例外。
 *
 * `message`と`details`はAndroidの分岐用ではなく、開発時に原因を読むための固定文
 * である。Androidは`error.code`（未知のcodeはHTTP statusの区分）で分岐する。
 *
 * JournalEntry本文・AnalysisResult本文・API keyを`message`と`details`へ含めない。
 * `details`はフィールドパスと違反内容だけを示し、受け取った値を反映しない。
 */
final class ApiException extends RuntimeException
{
    /**
     * @param list<string> $details
     * @param array<string, string> $headers
     */
    public function __construct(
        private int $status,
        private string $errorCode,
        string $message,
        private array $details = [],
        private array $headers = [],
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return list<string>
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
