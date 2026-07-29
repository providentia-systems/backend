<?php

declare(strict_types=1);

use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mezzio\Application;

chdir(dirname(__DIR__));
require 'vendor/autoload.php';

/** @var Psr\Container\ContainerInterface $container */
$container = require 'config/container.php';
/** @var Application $app */
$app = $container->get(Application::class);

(require 'config/pipeline.php')($app);
(require 'config/routes.php')($app);

(new SapiEmitter())->emit($app->handle(ServerRequestFactory::fromGlobals()));

