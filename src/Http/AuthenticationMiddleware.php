<?php

declare(strict_types=1);

namespace JournalingPostServer\Http;

use JournalingPostServer\Installation\ApiKey;
use JournalingPostServer\Installation\InstallationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 匿名installation単位の認証。
 *
 * `Authorization: Bearer <API key>`のAPI keyをhashしてinstallationを引き当てる。
 * account / profileは扱わず、FCM tokenや任意UUIDを認証情報として受け付けない。
 *
 * 認証に成功したinstallation識別子は`installationId`属性で後段へ渡す。
 */
final class AuthenticationMiddleware implements MiddlewareInterface
{
    public const INSTALLATION_ID_ATTRIBUTE = 'installationId';

    public function __construct(
        private InstallationRepository $installations,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $apiKey = self::extractApiKey($request);

        if ($apiKey === null || !ApiKey::isWellFormed($apiKey)) {
            throw self::unauthorized();
        }

        $installationId = $this->installations->authenticate($apiKey);

        if ($installationId === null) {
            throw self::unauthorized();
        }

        return $handler->handle(
            $request->withAttribute(
                self::INSTALLATION_ID_ATTRIBUTE,
                $installationId,
            ),
        );
    }

    private static function extractApiKey(
        ServerRequestInterface $request,
    ): ?string {
        $header = $request->getHeaderLine('Authorization');

        if (preg_match('/\ABearer\s+(\S+)\z/i', $header, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private static function unauthorized(): ApiException
    {
        // 形式不正と未登録を区別しない。登録済みAPI keyの探索に使えるため。
        return new ApiException(
            401,
            'unauthorized',
            'A valid installation API key is required.',
            headers: ['WWW-Authenticate' => 'Bearer'],
        );
    }
}
