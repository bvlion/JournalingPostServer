<?php

declare(strict_types=1);

namespace JournalingPostServer\Http;

use Michelf\Markdown;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * `GET /privacy-policy`
 *
 * プライバシーポリシー本文をHTMLページとして返す公開エンドポイント。Android
 * アプリとPlay Consoleが参照する通常の公開URLとして使う。Hosted API（`/v1`）
 * とは別で、認証もJSONも伴わない。
 *
 * 本文は`resources/privacy-policy.md`でMarkdownとして管理し、更新は通常の
 * Git履歴として残る。Markdown→HTMLの変換はリクエスト内で行う。アクセス頻度が
 * 低く、事前生成の運用（デプロイ手順・生成物のcommit）を増やす利点が無いため
 * である。
 */
final class PrivacyPolicyAction
{
    /** ページの`<title>`。Markdown内の見出しとは独立に固定する。 */
    private const DOCUMENT_TITLE = 'プライバシーポリシー';

    public function __construct(
        private string $markdownFile,
    ) {
    }

    /**
     * @param array<string, string> $arguments
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        $response->getBody()->write(
            self::renderDocument($this->readSource()),
        );

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Markdown本文を、単体で完結する1ページのHTML文書へ変換する。
     */
    public static function renderDocument(string $markdown): string
    {
        $title = self::escape(self::DOCUMENT_TITLE);
        $body = self::toHtml($markdown);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="ja">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$title}</title>
            <style>
            :root { color-scheme: light dark; }
            body {
                margin: 0 auto;
                max-width: 42rem;
                padding: 2rem 1.25rem 4rem;
                line-height: 1.8;
                font-family: system-ui, -apple-system, "Segoe UI", "Hiragino Sans",
                    "Noto Sans JP", Meiryo, sans-serif;
                word-wrap: break-word;
            }
            h1 { font-size: 1.6rem; line-height: 1.4; }
            h2 { margin-top: 2.5rem; font-size: 1.25rem; }
            h3 { margin-top: 1.75rem; font-size: 1.05rem; }
            ul, ol { padding-left: 1.4rem; }
            li { margin: 0.3rem 0; }
            </style>
            </head>
            <body>
            {$body}
            </body>
            </html>
            HTML;
    }

    private function readSource(): string
    {
        if (is_file($this->markdownFile) && is_readable($this->markdownFile)) {
            $markdown = file_get_contents($this->markdownFile);

            if ($markdown !== false) {
                return $markdown;
            }
        }

        // 本文はリポジトリで管理され各リリースへ同梱される。読めないのは
        // リリースの不備であり、内容を伴わない`internal_error`へ倒す。
        throw new RuntimeException(
            'The privacy policy source could not be read.',
        );
    }

    private static function toHtml(string $markdown): string
    {
        $parser = new Markdown();

        // 本文はリポジトリ管理の信頼できる内容だが、公開ページのため多層で守る。
        // 生HTMLはそのまま通さず、リンク先はhttp(s)・mailto・ページ内に限る。
        $parser->no_markup = true;
        $parser->no_entities = true;
        $parser->url_filter_func = static function (string $url): string {
            return preg_match('~\A(?:https?:|mailto:|[/#])~i', $url) === 1
                ? $url
                : '#';
        };

        return $parser->transform($markdown);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
    }
}
