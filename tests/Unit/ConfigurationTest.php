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
                    'openAiApiKey' => 'sk-example-not-a-real-openai-key',
                    'openAiTimeoutSeconds' => 45,
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

    /**
     * timeout秒数は正の整数だけを受け付ける。productionの値は実測から決めるが、
     * 設定ミスは起動時に弾く。値そのものは例外メッセージへ出さない。
     */
    public function testNonPositiveIntegerOpenAiTimeoutIsRejected(): void
    {
        $original = $_ENV['OPENAI_TIMEOUT_SECONDS'];

        try {
            foreach (['0', '-5', '12.5', 'soon', ' 30'] as $invalid) {
                $_ENV['OPENAI_TIMEOUT_SECONDS'] = $invalid;
                $_SERVER['OPENAI_TIMEOUT_SECONDS'] = $invalid;

                try {
                    require __DIR__ . '/../../bootstrap/config.php';
                    self::fail(sprintf('"%s"が拒否されませんでした。', $invalid));
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString(
                        'OPENAI_TIMEOUT_SECONDS',
                        $exception->getMessage(),
                    );
                    self::assertStringNotContainsString(
                        $invalid,
                        $exception->getMessage(),
                    );
                }
            }
        } finally {
            $_ENV['OPENAI_TIMEOUT_SECONDS'] = $original;
            $_SERVER['OPENAI_TIMEOUT_SECONDS'] = $original;
        }
    }

    /**
     * DBだけを必要とするCLI（`bin/migrate.php`・`bin/prune-expired-analyses.php`）は
     * `bootstrap/database-config.php`を使う。API keyの失効対応などで analysis /
     * OpenAI 設定が欠落・空でも、DB接続設定さえ揃っていれば起動できる。
     *
     * これがないと、`OPENAI_API_KEY`を空にした時点で5分間隔の削除Cronが継続的に
     * 失敗し、失効した解析結果本文が保持期間（30分）を越えてDBへ残り続ける。
     */
    public function testDatabaseConfigDoesNotRequireAnalysisOrOpenAiSettings(): void
    {
        $analysisVariables = [
            'ANALYSIS_FINGERPRINT_SECRET',
            'OPENAI_API_KEY',
            'OPENAI_TIMEOUT_SECONDS',
        ];
        $saved = [];

        foreach ($analysisVariables as $variable) {
            $saved[$variable] = [
                $_ENV[$variable] ?? null,
                $_SERVER[$variable] ?? null,
            ];
            $_ENV[$variable] = '';
            $_SERVER[$variable] = '';
        }

        try {
            $configuration = require __DIR__
                . '/../../bootstrap/database-config.php';

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
            self::assertArrayNotHasKey('analysis', $configuration);
            self::assertSame('UTC', date_default_timezone_get());
        } finally {
            foreach ($analysisVariables as $variable) {
                [$env, $server] = $saved[$variable];

                if ($env === null) {
                    unset($_ENV[$variable]);
                } else {
                    $_ENV[$variable] = $env;
                }

                if ($server === null) {
                    unset($_SERVER[$variable]);
                } else {
                    $_SERVER[$variable] = $server;
                }
            }
        }
    }

    /**
     * CLI用の分離でHTTP側の必須検証が緩まないこと。`bootstrap/config.php`は
     * DB接続設定に加え analysis / OpenAI 設定も欠落・空を弾く。
     */
    public function testHttpConfigStillRejectsMissingAnalysisOrOpenAiSettings(): void
    {
        foreach (
            [
                'ANALYSIS_FINGERPRINT_SECRET',
                'OPENAI_API_KEY',
                'OPENAI_TIMEOUT_SECONDS',
            ] as $variable
        ) {
            $savedEnv = $_ENV[$variable] ?? null;
            $savedServer = $_SERVER[$variable] ?? null;
            $_ENV[$variable] = '';
            $_SERVER[$variable] = '';

            try {
                require __DIR__ . '/../../bootstrap/config.php';
                self::fail(sprintf(
                    '%sの欠落がHTTP設定で検出されませんでした。',
                    $variable,
                ));
            } catch (ValidationException $exception) {
                self::assertStringContainsString(
                    $variable,
                    $exception->getMessage(),
                );
            } finally {
                if ($savedEnv === null) {
                    unset($_ENV[$variable]);
                } else {
                    $_ENV[$variable] = $savedEnv;
                }

                if ($savedServer === null) {
                    unset($_SERVER[$variable]);
                } else {
                    $_SERVER[$variable] = $savedServer;
                }
            }
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
            'OPENAI_API_KEY',
            'OPENAI_TIMEOUT_SECONDS',
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
