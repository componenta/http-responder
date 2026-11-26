<?php

declare(strict_types=1);

namespace Componenta\Http\Tests;

use Componenta\Detector\MimeType;
use Componenta\Detector\MimeTypeDetectorInterface;
use Componenta\Http\Responder;
use Nyholm\Psr7\Factory\Psr17Factory;

function auditedResponder(): Responder
{
    $factory = new Psr17Factory();
    return new Responder($factory, $factory);
}

it('accepts the narrow MIME 2 contract and extracts the value object', function (): void {
    $factory = new Psr17Factory();
    $detector = new class implements MimeTypeDetectorInterface {
        public function detectMimeType(string|\Psr\Http\Message\StreamInterface $content): ?MimeType
        {
            return new MimeType('application/example');
        }
    };
    $responder = new Responder($factory, $factory, $detector);
    expect($responder->respond(content: 'raw')->getHeaderLine('Content-Type'))->toBe('application/example')
        ->and($responder->respond(content: 'raw', contentType: 'text/plain')->getHeaderLine('Content-Type'))->toBe('text/plain');
});

it('preserves method case and deduplicates exact names', function (): void {
    expect(auditedResponder()->methodNotAllowed(['GET', 'x-Custom', 'X-Custom', 'GET'])->getHeaderLine('Allow'))
        ->toBe('GET, x-Custom, X-Custom');
});

it('rejects invalid HTTP method tokens rather than normalizing them', function (mixed $method): void {
    expect(fn() => auditedResponder()->methodNotAllowed(['GET', $method]))->toThrow(\InvalidArgumentException::class);
})->with(['', 'GET POST', 'GET, POST', "GET\n", 42, null]);

it('does not infer range support from a seekable stream', function (): void {
    $response = auditedResponder()->download('payload', 'data.txt');
    expect($response->hasHeader('Accept-Ranges'))->toBeFalse()
        ->and($response->getHeaderLine('Content-Length'))->toBe('7');
});

it('uses an ASCII filename fallback and a UTF-8 extended value', function (): void {
    $response = auditedResponder()->download('payload', 'отчёт.pdf');
    $header = $response->getHeaderLine('Content-Disposition');
    expect($header)->toBe("attachment; filename=\"_____.pdf\"; filename*=UTF-8''" . rawurlencode('отчёт.pdf'))
        ->and(preg_match('/^[\x20-\x7e]+$/D', $header))->toBe(1);
});

it('rejects invalid filename values', function (string $filename): void {
    expect(fn() => auditedResponder()->download('payload', $filename))->toThrow(\InvalidArgumentException::class);
})->with(["file\r\nname", "file\x7f", "bad\xff", '.', '..']);

it('rejects non-file paths before opening a stream', function (string $path): void {
    expect(fn() => auditedResponder()->downloadFile($path))->toThrow(\InvalidArgumentException::class);
})->with(['php://memory', 'data://text/plain,abc', sys_get_temp_dir()]);

it('does not take ownership of a resource when content is prohibited', function (): void {
    $resource = fopen('php://temp', 'w+b');
    if ($resource === false) {
        throw new \RuntimeException('Cannot create fixture');
    }
    try {
        fwrite($resource, 'payload');
        $responder = auditedResponder();
        $response = $responder->file($resource, 'data.txt', status: 204);
        expect(is_resource($resource))->toBeTrue()
            ->and((string) $response->getBody())->toBe('');
    } finally {
        if (is_resource($resource)) {
            fclose($resource);
        }
    }
});

it('rejects invalid entity tags', function (string $etag): void {
    $responder = auditedResponder();
    expect(fn() => $responder->withEtag($responder->empty(), $etag))->toThrow(\InvalidArgumentException::class);
})->with(['a b', "a\tb", "a\nb", 'a"b']);

it('rejects JSONP callbacks with ambiguous quote delimiters or a trailing newline', function (string $callback): void {
    expect(fn() => auditedResponder()->jsonp([], $callback))->toThrow(\InvalidArgumentException::class);
})->with(["callback\n", "callback['name\"]", 'callback["name\']']);

it('falls back rather than redirecting to an untrusted referrer', function (string $referer): void {
    expect(auditedResponder()->back($referer, '/safe')->getHeaderLine('Location'))->toBe('/safe');
})->with(['https://other.example/path', '//other.example/path', '/\\other.example/path', "bad\r\nurl", 'javascript:alert(1)']);

it('preserves a local referrer and explicitly authorized absolute referrer', function (): void {
    $responder = auditedResponder();
    expect($responder->back('/account?tab=1', '/safe')->getHeaderLine('Location'))->toBe('/account?tab=1')
        ->and($responder->back('https://shop.example/account', '/safe', trustedOrigin: 'https://shop.example')->getHeaderLine('Location'))
        ->toBe('https://shop.example/account')
        ->and($responder->back('https://shop.example.other/account', '/safe', trustedOrigin: 'https://shop.example')->getHeaderLine('Location'))
        ->toBe('/safe');
});
