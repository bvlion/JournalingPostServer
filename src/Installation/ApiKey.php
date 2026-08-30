<?php

declare(strict_types=1);

namespace JournalingPostServer\Installation;

/**
 * Hosted APIの匿名installation向けAPI key。
 *
 * Serverが発行した高エントロピーの乱数であり、クライアントが選んだ値ではない。
 * 端末が生成したUUIDなど、クライアントが値を選べる識別子を認証情報として扱わない
 * ための前提となる。
 *
 * 値が乱数（256bit）であるため、保存にはSHA-256を使う。bcrypt等のstretchingは
 * 辞書攻撃に耐えるためのものであり、この鍵長では不要なうえ、hashでの一意検索が
 * できなくなる。
 */
final class ApiKey
{
    /** 秘密情報として検出しやすくするための接頭辞。 */
    public const PREFIX = 'jpk_';

    /** 接頭辞を除いた本体の文字数（256bitをbase64urlで表現した長さ）。 */
    private const BODY_LENGTH = 43;

    public static function generate(): string
    {
        return self::PREFIX . rtrim(
            strtr(base64_encode(random_bytes(32)), '+/', '-_'),
            '=',
        );
    }

    public static function hash(string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }

    public static function isWellFormed(string $apiKey): bool
    {
        return preg_match(
            sprintf(
                '/\A%s[A-Za-z0-9_-]{%d}\z/',
                preg_quote(self::PREFIX, '/'),
                self::BODY_LENGTH,
            ),
            $apiKey,
        ) === 1;
    }
}
