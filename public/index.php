<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new Core\App(dirname(__DIR__));
$app->boot();
$app->ensureInitialAdmin();
$app->run();
