<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Integration;

use JournalingPostServer\Tests\Integration\Support\DatabaseTestCase;
use JournalingPostServer\Tests\Integration\Support\FakeAnalyzer;
use JournalingPostServer\Tests\Support\IntegrityFixture;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class RegistrationApiTest extends DatabaseTestCase
{
    public function testRegistrationRequiresVerificationAndNeverStoresTokens(): void
    {
        $connection = self::createConnection();
        $connection->exec('DELETE FROM installations');
        $fixture = new IntegrityFixture();
        $factory = require self::projectPath('bootstrap/app.php');
        $app = $factory(new FakeAnalyzer(), $fixture->verifier());
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/v1/installations')
            ->withHeader('Content-Type', 'application/json');
        foreach ([['{}', 422], ['[]', 400], [str_repeat('x', 32769), 413]] as [$body, $status]) {
            $response = $app->handle($request->withBody((new StreamFactory())->createStream($body)));
            self::assertSame($status, $response->getStatusCode());
            self::assertSame(0, $connection->query('SELECT COUNT(*) FROM installations')->fetchColumn());
        }
        self::assertSame(0, $fixture->calls);
        $request = $request->withBody((new StreamFactory())->createStream(json_encode([
            'registrationId' => $fixture->registrationId, 'integrityToken' => 'fake-token-never-persist',
        ], JSON_THROW_ON_ERROR)));
        $response = $app->handle($request);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $connection->query('SELECT COUNT(*) FROM installations')->fetchColumn());
        // Googleが再利用tokenをUNEVALUATEDとして返す場合も発行しない。
        $fixture->verdict['appIntegrity']['appRecognitionVerdict'] = 'UNEVALUATED';
        $request->getBody()->rewind();
        self::assertSame(403, $app->handle($request)->getStatusCode());
        $rows = $connection->query('SELECT * FROM installations')->fetchAll();
        self::assertCount(1, $rows);
        self::assertStringNotContainsString('fake-token-never-persist', json_encode($rows, JSON_THROW_ON_ERROR));
        self::assertSame(['id', 'api_key_hash', 'created_at'], array_keys($rows[0]));
    }
}
