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

        // 解析指示本文は実行環境からプレーンテキストで受け取る。実値に依存しない
        // よう、非空の文字列であることだけを確認してから残りを厳密比較する。
        self::assertIsString($configuration['analysis']['systemPrompt']);
        self::assertNotSame('', trim($configuration['analysis']['systemPrompt']));
        self::assertIsString($configuration['analysis']['analysisRules']);
        self::assertNotSame('', trim($configuration['analysis']['analysisRules']));
        unset(
            $configuration['analysis']['systemPrompt'],
            $configuration['analysis']['analysisRules'],
        );

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

    /**
     * 解析指示本文は実行時のプレーンテキストファイルから読む。1行目を system
     * prompt、残りを分析ルール本文として渡す（間の空行は任意）。配置先は
     * `ANALYSIS_INSTRUCTION_FILE` で差し替えられる。
     */
    public function testAnalysisInstructionIsReadFromPlainTextFile(): void
    {
        $saved = self::withAnalysisInstructionFile();
        $file = tempnam(sys_get_temp_dir(), 'instr');
        file_put_contents(
            $file,
            "架空のsystem prompt行\n\n架空の分析ルール本文。\n- good: 架空。\n",
        );

        try {
            $_ENV['ANALYSIS_INSTRUCTION_FILE']
                = $_SERVER['ANALYSIS_INSTRUCTION_FILE'] = $file;

            $configuration = require __DIR__ . '/../../bootstrap/config.php';

            self::assertSame(
                '架空のsystem prompt行',
                $configuration['analysis']['systemPrompt'],
            );
            self::assertSame(
                "架空の分析ルール本文。\n- good: 架空。",
                $configuration['analysis']['analysisRules'],
            );
        } finally {
            unlink($file);
            self::restoreAnalysisInstructionFile($saved);
        }
    }

    /**
     * ファイルが無い・system prompt行だけで分析ルール本文が無い・1行目が空白
     * だけの場合は、内容を出力せずに起動を失敗させる。例外メッセージへ本文を
     * 載せない。1行目の空白のみ拒否は、デプロイ側の事前検証（`bin/deploy-remote.sh`）
     * と判定を一致させるためにテストで固定する。
     */
    public function testMissingOrMalformedAnalysisInstructionFileFailsToBoot(): void
    {
        $saved = self::withAnalysisInstructionFile();
        $marker = 'MARKER-fake-instruction-not-a-production-value';

        $systemPromptOnlyFile = tempnam(sys_get_temp_dir(), 'instr');
        file_put_contents($systemPromptOnlyFile, $marker);

        $blankSystemPromptFile = tempnam(sys_get_temp_dir(), 'instr');
        file_put_contents($blankSystemPromptFile, "   \n\n" . $marker);

        $missingPath = sys_get_temp_dir() . '/does-not-exist-' . uniqid() . '.txt';

        try {
            foreach (
                [$missingPath, $systemPromptOnlyFile, $blankSystemPromptFile] as $path
            ) {
                $_ENV['ANALYSIS_INSTRUCTION_FILE']
                    = $_SERVER['ANALYSIS_INSTRUCTION_FILE'] = $path;

                $exception = null;
                ob_start();

                try {
                    require __DIR__ . '/../../bootstrap/config.php';
                } catch (RuntimeException $caught) {
                    $exception = $caught;
                }

                $emitted = ob_get_clean();

                self::assertInstanceOf(
                    RuntimeException::class,
                    $exception,
                    sprintf('"%s" が拒否されませんでした。', $path),
                );
                self::assertSame('', $emitted);
                self::assertStringNotContainsString(
                    $marker,
                    $exception->getMessage(),
                );
                self::assertStringContainsString(
                    'analysis instruction',
                    $exception->getMessage(),
                );
            }
        } finally {
            unlink($systemPromptOnlyFile);
            unlink($blankSystemPromptFile);
            self::restoreAnalysisInstructionFile($saved);
        }
    }

    /**
     * @return array{string|null, string|null}
     */
    private static function withAnalysisInstructionFile(): array
    {
        $saved = [
            $_ENV['ANALYSIS_INSTRUCTION_FILE'] ?? null,
            $_SERVER['ANALYSIS_INSTRUCTION_FILE'] ?? null,
        ];
        unset(
            $_ENV['ANALYSIS_INSTRUCTION_FILE'],
            $_SERVER['ANALYSIS_INSTRUCTION_FILE'],
        );

        return $saved;
    }

    /**
     * @param array{string|null, string|null} $saved
     */
    private static function restoreAnalysisInstructionFile(array $saved): void
    {
        [$env, $server] = $saved;

        if ($env === null) {
            unset($_ENV['ANALYSIS_INSTRUCTION_FILE']);
        } else {
            $_ENV['ANALYSIS_INSTRUCTION_FILE'] = $env;
        }

        if ($server === null) {
            unset($_SERVER['ANALYSIS_INSTRUCTION_FILE']);
        } else {
            $_SERVER['ANALYSIS_INSTRUCTION_FILE'] = $server;
        }
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
