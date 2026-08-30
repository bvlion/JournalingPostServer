<?php

declare(strict_types=1);

use Slim\App;

// Apacheは`Authorization`ヘッダーをそのままPHPへ渡さないため、`public/.htaccess`の
// Rewriteで環境変数へ転送している。転送値は内部リダイレクトを経て
// `REDIRECT_HTTP_AUTHORIZATION`として届くことがあるため、ここで揃える。
if (
    !isset($_SERVER['HTTP_AUTHORIZATION'])
    && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
) {
    $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}

/** @var callable(): App<null> $createApplication */
$createApplication = require __DIR__ . '/../bootstrap/app.php';
$createApplication()->run();
