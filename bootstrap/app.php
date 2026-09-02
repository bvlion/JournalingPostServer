<?php

declare(strict_types=1);

use JournalingPostServer\Analysis\AnalysisRequestRepository;
use JournalingPostServer\Analysis\Analyzer;
use JournalingPostServer\Analysis\OpenAi\CurlResponsesTransport;
use JournalingPostServer\Analysis\OpenAi\OpenAiAnalyzer;
use JournalingPostServer\Database\ConnectionFactory;
use JournalingPostServer\Http\AuthenticationMiddleware;
use JournalingPostServer\Http\CreateAnalysisAction;
use JournalingPostServer\Http\ErrorHandler;
use JournalingPostServer\Http\JsonResponse;
use JournalingPostServer\Http\RegisterInstallationAction;
use JournalingPostServer\Installation\InstallationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Factory\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';

$configuration = require __DIR__ . '/config.php';

/**
 * アプリケーションを構築する。
 *
 * DIコンテナは導入せず、依存の組み立てをこの1か所に集める。テストがAI解析だけを
 * 差し替えられるよう、Analyzerのみ引数で受け取る。省略時は実OpenAI Analyzer
 * （`OpenAiAnalyzer` + curl transport）を使う。
 *
 * @return callable(?Analyzer): App<null>
 */
return static function (?Analyzer $analyzer = null) use ($configuration): App {
    $databaseConfiguration = $configuration['database'];
    $connection = null;

    // 未定義ルートの応答などDBを必要としない経路で接続しないよう、遅延生成する。
    $connectionProvider = static function () use (
        &$connection,
        $databaseConfiguration,
    ): \PDO {
        return $connection ??= (new ConnectionFactory(
            $databaseConfiguration['host'],
            $databaseConfiguration['port'],
            $databaseConfiguration['name'],
            $databaseConfiguration['user'],
            $databaseConfiguration['password'],
        ))->create();
    };

    $installations = new InstallationRepository($connectionProvider);
    $analysisRequests = new AnalysisRequestRepository($connectionProvider);

    $app = AppFactory::create();
    $app->addRoutingMiddleware();

    $app->post(
        '/v1/installations',
        new RegisterInstallationAction($installations),
    );
    $app->post(
        '/v1/analyses',
        new CreateAnalysisAction(
            $analysisRequests,
            $analyzer ?? new OpenAiAnalyzer(
                new CurlResponsesTransport(
                    $configuration['analysis']['openAiTimeoutSeconds'],
                ),
                $configuration['analysis']['openAiApiKey'],
                $configuration['analysis']['systemPrompt'],
                $configuration['analysis']['analysisRules'],
            ),
            $configuration['analysis']['fingerprintSecret'],
        ),
    )->add(new AuthenticationMiddleware($installations));

    $app->add(
        static function (
            ServerRequestInterface $request,
            RequestHandlerInterface $handler,
        ): ResponseInterface {
            return $handler
                ->handle($request)
                ->withHeader('Content-Type', JsonResponse::CONTENT_TYPE);
        },
    );

    // 例外の詳細をHTTP応答へ出力しない（秘密情報・内部情報の漏洩防止）。
    // エラー応答の形は`ErrorHandler`が契約どおりに揃える。
    $errorMiddleware = $app->addErrorMiddleware(false, false, false);
    $errorMiddleware->setDefaultErrorHandler(
        new ErrorHandler($app->getResponseFactory()),
    );

    return $app;
};
