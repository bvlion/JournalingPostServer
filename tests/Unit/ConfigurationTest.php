<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Unit;

use Dotenv\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    public function testConfigurationIsLoadedFromEnvironmentVariables(): void
    {
        $configuration = require __DIR__ . '/../../bootstrap/config.php';

        self::assertSame(
            [
                'database' => [
                    'host' => 'database',
                    'port' => '3306',
                    'name' => 'example_database',
                    'user' => 'example_database_user',
                    'password' => 'example_database_password',
                ],
            ],
            $configuration,
        );
    }

    public function testDefaultTimeZoneIsFixedToUtc(): void
    {
        require __DIR__ . '/../../bootstrap/config.php';

        self::assertSame('UTC', date_default_timezone_get());
    }

    public function testEachRequiredEnvironmentVariableIsValidated(): void
    {
        $requiredEnvironmentVariables = [
            'DB_HOST',
            'DB_PORT',
            'DB_NAME',
            'DB_USER',
            'DB_PASSWORD',
        ];

        foreach ($requiredEnvironmentVariables as $environmentVariable) {
            $environmentVariableValue = $_ENV[$environmentVariable];
            $serverEnvironmentVariableValue = $_SERVER[$environmentVariable]
                ?? null;
            $_ENV[$environmentVariable] = '';
            $_SERVER[$environmentVariable] = '';

            try {
                require __DIR__ . '/../../bootstrap/config.php';
                self::fail(
                    sprintf(
                        '%sの欠落が検出されませんでした。',
                        $environmentVariable,
                    ),
                );
            } catch (ValidationException $exception) {
                self::assertStringContainsString(
                    $environmentVariable,
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    $environmentVariableValue,
                    $exception->getMessage(),
                );
            } finally {
                $_ENV[$environmentVariable] = $environmentVariableValue;

                if ($serverEnvironmentVariableValue === null) {
                    unset($_SERVER[$environmentVariable]);
                } else {
                    $_SERVER[$environmentVariable] =
                        $serverEnvironmentVariableValue;
                }
            }
        }
    }
}
