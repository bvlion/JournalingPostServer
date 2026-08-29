<?php

declare(strict_types=1);

use Slim\App;

/** @var App<null> $app */
$app = require __DIR__ . '/../bootstrap/app.php';
$app->run();
