<?php

declare(strict_types=1);

use App\Kernel;
use App\Tests\TestingDatabaseCaching;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$_SERVER['APP_ENV'] = 'test';
$_ENV['APP_ENV'] = 'test';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

$kernel = new Kernel('test', true);
$kernel->boot();

TestingDatabaseCaching::refresh($kernel);

$kernel->shutdown();
