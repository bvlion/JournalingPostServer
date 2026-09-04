<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Unit;

use JournalingPostServer\Http\PrivacyPolicyAction;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * プライバシーポリシーの公開ページが、Markdown本文をHTMLとして返すことを確認
 * する。DBへ接続せずに応答できることも同時に確認している。
 */
final class PrivacyPolicyPageTest extends TestCase
{
    public function testServesTheMarkdownSourceAsAnHtmlPage(): void
    {
        $response = self::handle('GET', '/privacy-policy');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'text/html; charset=utf-8',
            $response->getHeaderLine('Content-Type'),
        );

        $body = (string) $response->getBody();
        self::assertStringStartsWith('<!DOCTYPE html>', $body);
        self::assertStringContainsString('<html lang="ja">', $body);
        self::assertStringContainsString(
            '<title>プライバシーポリシー</title>',
            $body,
        );

        // Markdownが見出し・段落へ変換され、生の記法が残らない。
        self::assertStringContainsString(
            '<h1>淡香 プライバシーポリシー</h1>',
            $body,
        );
        self::assertStringContainsString('<h2>お問い合わせ</h2>', $body);
        self::assertStringNotContainsString('## お問い合わせ', $body);
    }

    public function testSourceMarkdownIsUnderVersionControl(): void
    {
        $path = __DIR__ . '/../../resources/privacy-policy.md';

        self::assertFileExists($path);
        self::assertStringContainsString(
            '# 淡香 プライバシーポリシー',
            (string) file_get_contents($path),
        );
    }

    public function testRawHtmlAndUnsafeLinksInSourceAreNeutralised(): void
    {
        $html = PrivacyPolicyAction::renderDocument(
            "# 見出し\n\n<script>alert(1)</script>\n\n"
                . "[link](javascript:alert(1))\n",
        );

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('javascript:alert(1)', $html);
        self::assertStringContainsString('<h1>見出し</h1>', $html);
    }

    public function testJsonErrorResponsesAreStillJson(): void
    {
        $response = self::handle('GET', '/undefined-route');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            'application/json; charset=utf-8',
            $response->getHeaderLine('Content-Type'),
        );
    }

    private static function handle(
        string $method,
        string $path,
    ): ResponseInterface {
        /** @var callable(): App<null> $createApplication */
        $createApplication = require __DIR__ . '/../../bootstrap/app.php';

        return $createApplication()->handle(
            (new ServerRequestFactory())->createServerRequest($method, $path),
        );
    }
}
