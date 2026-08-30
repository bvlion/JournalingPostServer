<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Unit;

use JournalingPostServer\Installation\ApiKey;
use PHPUnit\Framework\TestCase;

final class ApiKeyTest extends TestCase
{
    public function testGeneratedKeyIsWellFormedAndUnique(): void
    {
        $apiKey = ApiKey::generate();

        self::assertStringStartsWith(ApiKey::PREFIX, $apiKey);
        self::assertTrue(ApiKey::isWellFormed($apiKey));
        self::assertNotSame($apiKey, ApiKey::generate());
    }

    public function testHashIsStableAndDoesNotContainTheKey(): void
    {
        $apiKey = ApiKey::generate();
        $hash = ApiKey::hash($apiKey);

        self::assertSame($hash, ApiKey::hash($apiKey));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $hash);
        self::assertStringNotContainsString($apiKey, $hash);
        self::assertNotSame($hash, ApiKey::hash(ApiKey::generate()));
    }

    /**
     * 端末が生成したUUIDなど、クライアントが選んだ値をそのまま認証情報として
     * 受け付けない。
     */
    public function testMalformedKeysAreRejected(): void
    {
        $malformedKeys = [
            '',
            'not-a-key',
            '3f1c9c4e-2a55-4c1b-9c2a-0b8f6b7d5e41',
            ApiKey::PREFIX,
            ApiKey::generate() . 'x',
            substr(ApiKey::generate(), 0, -1),
            strtoupper(ApiKey::PREFIX) . substr(ApiKey::generate(), 4),
        ];

        foreach ($malformedKeys as $malformedKey) {
            self::assertFalse(
                ApiKey::isWellFormed($malformedKey),
                sprintf('形式不正として扱われませんでした: %s', $malformedKey),
            );
        }
    }
}
