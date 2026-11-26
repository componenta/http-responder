<?php

declare(strict_types=1);

namespace Componenta\Http\Tests;

use Componenta\Arrayable\Arrayable;
use Componenta\Config\ConfigKey;
use Componenta\Detector\MimeMapInterface;
use Componenta\Detector\MimeType;
use Componenta\Detector\MimeTypeDetectorInterface;
use Componenta\Http\Responder;
use Componenta\Http\ResponderConfigProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

function helperResponder(): Responder
{
    $factory = new Psr17Factory();
    return new Responder($factory, $factory);
}

it('implements all status shortcuts', function (string $method, int $status, ?string $message): void {
    $response = helperResponder()->{$method}();
    expect($response->getStatusCode())->toBe($status)
        ->and((string) $response->getBody())->toBe($message ?? '');
})->with([
    ['badRequest', 400, 'Bad Request'],
    ['unauthorized', 401, 'Unauthorized'],
    ['forbidden', 403, 'Forbidden'],
    ['notFound', 404, 'Not Found'],
    ['conflict', 409, 'Conflict'],
    ['gone', 410, 'Gone'],
    ['unprocessableEntity', 422, 'Unprocessable Entity'],
    ['tooManyRequests', 429, 'Too Many Requests'],
    ['serverError', 500, 'Internal Server Error'],
    ['notImplemented', 501, 'Not Implemented'],
    ['serviceUnavailable', 503, 'Service Unavailable'],
    ['notModified', 304, null],
    ['preconditionFailed', 412, null],
]);

it('constructs redirect shortcuts with exact statuses', function (string $method, int $status): void {
    $response = helperResponder()->{$method}('/next?q=1');
    expect($response->getStatusCode())->toBe($status)
        ->and($response->getHeaderLine('Location'))->toBe('/next?q=1')
        ->and((string) $response->getBody())->toBe('');
})->with([
    ['movedPermanently', 301], ['found', 302], ['seeOther', 303],
    ['temporaryRedirect', 307], ['permanentRedirect', 308],
]);

it('uses exact byte length and media type for text helpers', function (string $method, string $type): void {
    $body = 'Привет';
    $response = helperResponder()->{$method}($body, 201);
    expect((string) $response->getBody())->toBe($body)
        ->and($response->getHeaderLine('Content-Type'))->toBe($type)
        ->and($response->getHeaderLine('Content-Length'))->toBe((string) strlen($body))
        ->and($response->getStatusCode())->toBe(201);
})->with([
    ['text', 'text/plain; charset=utf-8'],
    ['html', 'text/html; charset=utf-8'],
    ['xml', 'application/xml; charset=utf-8'],
]);

it('validates status codes and preserves existing responses without touching them', function (): void {
    $responder = helperResponder();
    expect(fn() => $responder->text('x', 99))->toThrow(\InvalidArgumentException::class)
        ->and(fn() => $responder->empty(600))->toThrow(\InvalidArgumentException::class);
    $original = $responder->text('original', 201);
    expect($responder->respond(999, $original, 'ignored'))->toBe($original)
        ->and($responder->respond()->getStatusCode())->toBe(204)
        ->and($responder->respond(content: '')->getStatusCode())->toBe(204)
        ->and($responder->respond(200, '')->getStatusCode())->toBe(200)
        ->and(fn() => $responder->respond(content: 42))->toThrow(\InvalidArgumentException::class);
});

it('uses the documented response dispatch priority', function (): void {
    $responder = helperResponder();
    $json = new class implements Arrayable, \JsonSerializable, \Stringable {
        public function toArray(): array { return ['arrayable' => true]; }
        public function jsonSerialize(): mixed { return ['json' => true]; }
        public function __toString(): string { return 'string'; }
    };
    $arrayable = new class implements Arrayable, \Stringable {
        public function toArray(): array { return ['arrayable' => true]; }
        public function __toString(): string { return 'string'; }
    };
    $stringable = new class implements \Stringable {
        public function __toString(): string { return 'string'; }
    };
    expect((string) $responder->respond(content: $json)->getBody())->toBe('{"json":true}')
        ->and((string) $responder->respond(content: $arrayable)->getBody())->toBe('{"arrayable":true}')
        ->and((string) $responder->respond(content: $stringable, contentType: 'text/plain')->getBody())->toBe('string');
});

it('encodes JSON and JSONP without corrupting Unicode and rejects encoding failures', function (): void {
    $responder = helperResponder();
    $data = ['url' => '/path', 'message' => 'Привет'];
    $json = '{"url":"/path","message":"Привет"}';
    expect((string) $responder->json($data)->getBody())->toBe($json)
        ->and($responder->json($data)->getHeaderLine('Content-Type'))->toBe('application/json; charset=utf-8')
        ->and((string) $responder->jsonp($data, "app['callback']")->getBody())->toBe("app['callback'](" . $json . ');')
        ->and((string) $responder->jsonp($data, 'app.callback')->getBody())->toBe('app.callback(' . $json . ');')
        ->and(fn() => $responder->json(INF))->toThrow(\InvalidArgumentException::class);
});

