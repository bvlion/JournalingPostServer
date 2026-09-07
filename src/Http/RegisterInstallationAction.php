<?php

declare(strict_types=1);

namespace JournalingPostServer\Http;

use DateTimeImmutable;
use JournalingPostServer\Installation\InstallationRepository;
use JournalingPostServer\Installation\PlayIntegrity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /v1/installations`
 *
 * 匿名installationを登録し、Hosted APIの認証情報を発行する。account作成は伴わず、
 * requestはPlay Integrity tokenを必要とする。Androidが保存するのはAPI keyだけ。
 *
 * Server内部のinstallation識別子は返さない。Androidから送る用途が無く、返せば
 * 端末側に不要な状態が増えるためである。
 *
 * API keyの平文を返すのはこの応答だけである。Serverはhashしか保存しないため、
 * 端末が失った場合は再登録して新しいinstallationになる。
 *
 * 登録時にだけGoogle Playのapp recognition / licensingを確認する。
 */
final class RegisterInstallationAction
{
    public function __construct(
        private InstallationRepository $installations,
        private PlayIntegrity $integrity,
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
        $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        if ($contentType !== 'application/json') {
            throw new ApiException(
                415,
                'unsupported_media_type',
                'The request must use Content-Type: application/json.',
            );
        }
        $body = $request->getBody()->read(32769);
        if (strlen($body) > 32768 || !$request->getBody()->eof()) {
            throw new ApiException(413, 'payload_too_large', 'The registration request is too large.');
        }
        $payload = json_decode($body);
        if (!$payload instanceof \stdClass) {
            throw new ApiException(400, 'invalid_request', 'The request must be a JSON object.');
        }
        if (
            !is_string($payload->integrityToken ?? null) || $payload->integrityToken === ''
            || !is_string($payload->registrationId ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/', $payload->registrationId) !== 1
        ) {
            throw new ApiException(
                422,
                'validation_error',
                'integrityToken and a 64 character hexadecimal registrationId are required.',
            );
        }
        $this->integrity->verify($payload->integrityToken, $payload->registrationId);
        $apiKey = $this->installations->register(new DateTimeImmutable('now'));

        return JsonResponse::write(
            $response->withStatus(201),
            ['installation' => ['apiKey' => $apiKey]],
        );
    }
}
