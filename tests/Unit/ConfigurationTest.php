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

        // 解析指示本文は実行環境の設定ファイルから読む。実値に依存しないよう、
        // 非空の文字列であることだけを確認してから残りを厳密比較する。
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
     * 解析指示本文の設定ファイルが欠落・不正な場合は、内容を出力せずに起動を
     * 失敗させる。配置先は `ANALYSIS_INSTRUCTION_FILE` で差し替えられる。
     */
    public function testMissingOrInvalidAnalysisInstructionFileFailsToBoot(): void
    {
        $original = $_ENV['ANALYSIS_INSTRUCTION_FILE'] ?? null;
        $secret = 'この文字列は例外メッセージに現れてはならない';
        $invalidFile = tempnam(sys_get_temp_dir(), 'instr');
        file_put_contents(
            $invalidFile,
            "<?php\n\nreturn ['systemPrompt' => '{$secret}', 'rules' => ''];\n",
        );

        $cases = [
            sys_get_temp_dir() . '/does-not-exist-' . uniqid() . '.php',
            $invalidFile,
        ];

        try {
            foreach ($cases as $path) {
                $_ENV['ANALYSIS_INSTRUCTION_FILE'] = $path;
                $_SERVER['ANALYSIS_INSTRUCTION_FILE'] = $path;

                try {
                    require __DIR__ . '/../../bootstrap/config.php';
                    self::fail(sprintf('"%s" が拒否されませんでした。', $path));
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString(
                        'analysis instruction',
                        $exception->getMessage(),
                    );
                    self::assertStringNotContainsString(
                        $secret,
                        $exception->getMessage(),
                    );
                }
            }
        } finally {
            unlink($invalidFile);

            if ($original === null) {
                unset(
                    $_ENV['ANALYSIS_INSTRUCTION_FILE'],
                    $_SERVER['ANALYSIS_INSTRUCTION_FILE'],
                );
            } else {
                $_ENV['ANALYSIS_INSTRUCTION_FILE'] = $original;
                $_SERVER['ANALYSIS_INSTRUCTION_FILE'] = $original;
            }
        }
    }

    /**
     * 解析指示の設定ファイルの評価中に出力が起きても（`<?php`前後の地の文・
     * `echo`・パースエラー等）、その内容をHTTP応答・標準出力・通常ログ・例外
     * メッセージへ出さずに失敗する。テストはproduction用の指示本文を含めず、
     * 架空のマーカー文字列だけで検証する。
     */
    public function testAnalysisInstructionFileOutputIsNeverEmittedOnFailure(): void
    {
        $original = $_ENV['ANALYSIS_INSTRUCTION_FILE'] ?? null;
        $marker = 'MARKER-fake-instruction-body-not-a-production-value';

        // require が中身をそのまま標準出力へ流すファイル。
        $plainText = tempnam(sys_get_temp_dir(), 'instr');
        file_put_contents($plainText, $marker . "\n");

        // 正しい配列を return する前に echo するファイル。
        $echoesThenReturns = tempnam(sys_get_temp_dir(), 'instr');
        file_put_contents(
            $echoesThenReturns,
            "<?php echo '{$marker}';\n"
                . "return ['systemPrompt' => 'x', 'rules' => 'y'];\n",
        );

        // パースエラーを起こすファイル。
        $parseError = tempnam(sys_get_temp_dir(), 'instr');
        file_put_contents(
            $parseError,
            "<?php return ['systemPrompt' => '{$marker}'\n",
        );

        try {
            foreach ([$plainText, $echoesThenReturns, $parseError] as $path) {
                $_ENV['ANALYSIS_INSTRUCTION_FILE'] = $path;
                $_SERVER['ANALYSIS_INSTRUCTION_FILE'] = $path;

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
                self::assertStringNotContainsString($marker, $emitted);
                self::assertStringNotContainsString(
                    $marker,
                    $exception->getMessage(),
                );
            }
        } finally {
            unlink($plainText);
            unlink($echoesThenReturns);
            unlink($parseError);

            if ($original === null) {
                unset(
                    $_ENV['ANALYSIS_INSTRUCTION_FILE'],
                    $_SERVER['ANALYSIS_INSTRUCTION_FILE'],
                );
            } else {
                $_ENV['ANALYSIS_INSTRUCTION_FILE'] = $original;
                $_SERVER['ANALYSIS_INSTRUCTION_FILE'] = $original;
            }
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