it('sets protocol headers without mutating the input response', function (): void {
    $responder = helperResponder();
    $original = $responder->text('hello');
    $response = $responder->withCache($original, 60, true, true, 120);
    expect($response->getHeaderLine('Cache-Control'))->toBe('public, max-age=60, s-maxage=120, immutable')
        ->and($original->hasHeader('Cache-Control'))->toBeFalse()
        ->and($responder->withCache($original, 0)->getHeaderLine('Cache-Control'))->toBe('private, max-age=0')
        ->and($responder->withNoCache($response)->getHeaderLine('Cache-Control'))->toBe('no-store, no-cache, must-revalidate, max-age=0')
        ->and($responder->withNoCache($response)->getHeaderLine('Pragma'))->toBe('no-cache')
        ->and($responder->withEtag($response, 'tag')->getHeaderLine('ETag'))->toBe('"tag"')
        ->and($responder->withEtag($response, 'tag', true)->getHeaderLine('ETag'))->toBe('W/"tag"')
        ->and($responder->withLastModified($response, new \DateTimeImmutable('2026-10-01T11:30:00+02:00'))->getHeaderLine('Last-Modified'))->toBe('Thu, 01 Oct 2026 09:30:00 GMT')
        ->and($original->hasHeader('ETag'))->toBeFalse();
});

it('validates cache and retry headers including zero values', function (): void {
    $responder = helperResponder();
    $empty = $responder->empty();
    expect(fn() => $responder->withCache($empty, -1))->toThrow(\InvalidArgumentException::class)
        ->and(fn() => $responder->withCache($empty, 10, true, sharedMaxAge: -1))->toThrow(\InvalidArgumentException::class)
        ->and(fn() => $responder->withCache($empty, 10, false, sharedMaxAge: 0))->toThrow(\InvalidArgumentException::class)
        ->and(fn() => $responder->tooManyRequests(retryAfter: -1))->toThrow(\InvalidArgumentException::class)
        ->and(fn() => $responder->serviceUnavailable(retryAfter: -1))->toThrow(\InvalidArgumentException::class)
        ->and($responder->tooManyRequests(retryAfter: 0)->getHeaderLine('Retry-After'))->toBe('0')
        ->and($responder->serviceUnavailable(retryAfter: 60)->getHeaderLine('Retry-After'))->toBe('60')
        ->and($responder->unauthorized(wwwAuthenticate: 'Bearer realm="api"')->getHeaderLine('WWW-Authenticate'))->toBe('Bearer realm="api"')
        ->and(fn() => $responder->unauthorized(wwwAuthenticate: "Bearer\r\nX-Foo: bad"))->toThrow(\InvalidArgumentException::class);
});

it('keeps offload responses header-only and preserves the range error contract', function (): void {
    $responder = helperResponder();
    $nginx = $responder->xAccelRedirect('/internal/data', 'data.bin', 'application/octet-stream', '100k');
    $apache = $responder->xSendfile('/srv/data.bin', 'data.bin', 'application/octet-stream');
    expect($nginx->getHeaderLine('X-Accel-Redirect'))->toBe('/internal/data')
        ->and($nginx->getHeaderLine('X-Accel-Limit-Rate'))->toBe('100k')
        ->and((string) $nginx->getBody())->toBe('')
        ->and($nginx->hasHeader('Content-Length'))->toBeFalse()
        ->and($apache->getHeaderLine('X-Sendfile'))->toBe('/srv/data.bin')
        ->and((string) $apache->getBody())->toBe('')
        ->and($responder->rangeNotSatisfiable(0)->getHeaderLine('Content-Range'))->toBe('bytes */0')
        ->and(fn() => $responder->rangeNotSatisfiable(-1))->toThrow(\InvalidArgumentException::class);
});

it('does not use automatic detection when a caller supplies an explicit media type', function (): void {
    $factory = new Psr17Factory();
    $detector = $this->createMock(MimeTypeDetectorInterface::class);
    $detector->expects($this->never())->method('detectMimeType');
    $responder = new Responder($factory, $factory, $detector);
    expect($responder->respond(content: 'body', contentType: 'text/custom')->getHeaderLine('Content-Type'))->toBe('text/custom');
});

