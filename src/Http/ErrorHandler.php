<?php

declare(strict_types=1);

namespace JournalingPostServer\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * すべてのエラー応答を1つの契約
 * （`{"error": {"code": ..., "message": ..., "details": [...]}}`）へ揃える。
 *
 * 想定外の例外はmessageをそのまま出さず`internal_error`へ潰す。例外メッセージへ
 * JournalEntry本文やAPI keyが混ざっていても応答へ出さないためである。
 */
final class ErrorHandler
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        $apiException = $this->toApiException($exception);
        $payload = [
            'error' => [
                'code' => $apiException->errorCode(),
                'message' => $apiException->getMessage(),
            ],
        ];

        if ($apiException->details() !== []) {
            $payload['error']['details'] = $apiException->details();
        }

        $response = $this->responseFactory
            ->createResponse($apiException->status());

        foreach ($apiException->headers() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return JsonResponse::write($response, $payload);
    }

    private function toApiException(Throwable $exception): ApiException
    {
        if ($exception instanceof ApiException) {
            return $exception;
        }

        if ($exception instanceof HttpNotFoundException) {
            return new ApiException(
                404,
                'not_found',
                'The requested endpoint does not exist.',
            );
        }

        if ($exception instanceof HttpMethodNotAllowedException) {
            return new ApiException(
                405,
                'method_not_allowed',
                'The HTTP method is not allowed for this endpoint.',
                headers: [
                    'Allow' => implode(', ', $exception->getAllowedMethods()),
                ],
            );
        }

        return new ApiException(
            500,
            'internal_error',
            'The server failed to process the request.',
        );
    }
}
