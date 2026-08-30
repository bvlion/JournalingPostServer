<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Integration\Support;

use Psr\Http\Message\StreamInterface;
use RuntimeException;
use stdClass;

/**
 * 読み取ったbyte数を数えるrequest body用のstream。
 *
 * 実体を保持せず、同じ1文字を`$size` byte分だけ生成する。上限を超えるbodyを、
 * テスト側でメモリへ確保せずに再現するためである。
 *
 * `bytesRead()`で「実際に読まれた量」を検証できる。body全体をstringへ読んでから
 * 長さを確認する実装では、この値がbody全体になる。
 *
 * 読み取り量はcloneをまたいで数える。PSR-7実装は`withAttribute()`等でrequestを
 * cloneする際にbodyもcloneするため、cloneごとに数えるとテストが実際に読まれた
 * 量を観測できない。
 */
final class CountingStream implements StreamInterface
{
    private int $position = 0;

    private stdClass $counter;

    public function __construct(
        private int $size,
        private string $filler = 'x',
    ) {
        $this->counter = new stdClass();
        $this->counter->bytesRead = 0;
    }

    public function bytesRead(): int
    {
        return $this->counter->bytesRead;
    }

    public function read(int $length): string
    {
        $available = min($length, $this->size - $this->position);

        if ($available <= 0) {
            return '';
        }

        $this->position += $available;
        $this->counter->bytesRead += $available;

        return str_repeat($this->filler, $available);
    }

    public function getContents(): string
    {
        return $this->read($this->size - $this->position);
    }

    public function __toString(): string
    {
        $this->rewind();

        return $this->getContents();
    }

    public function eof(): bool
    {
        return $this->position >= $this->size;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->position = $whence === SEEK_END
            ? $this->size + $offset
            : ($whence === SEEK_CUR ? $this->position + $offset : $offset);
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('The stream is not writable.');
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
