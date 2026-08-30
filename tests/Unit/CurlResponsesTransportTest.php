<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Unit;

use JournalingPostServer\Analysis\OpenAi\CurlResponsesTransport;
use JournalingPostServer\Analysis\OpenAi\OpenAiUnreachableException;
use PHPUnit\Framework\TestCase;

/**
 * curl transportのうち、外部ネットワークなしで確認できる範囲。
 *
 * 実OpenAIへは接続しない。実provider / XServerでの応答時間・timeout測定は
 * 自動テストの対象外で、`docs/hosted-analysis-api.md`の測定項目で扱う。
 */
final class CurlResponsesTransportTest extends TestCase
{
    /**
     * 接続を確立できない場合はrequestが送信されていないため、確定失敗
     * （`OpenAiUnreachableException`）として扱う。呼び出し元はclaimを解放できる。
     */
    public function testConnectionRefusedIsReportedAsUnreachable(): void
    {
        $transport = new CurlResponsesTransport(2);

        $this->expectException(OpenAiUnreachableException::class);

        // ローカルの閉じているポート。外部通信は発生しない。
        $transport->post(
            'https://127.0.0.1:1/v1/responses',
            ['Content-Type' => 'application/json'],
            '{}',
        );
    }

    /**
     * curlのerror文にrequest bodyやヘッダー（API key）を載せない。
     */
    public function testTransportFailureMessageDoesNotcontainRequestContent(): void
    {
        $transport = new CurlResponsesTransport(2);

        try {
            $transport->post(
                'https://127.0.0.1:1/v1/responses',
                ['Authorization' => 'Bearer sk-fake-secret-value-123'],
                '{"secret":"do-not-leak"}',
            );
            self::fail('例外が投げられませんでした。');
        } catch (OpenAiUnreachableException $exception) {
            self::assertStringNotContainsString(
                'sk-fake-secret-value-123',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString(
                'do-not-leak',
                $exception->getMessage(),
            );
        }
    }
}
