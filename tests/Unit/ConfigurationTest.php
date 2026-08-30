<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Unit;

use Dotenv\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
                'analysis' => [
                    'fingerprintSecret' =>
                        'example_fingerprint_secret_not_for_production_use',
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

    /**
     * 短い秘密値では鍵付きfingerprintの意味がなくなる。値そのものは例外
     * メッセージへ出さない。
     */
    public function testShortFingerprintSecretIsRejectedWithoutLeakingIt(): void
    {
        $original = $_ENV['ANALYSIS_FINGERPRINT_SECRET'];
        $tooShort = str_repeat('a', 31);
        $_ENV['ANALYSIS_FINGERPRINT_SECRET'] = $tooShort;
        $_SERVER['ANALYSIS_FINGERPRINT_SECRET'] = $tooShort;

        try {
            require __DIR__ . '/../../bootstrap/config.php';
            self::fail('短すぎる秘密値が検出されませんでした。');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'ANALYSIS_FINGERPRINT_SECRET',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString(
                $tooShort,
                $exception->getMessage(),
            );
        } finally {
            $_ENV['ANALYSIS_FINGERPRINT_SECRET'] = $original;
            $_SERVER['ANALYSIS_FINGERPRINT_SECRET'] = $original;
        }
    }

    public function testEachRequiredEnvironmentVariableIsValidated(): void
    {
        $requiredEnvironmentVariables = [
            'DB_HOST',
            'DB_PORT',
            'DB_NAME',
            'DB_USER',
            'DB_PASSWORD',
            'ANALYSIS_FINGERPRINT_SECRET',
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
