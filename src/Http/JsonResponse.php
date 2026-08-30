<?php

declare(strict_types=1);

namespace JournalingPostServer\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * 応答のJSON表現を1か所に揃える。
 */
final class JsonResponse
{
    public const CONTENT_TYPE = 'application/json; charset=utf-8';

    private const ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES;

    /**
     * @param array<string, mixed> $payload
     */
    public static function encode(array $payload): string
    {
        return json_encode($payload, self::ENCODE_FLAGS);
    }

    public static function writeBody(
        ResponseInterface $response,
        string $body,
    ): ResponseInterface {
        $response = $response->withHeader('Content-Type', self::CONTENT_TYPE);
        $response->getBody()->write($body);

        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function write(
        ResponseInterface $response,
        array $payload,
    ): ResponseInterface {
        return self::writeBody($response, self::encode($payload));
    }
}
