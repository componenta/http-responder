<?php

declare(strict_types=1);

namespace Componenta\Http\Tests;

use Componenta\Http\Responder;
use GuzzleHttp\Psr7\HttpFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

dataset('psr17 factories', [
    'Nyholm' => [static fn () => new Psr17Factory()],
    'Guzzle' => [static fn () => new HttpFactory()],
]);

it('constructs equivalent responses with independent PSR-17 implementations', function (\Closure $make): void {
    $factory = $make();
    $responder = new Responder($factory, $factory);
    $json = $responder->json(['ok' => true], 201);
    expect($json->getStatusCode())->toBe(201)
        ->and($json->getHeaderLine('Content-Type'))->toBe('application/json; charset=utf-8')
        ->and($json->getHeaderLine('Content-Length'))->toBe('11')
        ->and($json->getBody()->getContents())->toBe('{"ok":true}');

    $stream = $factory->createStream('prefix:payload');
    $stream->read(7);
    $download = $responder->download($stream, 'отчёт.txt', 'text/plain');
    expect($download->getBody())->toBe($stream)
        ->and($download->getBody()->getContents())->toBe('prefix:payload')
        ->and($download->getHeaderLine('Content-Length'))->toBe('14')
        ->and($download->getHeaderLine('Content-Disposition'))->toBe("attachment; filename=\"_____.txt\"; filename*=UTF-8''" . rawurlencode('отчёт.txt'))
        ->and($download->hasHeader('Accept-Ranges'))->toBeFalse();

    expect($responder->empty(204)->getBody()->getContents())->toBe('')
        ->and($responder->back('https://outside.example', '/safe')->getHeaderLine('Location'))->toBe('/safe')
        ->and($responder->respond(content: $json))->toBe($json);
})->with('psr17 factories');

it('does not inherit conflicting representation or offload metadata from a response factory', function (\Closure $make): void {
    $factory = $make();
    $responses = new class($factory) implements ResponseFactoryInterface {
        public function __construct(private readonly ResponseFactoryInterface $factory) {}
        public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
        {
            return $this->factory->createResponse($code, $reasonPhrase)
                ->withHeader('Content-Encoding', 'gzip')
                ->withHeader('Content-Disposition', 'attachment; filename=old.txt')
                ->withHeader('Content-Range', 'bytes 0-2/20')
                ->withHeader('Accept-Ranges', 'bytes')
                ->withHeader('ETag', '"stale"')
                ->withHeader('X-Accel-Redirect', '/internal/old')
                ->withHeader('X-Sendfile', '/srv/old')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        }
    };

    $responder = new Responder($responses, $factory);
    foreach ([$responder->text('new'), $responder->empty(204), $responder->download('new', 'new.txt', 'text/plain'), $responder->xAccelRedirect('/internal/new', 'new.txt', 'text/plain')] as $response) {
        foreach (['Content-Encoding', 'Content-Range', 'Accept-Ranges', 'ETag', 'X-Sendfile'] as $header) {
            expect($response->hasHeader($header))->toBeFalse();
        }
        expect($response->getHeaderLine('X-Content-Type-Options'))->toBe('nosniff');
    }

    $text = $responder->text('new');
    expect($text->hasHeader('Content-Disposition'))->toBeFalse()
        ->and($text->hasHeader('X-Accel-Redirect'))->toBeFalse()
        ->and($text->getBody()->getContents())->toBe('new');
    expect($responder->download('new', 'new.txt', 'text/plain')->getHeaderLine('Content-Disposition'))
        ->toBe('attachment; filename="new.txt"');
    expect($responder->xAccelRedirect('/internal/new', 'new.txt', 'text/plain')->getHeaderLine('X-Accel-Redirect'))
        ->toBe('/internal/new');
})->with('psr17 factories');


it('replaces conflicting factory redirect metadata while retaining global security fields', function (\Closure $make): void {
    $factory = $make();
    $responses = new class($factory) implements ResponseFactoryInterface {
        public function __construct(private readonly ResponseFactoryInterface $factory) {}
        public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
        {
            return $this->factory->createResponse($code, $reasonPhrase)
                ->withHeader('Location', '/old')
                ->withHeader('Status', '302 Found')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        }
    };
    $responder = new Responder($responses, $factory);

    $response = $responder->forbidden();
    expect($response->getStatusCode())->toBe(403)
        ->and($response->hasHeader('Location'))->toBeFalse()
        ->and($response->hasHeader('Status'))->toBeFalse()
        ->and($response->getHeaderLine('X-Content-Type-Options'))->toBe('nosniff');

    $redirect = $responder->redirect('/new');
    expect($redirect->getHeader('Location'))->toBe(['/new'])
        ->and($redirect->hasHeader('Status'))->toBeFalse()
        ->and($redirect->getStatusCode())->toBe(302);
})->with('psr17 factories');

it('replaces conflicting nginx cache lifetime when selecting a new cache policy', function (\Closure $make): void {
    $factory = $make();
    $responder = new Responder($factory, $factory);
    $original = $responder->text('data')
        ->withHeader('X-Accel-Expires', '3600')
        ->withHeader('Expires', 'Fri, 27 Nov 2026 12:00:00 GMT')
        ->withHeader('Vary', 'Accept-Encoding');

    $private = $responder->withNoCache($original);
    expect($private->getHeaderLine('Cache-Control'))->toBe('no-store, no-cache, must-revalidate, max-age=0')
        ->and($private->hasHeader('X-Accel-Expires'))->toBeFalse()
        ->and($private->hasHeader('Expires'))->toBeFalse()
        ->and($private->getHeaderLine('Vary'))->toBe('Accept-Encoding');

    $public = $responder->withCache($original, 60, true);
    expect($public->getHeaderLine('Cache-Control'))->toBe('public, max-age=60')
        ->and($public->hasHeader('X-Accel-Expires'))->toBeFalse()
        ->and($public->hasHeader('Expires'))->toBeFalse()
        ->and($public->getHeaderLine('Vary'))->toBe('Accept-Encoding')
        ->and($original->getHeaderLine('X-Accel-Expires'))->toBe('3600');
})->with('psr17 factories');
