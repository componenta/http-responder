<?php

declare(strict_types=1);

$package = dirname(__DIR__);
$local = $package . '/vendor/autoload.php';
$integration = dirname($package) . '/interceptor-app/integration/vendor/autoload.php';
$loader = require is_file($local) ? $local : $integration;
$loader->addPsr4('Componenta\\Http\\', $package . '/src', true);
