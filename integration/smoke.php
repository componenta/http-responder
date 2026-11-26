<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Componenta\Config\Config;
use Componenta\Config\ConfigKey;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Http\Emitter;
use Componenta\Http\EmitterConfigProvider;
use Componenta\Http\EmitterInterface;
use Componenta\Http\Responder;
use Componenta\Http\ResponderConfigProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$definitions = array_replace_recursive(
    (new ResponderConfigProvider())()[ConfigKey::DEPENDENCIES],
    (new EmitterConfigProvider())()[ConfigKey::DEPENDENCIES],
);
$definitions[ConfigKey::SERVICES][Psr17Factory::class] = new Psr17Factory();
$definitions[ConfigKey::ALIASES][ResponseFactoryInterface::class] = Psr17Factory::class;
$definitions[ConfigKey::ALIASES][StreamFactoryInterface::class] = Psr17Factory::class;
$assembly = new ContainerFactory();
$config = new Config([], new Environment([]));
$dependencies = new DependencyDefinitions($definitions);
$value = match ($argv[1] ?? '') {
    'full' => $assembly->create($config, $dependencies),
    'targeted' => $assembly->createWithTargetedAttributeDiscovery($config, $dependencies),
    default => throw new LogicException('Unknown DI discovery strategy.'),
};
$container = $value->container;
$responder = $container->get(Responder::class);
$emitter = $container->get(EmitterInterface::class);
check($responder instanceof Responder, 'Wrong responder service.');
check($emitter instanceof Emitter, 'Wrong emitter alias.');

$response = $responder->json(['ok' => true], 201);
check($response->getStatusCode() === 201, 'Wrong response status.');
check($response->getHeaderLine('Content-Length') === '11', 'Wrong content length.');
check($response->getHeaderLine('Content-Type') === 'application/json; charset=utf-8', 'Wrong content type.');
check((string) $responder->empty(204)->getBody() === '', 'Bodyless response contains data.');
check($responder->download('payload', 'data.txt', 'text/plain')->hasHeader('Accept-Ranges') === false, 'Unsupported ranges advertised.');
check($responder->back('https://other.example/', '/safe')->getHeaderLine('Location') === '/safe', 'Untrusted redirect accepted.');
// The real detector is only a dependency of the response construction;
// the detector's own classification behavior is outside this fixture.
$detected = $responder->respond(content: 'plain text');
check($detected->getHeaderLine('Content-Type') !== '', 'MIME value object not converted into a header.');
$emitter->emit($response);
