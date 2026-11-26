<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Componenta\Http\Emitter;
use Componenta\Http\Responder;
use Nyholm\Psr7\Factory\Psr17Factory;

$factory = new Psr17Factory();
$responder = new Responder($factory, $factory);
$scenario = getenv('COMPONENTA_SCENARIO');

$stream = $factory->createStream('prefix:payload');
$stream->read(7);

$response = match ($scenario) {
    'json', 'head' => $responder->json(['ok' => true], 201),
    'file' => $responder->download($stream, 'data.txt', 'text/plain'),
    'range' => $responder->inline('abc', 'data.txt', 'text/plain', 206)
        ->withHeader('Content-Range', 'bytes 10-12/20'),
    'not-modified' => $responder->withEtag($responder->notModified(), 'one')
        ->withHeader('Content-Length', '14'),
    'range-error' => $responder->rangeNotSatisfiable(20),
    'offload' => $responder->xAccelRedirect('/internal/data', 'data.bin', 'application/octet-stream'),
    'detected' => $responder->respond(content: 'plain text'),
    default => throw new LogicException('Unknown interoperability scenario.'),
};

(new Emitter(chunkSize: 3))->emit($response);
