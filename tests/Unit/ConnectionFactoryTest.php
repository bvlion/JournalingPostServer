<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Unit;

use DateTimeZone;
use JournalingPostServer\Database\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConnectionFactoryTest extends TestCase
{
    public function testConnectionFailureDoesNotExposeConfiguration(): void
    {
        $configurationValues = [
            '127.0.0.1',
            '1',
            'example_database',
            'example_database_user',
            'example_database_password',
        ];
        $connectionFactory = new ConnectionFactory(
            ...$configurationValues,
            timeZone: new DateTimeZone('UTC'),
        );

        try {
            $connectionFactory->create();
            self::fail('データベース接続が失敗しませんでした。');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Database connection failed.',
                $exception->getMessage(),
            );
            self::assertNull($exception->getPrevious());

            foreach ($configurationValues as $configurationValue) {
                self::assertStringNotContainsString(
                    $configurationValue,
                    $exception->getMessage(),
                );
            }
        }
    }
}
