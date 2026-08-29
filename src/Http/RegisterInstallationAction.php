<?php

declare(strict_types=1);

namespace JournalingPostServer\Http;

use DateTimeImmutable;
use JournalingPostServer\Installation\InstallationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /v1/installations`
 *
 * 匿名installationを登録し、Hosted APIの認証情報を発行する。account作成は伴わず、
 * requestは本文を必要としない。Androidが保存するのはAPI keyだけである。
 *
 * Server内部のinstallation識別子は返さない。Androidから送る用途が無く、返せば
 * 端末側に不要な状態が増えるためである。
 *
 * API keyの平文を返すのはこの応答だけである。Serverはhashしか保存しないため、
 * 端末が失った場合は再登録して新しいinstallationになる。
 *
 * 登録自体のrate limit / abuse対策はIssue #5で扱う。
 */
final class RegisterInstallationAction
{
    public function __construct(
        private InstallationRepository $installations,
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
        $apiKey = $this->installations->register(new DateTimeImmutable('now'));

        return JsonResponse::write(
            $response->withStatus(201),
            ['installation' => ['apiKey' => $apiKey]],
        );
    }
}
