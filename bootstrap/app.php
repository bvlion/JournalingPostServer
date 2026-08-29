<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/config.php';

$app = AppFactory::create();
$app->addRoutingMiddleware();

// APIルートはIssue #2以降で追加する。現時点では未定義パスに対する
// JSONエラー応答だけを提供する。

$app->add(
    function (
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        return $handler
            ->handle($request)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    },
);

// 例外の詳細をHTTP応答へ出力しない（秘密情報・内部情報の漏洩防止）。
$errorMiddleware = $app->addErrorMiddleware(false, false, false);
$errorHandler = $errorMiddleware->getDefaultErrorHandler();
$errorHandler->forceContentType('application/json');

return $app;
