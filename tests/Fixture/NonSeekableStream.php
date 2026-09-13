<?php

declare(strict_types=1);

namespace Componenta\Http\Tests\Responder\Fixture;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

final readonly class NonSeekableStream implements StreamInterface
{
    public function __construct(private StreamInterface $stream) {}

    public function __toString(): string { return ''; }
    public function close(): void { $this->stream->close(); }
    public function detach() { return $this->stream->detach(); }
    public function getSize(): ?int { return $this->stream->getSize(); }
    public function tell(): int { return $this->stream->tell(); }
    public function eof(): bool { return $this->stream->eof(); }
    public function isSeekable(): bool { return false; }
    public function seek(int $offset, int $whence = SEEK_SET): void { throw new RuntimeException('not seekable'); }
    public function rewind(): void { throw new RuntimeException('not seekable'); }
    public function isWritable(): bool { return false; }
    public function write(string $string): int { throw new RuntimeException('not writable'); }
    public function isReadable(): bool { return true; }
    public function read(int $length): string { return $this->stream->read($length); }
    public function getContents(): string { return $this->stream->getContents(); }
    public function getMetadata(?string $key = null): mixed { return $this->stream->getMetadata($key); }
}
