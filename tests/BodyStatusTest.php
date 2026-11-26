<?php

declare(strict_types=1);

namespace Componenta\Http\Tests;

use Componenta\Http\Responder;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;

it('omits content for statuses that prohibit it', function (int $status): void {
    $factory = new Psr17Factory();
    $responder = new Responder($factory, $factory);

    $response = $responder->text('payload', $status);

    expect($response->getStatusCode())->toBe($status)
        ->and((string) $response->getBody())->toBe('')
        ->and($response->getHeaderLine('Content-Length'))->toBe('');
})->with([100, 103, 199, 204, 205, 304]);

it('omits file content and its length for statuses that prohibit content', function (int $status): void {
    $factory = new Psr17Factory();
    $responder = new Responder($factory, $factory);
    $body = $factory->createStream('payload');

    $response = $responder->file($body, 'demo.txt', status: $status);

    expect($response->getStatusCode())->toBe($status)
        ->and((string) $response->getBody())->toBe('')
        ->and($response->getHeaderLine('Content-Length'))->toBe('');
})->with([100, 103, 199, 204, 205, 304]);

it('preserves content and file headers for statuses that allow content', function (int $status): void {
    $factory = new Psr17Factory();
    $responder = new Responder($factory, $factory);

    $text = $responder->text('payload', $status);
    $file = $responder->file('payload', 'demo.txt', 'text/plain', $status);

    expect($text->getStatusCode())->toBe($status)
        ->and((string) $text->getBody())->toBe('payload')
        ->and($text->getHeaderLine('Content-Length'))->toBe('7')
        ->and($file->getStatusCode())->toBe($status)
        ->and((string) $file->getBody())->toBe('payload')
        ->and($file->getHeaderLine('Content-Length'))->toBe('7')
        ->and($file->getHeaderLine('Content-Type'))->toBe('text/plain')
        ->and($file->getHeaderLine('Content-Disposition'))->toBe('attachment; filename="demo.txt"')
        ->and($file->hasHeader('Accept-Ranges'))->toBeFalse();
})->with([200, 203, 206, 303, 305]);

it('applies content rules to download and inline shortcuts', function (bool $inline): void {
    $factory = new Psr17Factory();
    $responder = new Responder($factory, $factory);

    $response = $inline
        ? $responder->inline('payload', 'demo.txt', status: 205)
        : $responder->download('payload', 'demo.txt', status: 205);

    expect($response->getStatusCode())->toBe(205)
        ->and((string) $response->getBody())->toBe('')
        ->and($response->getHeaderLine('Content-Length'))->toBe('');
})->with(['download' => false, 'inline' => true]);

it('applies content rules when opening a file', function (string $method): void {
    $factory = new Psr17Factory();
    $responder = new Responder($factory, $factory);
    $path = tempnam(sys_get_temp_dir(), 'responder-status-');
    if ($path === false) {
        throw new \RuntimeException('Cannot create fixture file.');
    }

    try {
        file_put_contents($path, 'payload');
        $response = match ($method) {
            'file' => $responder->fileFromPath($path, status: 304),
            'download' => $responder->downloadFile($path, status: 304),
            'inline' => $responder->inlineFile($path, status: 304),
            default => throw new \LogicException('Unknown file shortcut: ' . $method),
        };

        expect($response->getStatusCode())->toBe(304)
            ->and((string) $response->getBody())->toBe('')
            ->and($response->getHeaderLine('Content-Length'))->toBe('');
    } finally {
        unlink($path);
    }
})->with(['file', 'download', 'inline']);

it('still validates file bodies when the status prohibits content', function (): void {
    $factory = new Psr17Factory();
    $responder = new Responder($factory, $factory);

    // @phpstan-ignore argument.type (Deliberately invalid body tests the public runtime validation.)
    expect(fn () => $responder->file([], 'demo.txt', status: 204))
        ->toThrow(\InvalidArgumentException::class, 'Body must be a StreamInterface, resource, or string content.');
});

it('still validates file arguments when the status prohibits content', function (\Closure $call): void {
    $factory = new Psr17Factory();
    $responder = new Responder($factory, $factory);

    expect(fn () => $call($responder))->toThrow(\InvalidArgumentException::class);
})->with([
    'filename' => [static fn (Responder $responder): ResponseInterface => $responder->file('payload', '', status: 204)],
    'disposition' => [static fn (Responder $responder): ResponseInterface => $responder->file('payload', 'demo.txt', status: 204, disposition: 'invalid')],
]);
