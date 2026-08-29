<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Integration;

use JournalingPostServer\Tests\Integration\Support\DatabaseTestCase;

final class DatabaseConnectionTest extends DatabaseTestCase
{
    public function testConnectionUsesUtf8mb4AndConfiguredTimeZone(): void
    {
        $connection = self::createConnection();

        self::assertSame(
            '+00:00',
            $connection
                ->query('SELECT @@session.time_zone')
                ->fetchColumn(),
        );
        self::assertSame(
            'utf8mb4',
            $connection
                ->query('SELECT @@session.character_set_client')
                ->fetchColumn(),
        );
    }
}
