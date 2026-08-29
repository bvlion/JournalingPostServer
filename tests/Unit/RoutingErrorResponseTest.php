<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * ルーティング段階のエラーも、他のエラーと同じ契約
 * （`{"error": {"code": ...}}`）で返ることを確認する。
 *
 * DBへ接続せずに構築・応答できることも同時に確認している（接続はAPI経路でのみ
 * 遅延生成される）。
 */
final class RoutingErrorResponseTest extends TestCase
{
    public function testUndefinedRouteReturnsNotFoundError(): void
    {
        $response = self::handle('GET', '/undefined-route');

        self::assertSame(404, $response['status']);
        self::assertSame('not_found', $response['payload']['error']['code']);
    }

    public function testUnsupportedMethodReturnsMethodNotAllowedError(): void
    {
        $response = self::handle('GET', '/v1/analyses');

        self::assertSame(405, $response['status']);
        self::assertSame(
            'method_not_allowed',
            $response['payload']['error']['code'],
        );
        self::assertStringContainsString('POST', $response['allow']);
    }

    /**
     * @return array{status: int, allow: string, payload: array<string, mixed>}
     */
    private static function handle(string $method, string $path): array
    {
        /** @var callable(): App<null> $createApplication */
        $createApplication = require __DIR__ . '/../../bootstrap/app.php';
        $response = $createApplication()->handle(
            (new ServerRequestFactory())->createServerRequest($method, $path),
        );

        self::assertSame(
            'application/json; charset=utf-8',
            $response->getHeaderLine('Content-Type'),
        );

        $payload = json_decode(
            (string) $response->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($payload);

        return [
            'status' => $response->getStatusCode(),
            'allow' => $response->getHeaderLine('Allow'),
            'payload' => $payload,
        ];
    }
}
