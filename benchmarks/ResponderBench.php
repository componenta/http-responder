<?php

declare(strict_types=1);

namespace Componenta\Http\Benchmarks;

use Componenta\Detector\MimeMap;
use Componenta\Detector\MimeType;
use Componenta\Detector\MimeTypeDetectorInterface;
use Componenta\Http\Responder;
use Nyholm\Psr7\Factory\Psr17Factory;
use PhpBench\Attributes as Bench;
use Psr\Http\Message\StreamInterface;

#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(5)]
#[Bench\Revs(1000)]
final class ResponderBench
{
    private Psr17Factory $factory;
    private Responder $responder;
    private MimeTypeDetectorInterface $detector;
    private MimeMap $map;
    private array $payload;
    private string $file;

    public function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->detector = new class(new MimeType('text/plain')) implements MimeTypeDetectorInterface {
            public function __construct(private readonly MimeType $type) {}
            public function detectMimeType(string|StreamInterface $content): ?MimeType { return $this->type; }
        };
        $this->map = new MimeMap();
        $this->responder = new Responder($this->factory, $this->factory, $this->detector, $this->map);
        $this->payload = array_fill(0, 100, ['id' => 1, 'name' => 'example']);
        $this->file = str_repeat('x', 65536);
    }

    public function benchConstruction(): void { new Responder($this->factory, $this->factory, $this->detector, $this->map); }
    public function benchText(): void { $this->responder->text('response body'); }
    public function benchJson(): void { $this->responder->json($this->payload); }
    public function benchDispatch(): void { $this->responder->respond(content: 'response body'); }
    public function benchBack(): void { $this->responder->back('https://shop.example/account', '/', 'https://shop.example'); }
    public function benchDownload64KiB(): void { $this->responder->download($this->file, 'отчёт.bin', 'application/octet-stream'); }
    public function benchNoContent(): void { $this->responder->empty(); }
    public function benchCacheHeaders(): void { $this->responder->withCache($this->responder->text('response body'), 60, true, true, 120); }
}
