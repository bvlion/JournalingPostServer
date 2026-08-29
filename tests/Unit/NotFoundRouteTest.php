<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

final class NotFoundRouteTest extends TestCase
{
    public function testUndefinedRouteReturnsJsonError(): void
    {
        /** @var App<null> $app */
        $app = require __DIR__ . '/../../bootstrap/app.php';
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            '/undefined-route',
        );

        $response = $app->handle($request);
        $responseBody = (string) $response->getBody();
        $responsePayload = json_decode(
            $responseBody,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            'application/json',
            $response->getHeaderLine('Content-Type'),
        );
        self::assertIsArray($responsePayload);
        self::assertSame('404 Not Found', $responsePayload['message'] ?? null);
    }
}