it('uses the injected map when detection returns no result', function (): void {
    $factory = new Psr17Factory();
    $detector = $this->createStub(MimeTypeDetectorInterface::class);
    $detector->method('detectMimeType')->willReturn(null);
    $map = $this->createMock(MimeMapInterface::class);
    $map->expects($this->once())->method('getMimeType')->with('custom')->willReturn('application/example');
    $responder = new Responder($factory, $factory, $detector, $map);
    $path = tempnam(sys_get_temp_dir(), 'responder-');
    if ($path === false) {
        throw new \RuntimeException('Cannot allocate fixture.');
    }
    try {
        file_put_contents($path, 'example');
        $response = $responder->downloadFile($path, 'name.custom');
        expect($response->getHeaderLine('Content-Type'))->toBe('application/example')
            ->and((string) $response->getBody())->toBe('example')
            ->and($response->getHeaderLine('Content-Length'))->toBe('7');
        $response->getBody()->close();
    } finally {
        unlink($path);
    }
});

it('closes an owned file stream when MIME resolution throws', function (): void {
    $factory = new Psr17Factory();
    $stream = $factory->createStream('payload');
    $streams = $this->createMock(StreamFactoryInterface::class);
    $streams->expects($this->once())->method('createStreamFromFile')->willReturn($stream);
    $detector = $this->createMock(MimeTypeDetectorInterface::class);
    $detector->expects($this->once())->method('detectMimeType')->willThrowException(new \RuntimeException('detection failed'));
    $responder = new Responder($factory, $streams, $detector);
    $path = tempnam(sys_get_temp_dir(), 'responder-');
    if ($path === false) {
        throw new \RuntimeException('Cannot allocate fixture.');
    }
    try {
        expect(fn() => $responder->downloadFile($path))->toThrow(\RuntimeException::class, 'detection failed');
        expect($stream->isReadable())->toBeFalse();
    } finally {
        unlink($path);
    }
});

it('constructs the service from the public configuration factory and dependency contracts', function (): void {
    $factory = new Psr17Factory();
    $detector = $this->createStub(MimeTypeDetectorInterface::class);
    $detector->method('detectMimeType')->willReturn(new MimeType('application/example'));
    $map = $this->createStub(MimeMapInterface::class);
    $container = $this->createStub(ContainerInterface::class);
    $container->method('get')->willReturnMap([
        [ResponseFactoryInterface::class, $factory],
        [StreamFactoryInterface::class, $factory],
        [MimeTypeDetectorInterface::class, $detector],
        [MimeMapInterface::class, $map],
    ]);
    $config = (new ResponderConfigProvider())();
    $create = $config[ConfigKey::DEPENDENCIES][ConfigKey::FACTORIES][Responder::class];
    $responder = $create($container);
    expect($responder)->toBeInstanceOf(Responder::class)
        ->and($responder->respond(content: 'body')->getHeaderLine('Content-Type'))->toBe('application/example');
});

it('does not inherit stale framing or content from a custom response factory', function (): void {
    $factory = new Psr17Factory();
    $responses = new class($factory) implements ResponseFactoryInterface {
        public function __construct(private Psr17Factory $factory) {}
        public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
        {
            return $this->factory->createResponse($code, $reasonPhrase)
                ->withBody($this->factory->createStream('stale'))
                ->withHeader('Content-Length', '5')
                ->withHeader('Transfer-Encoding', 'chunked')
                ->withHeader('Trailer', 'X-Foo');
        }
    };
    $responder = new Responder($responses, $factory);
    foreach ([$responder->empty(204), $responder->text('discarded', 205), $responder->redirect('/next')] as $response) {
        expect((string) $response->getBody())->toBe('')
            ->and($response->hasHeader('Content-Length'))->toBeFalse()
            ->and($response->hasHeader('Transfer-Encoding'))->toBeFalse()
            ->and($response->hasHeader('Trailer'))->toBeFalse();
    }
    $text = $responder->text('a');
    expect($text->getHeaderLine('Content-Length'))->toBe('1')
        ->and($text->hasHeader('Transfer-Encoding'))->toBeFalse()
        ->and((string) $text->getBody())->toBe('a');
});

it('uses the exact trusted origin and validates its configuration', function (): void {
    $responder = helperResponder();
    expect($responder->back('https://SHOP.example:443/path', '/safe', 'https://shop.example')->getHeaderLine('Location'))
        ->toBe('https://SHOP.example:443/path')
        ->and($responder->back('https://shop.example:444/path', '/safe', 'https://shop.example')->getHeaderLine('Location'))->toBe('/safe')
        ->and($responder->back('https://shop.example@other.example/path', '/safe', 'https://shop.example')->getHeaderLine('Location'))->toBe('/safe')
        ->and($responder->back('http://shop.example/path', '/safe', 'https://shop.example')->getHeaderLine('Location'))->toBe('/safe')
        ->and($responder->back('https://[2001:db8::1]/path', '/safe', 'https://[2001:0db8:0:0:0:0:0:1]')->getHeaderLine('Location'))->toBe('https://[2001:db8::1]/path')
        ->and(fn() => $responder->back(null, '/safe', 'https://shop.example/path'))->toThrow(\InvalidArgumentException::class)
        ->and(fn() => $responder->redirect("\r\n/next"))->toThrow(\InvalidArgumentException::class);
});
