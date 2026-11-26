<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Componenta\Http\Emitter;
use Componenta\Http\Responder;
use GuzzleHttp\Psr7\HttpFactory;
use Nyholm\Psr7\Factory\Psr17Factory;

$factory = match (getenv('COMPONENTA_PSR17')) {
    'nyholm' => new Psr17Factory(),
    'guzzle' => new HttpFactory(),
    default => throw new LogicException('Unknown PSR-17 implementation.'),
};
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
    'denied' => $responder->forbidden('denied'),
    default => throw new LogicException('Unknown interoperability scenario.'),
};

if ($scenario === 'denied') {
    header('X-Accel-Redirect: /internal/old');
    header('X-Sendfile: /srv/old');
    header('Content-Encoding: gzip');
    header('ETag: "old"');
}
(new Emitter(chunkSize: 3))->emit($response);
