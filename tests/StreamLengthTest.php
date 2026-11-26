<?php

declare(strict_types=1);

namespace Componenta\Http\Tests;

use Componenta\Http\Responder;
use Componenta\Http\Tests\Responder\Fixture\NonSeekableStream;
use Nyholm\Psr7\Factory\Psr17Factory;

require_once __DIR__ . '/Fixture/NonSeekableStream.php';

it('preserves the remaining non-seekable body without claiming the full stream length', function (string $method): void {
    $factory = new Psr17Factory();
    $stream = new NonSeekableStream($factory->createStream('prefix:payload'));
    $stream->read(7);
    $responder = new Responder($factory, $factory);

    $response = $method === 'respond'
        ? $responder->respond(content: $stream)
        : $responder->file($stream, 'data.txt', 'text/plain');

    expect($response->getBody())->toBe($stream)
        ->and($response->hasHeader('Content-Length'))->toBeFalse()
        ->and($response->hasHeader('Accept-Ranges'))->toBeFalse()
        ->and($response->getBody()->getContents())->toBe('payload');
})->with(['respond', 'file']);

it('prepares a seekable body from the start so its declared length matches the readable bytes', function (string $method): void {
    $factory = new Psr17Factory();
    $stream = $factory->createStream('prefix:payload');
    $stream->read(7);
    $responder = new Responder($factory, $factory);

    $response = $method === 'respond'
        ? $responder->respond(content: $stream)
        : $responder->file($stream, 'data.txt', 'text/plain');

    expect($response->getBody())->toBe($stream)
        ->and($response->getHeaderLine('Content-Length'))->toBe('14')
        ->and($response->getBody()->tell())->toBe(0)
        ->and($response->getBody()->getContents())->toBe('prefix:payload');
})->with(['respond', 'file']);



it('rejects unreadable content streams instead of building an unusable response', function (string $method): void {
    $factory = new Psr17Factory();
    $stream = $this->createStub(\Psr\Http\Message\StreamInterface::class);
    $stream->method('isReadable')->willReturn(false);
    $stream->method('isSeekable')->willReturn(false);
    $responder = new Responder($factory, $factory);

    expect(fn() => $method === 'respond'
        ? $responder->respond(content: $stream)
        : $responder->file($stream, 'data.txt', 'text/plain'))
        ->toThrow(\InvalidArgumentException::class);
})->with(['respond', 'file']);

it('validates an invalid status before wrapping a caller-owned resource', function (): void {
    $factory = new Psr17Factory();
    $streams = $this->createMock(\Psr\Http\Message\StreamFactoryInterface::class);
    $streams->expects($this->never())->method('createStreamFromResource');
    $resource = fopen('php://temp', 'w+b');
    if ($resource === false) {
        throw new \RuntimeException('Cannot create fixture.');
    }
    try {
        $responder = new Responder($factory, $streams);
        expect(fn() => $responder->respond(600, $resource))->toThrow(\InvalidArgumentException::class)
            ->and(is_resource($resource))->toBeTrue();
    } finally {
        if (is_resource($resource)) {
            fclose($resource);
        }
    }
});

it('validates file response fields before acquiring a caller resource', function (): void {
    $factory = new Psr17Factory();
    $streams = $this->createMock(\Psr\Http\Message\StreamFactoryInterface::class);
    $streams->expects($this->never())->method('createStreamFromResource');
    $resource = fopen('php://temp', 'w+b');
    if ($resource === false) {
        throw new \RuntimeException('Cannot create fixture.');
    }
    try {
        $responder = new Responder($factory, $streams);
        expect(fn() => $responder->file($resource, 'data.txt', "text/plain\r\nX-Invalid: bad"))->toThrow(\InvalidArgumentException::class)
            ->and(is_resource($resource))->toBeTrue();
    } finally {
        if (is_resource($resource)) {
            fclose($resource);
        }
    }
});
