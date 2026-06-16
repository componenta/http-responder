<?php

declare(strict_types=1);

namespace Componenta\Http;

use Componenta\Detector\DetectorInterface;
use Componenta\Detector\FinfoDetector;
use Componenta\Detector\MimeMap;
use Componenta\Detector\MimeMapInterface;
use Componenta\Arrayable\Arrayable;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Stringable;

/**
 * HTTP Response helper built on top of PSR-17 factories
 *
 * Provides convenient methods for creating common response types:
 * - Text, HTML, JSON, XML responses
 * - Redirects (301, 302, 303, 307, 308)
 * - File downloads and inline display
 * - Error responses
 * - Empty responses (204, 304)
 * - Streaming responses
 */
final readonly class Responder
{
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    private const array VALID_DISPOSITIONS = ['inline', 'attachment'];
    private const string DEFAULT_BINARY_TYPE = 'application/octet-stream';

    private MimeMapInterface $mimeMap;

    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private DetectorInterface $detector = new FinfoDetector(),
        ?MimeMapInterface $mimeMap = null,
    ) {
        $this->mimeMap = $mimeMap ?? new MimeMap();
    }

    /**
     * Creates a response based on content type detection
     *
     * Detection priority (first match wins):
     * 1. null -> 204 No Content
     * 2. ResponseInterface -> returned as-is ($code and $contentType are ignored)
     * 3. StreamInterface -> binary stream (application/octet-stream)
     * 4. string -> detected by magic bytes, empty string returns 204
     * 5. array | JsonSerializable -> JSON encoded (takes precedence over Arrayable and Stringable)
     * 6. Arrayable -> toArray() then JSON (takes precedence over Stringable)
     * 7. Stringable -> cast to string, detected by magic bytes
     * 8. resource -> wrapped in stream (application/octet-stream), closed resources throw
     *
     * Note: If status code is 204 or 304, content will be ignored per RFC 7230.
     *
     * @param int|null $code HTTP status code (100-599)
     * @param mixed $content Response content
     * @param string|null $contentType Content-Type header value (overrides detection)
     *
     * @throws InvalidArgumentException When content type is not supported, JSON encoding fails, or status code is invalid
     */
    public function respond(?int $code = null, mixed $content = null, ?string $contentType = null): ResponseInterface
    {
        if ($content === null) {
            return $this->empty($code ?? 204);
        }

        if ($content instanceof ResponseInterface) {
            return $content;
        }

        if ($content instanceof StreamInterface) {
            return $this->createResponse(
                $content,
                $code ?? 200,
                $contentType ?? self::DEFAULT_BINARY_TYPE,
            );
        }

        if (is_string($content)) {
            if ($content === '') {
                return $this->empty($code ?? 204);
            }

            return $this->createResponse(
                $content,
                $code ?? 200,
                $contentType ?? $this->detector->detectMimeType($content) ?? self::DEFAULT_BINARY_TYPE,
            );
        }

        if (is_array($content) || $content instanceof JsonSerializable) {
            return $this->createResponse(
                $this->encodeJson($content),
                $code ?? 200,
                $contentType ?? 'application/json; charset=utf-8',
            );
        }

        if ($content instanceof Arrayable) {
            return $this->createResponse(
                $this->encodeJson($content->toArray()),
                $code ?? 200,
                $contentType ?? 'application/json; charset=utf-8',
            );
        }

        if ($content instanceof Stringable) {
            $stringContent = (string) $content;

            if ($stringContent === '') {
                return $this->empty($code ?? 204);
            }

            return $this->createResponse(
                $stringContent,
                $code ?? 200,
                $contentType ?? $this->detector->detectMimeType($stringContent) ?? self::DEFAULT_BINARY_TYPE,
            );
        }

        if ($this->isValidResource($content)) {
            return $this->createResponse(
                $this->streamFactory->createStreamFromResource($content),
                $code ?? 200,
                $contentType ?? self::DEFAULT_BINARY_TYPE,
            );
        }

        throw new InvalidArgumentException(
            sprintf('Unsupported content type: %s', get_debug_type($content)),
        );
    }

    /**
     * Creates an empty response with given status code
     *
     * @param int $status HTTP status code (100-599)
     *
     * @throws InvalidArgumentException When status code is invalid
     */
    public function empty(int $status = 204): ResponseInterface
    {
        $this->validateStatusCode($status);

        return $this->responseFactory->createResponse($status);
    }

    /**
     * Creates a plain text response
     *
     * @throws InvalidArgumentException When status code is invalid
     */
    public function text(string $content, int $status = 200): ResponseInterface
    {
        return $this->createResponse($content, $status, 'text/plain; charset=utf-8');
    }

    /**
     * Creates an HTML response
     *
     * @throws InvalidArgumentException When status code is invalid
     */
    public function html(string $content, int $status = 200): ResponseInterface
    {
        return $this->createResponse($content, $status, 'text/html; charset=utf-8');
    }

    /**
     * Creates an XML response
     *
     * @throws InvalidArgumentException When status code is invalid
     */
    public function xml(string $content, int $status = 200): ResponseInterface
    {
        return $this->createResponse($content, $status, 'application/xml; charset=utf-8');
    }

    /**
     * Creates a JSON response
     *
     * @param mixed $data Data to encode as JSON
     * @param int $status HTTP status code
     * @param int $flags JSON encoding flags
     *
     * @throws InvalidArgumentException If encoding fails or status code is invalid
     */
    public function json(mixed $data, int $status = 200, int $flags = self::JSON_FLAGS): ResponseInterface
    {
        return $this->createResponse(
            $this->encodeJson($data, $flags),
            $status,
            'application/json; charset=utf-8',
        );
    }

    /**
     * Creates a JSONP response (JSON with callback)
     *
     * @param mixed $data Data to encode
     * @param string $callback JavaScript callback function name (supports dot notation like "my.namespace.func")
     *
     * @throws InvalidArgumentException If callback name is invalid, encoding fails, or status code is invalid
     */
    public function jsonp(mixed $data, string $callback, int $status = 200): ResponseInterface
    {
        $this->validateJsonpCallback($callback);

        return $this->createResponse(
            "$callback({$this->encodeJson($data)});",
            $status,
            'application/javascript; charset=utf-8',
        );
    }

    /**
     * Creates a redirect response (RFC 7231)
     *
     * @param string $url Target URL (absolute or relative)
     * @param int $status HTTP status code (typically 301, 302, 303, 307, 308; also valid with 201 for REST APIs)
     *
     * @throws InvalidArgumentException If URL is empty, contains invalid characters, or status code is invalid
     */
    public function redirect(string $url, int $status = 302): ResponseInterface
    {
        $this->validateStatusCode($status);

        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Location', $this->validateAndSanitizeUrl($url));
    }

    /**
     * Permanent redirect (301 Moved Permanently)
     *
     * Use for SEO-friendly permanent URL changes.
     * Browsers may cache this redirect.
     *
     * @throws InvalidArgumentException If URL is empty or contains invalid characters
     */
    public function movedPermanently(string $url): ResponseInterface
    {
        return $this->redirect($url, 301);
    }

    /**
     * Temporary redirect (302 Found)
     *
     * Default redirect type. Browser may change POST to GET.
     *
     * @throws InvalidArgumentException If URL is empty or contains invalid characters
     */
    public function found(string $url): ResponseInterface
    {
        return $this->redirect($url, 302);
    }

    /**
     * See Other redirect (303)
     *
     * Always changes method to GET. Use after POST form submission.
     *
     * @throws InvalidArgumentException If URL is empty or contains invalid characters
     */
    public function seeOther(string $url): ResponseInterface
    {
        return $this->redirect($url, 303);
    }

    /**
     * Temporary redirect preserving method (307)
     *
     * Like 302, but guarantees method preservation.
     *
     * @throws InvalidArgumentException If URL is empty or contains invalid characters
     */
    public function temporaryRedirect(string $url): ResponseInterface
    {
        return $this->redirect($url, 307);
    }

    /**
     * Permanent redirect preserving method (308)
     *
     * Like 301, but guarantees method preservation.
     *
     * @throws InvalidArgumentException If URL is empty or contains invalid characters
     */
    public function permanentRedirect(string $url): ResponseInterface
    {
        return $this->redirect($url, 308);
    }

    /**
     * Redirect back to referer or fallback URL
     *
     * @param string|null $referer The HTTP Referer header value
     * @param string $fallback Fallback URL if referer is empty or invalid
     *
     * @throws InvalidArgumentException If resulting URL is empty or contains invalid control characters
     */
    public function back(?string $referer, string $fallback = '/'): ResponseInterface
    {
        $referer = $referer !== null ? trim($referer) : '';

        return $this->redirect($referer !== '' ? $referer : $fallback, 302);
    }

    /**
     * Creates a file download response
     *
     * @param StreamInterface|resource|string $body File stream, resource, or content
     * @param string $filename Download filename (shown to user)
     * @param string|null $contentType MIME type (auto-detected if null)
     * @param int $status HTTP status code
     *
     * @throws InvalidArgumentException If filename is empty or status code is invalid
     */
    public function download(
        mixed $body,
        string $filename,
        ?string $contentType = null,
        int $status = 200,
    ): ResponseInterface {
        return $this->file($body, $filename, $contentType, $status, 'attachment');
    }

    /**
     * Creates a file download response from filesystem path
     *
     * @param string $filePath Absolute path to file
     * @param string|null $filename Download filename (defaults to basename)
     * @param string|null $contentType MIME type (auto-detected if null)
     * @param int $status HTTP status code
     *
     * @throws InvalidArgumentException If file does not exist or is not readable
     */
    public function downloadFile(
        string $filePath,
        ?string $filename = null,
        ?string $contentType = null,
        int $status = 200,
    ): ResponseInterface {
        return $this->fileFromPath($filePath, $filename, $contentType, $status, 'attachment');
    }

    /**
     * Creates an inline file response (display in browser)
     *
     * @param StreamInterface|resource|string $body File stream, resource, or content
     * @param string $filename Filename for Content-Disposition
     * @param string|null $contentType MIME type (auto-detected if null)
     * @param int $status HTTP status code
     *
     * @throws InvalidArgumentException If filename is empty or status code is invalid
     */
    public function inline(
        mixed $body,
        string $filename,
        ?string $contentType = null,
        int $status = 200,
    ): ResponseInterface {
        return $this->file($body, $filename, $contentType, $status, 'inline');
    }

    /**
     * Creates an inline file response from filesystem path
     *
     * @param string $filePath Absolute path to file
     * @param string|null $filename Filename for Content-Disposition (defaults to basename)
     * @param string|null $contentType MIME type (auto-detected if null)
     * @param int $status HTTP status code
     *
     * @throws InvalidArgumentException If file does not exist or is not readable
     */
    public function inlineFile(
        string $filePath,
        ?string $filename = null,
        ?string $contentType = null,
        int $status = 200,
    ): ResponseInterface {
        return $this->fileFromPath($filePath, $filename, $contentType, $status, 'inline');
    }

    /**
     * Creates a file response with specified disposition (RFC 6266)
     *
     * @param StreamInterface|resource|string $body File stream, resource, or content (NOT a file path)
     * @param string $filename Filename for Content-Disposition header
     * @param string|null $contentType MIME type (auto-detected from filename if null)
     * @param int $status HTTP status code
     * @param string $disposition Content disposition: 'attachment' or 'inline'
     *
     * @throws InvalidArgumentException If disposition is invalid, filename is empty, or status code is invalid
     */
    public function file(
        mixed $body,
        string $filename,
        ?string $contentType = null,
        int $status = 200,
        string $disposition = 'attachment',
    ): ResponseInterface {
        $this->validateStatusCode($status);
        $this->validateDisposition($disposition);
        $this->validateFilename($filename);

        $stream = $this->normalizeBody($body);
        $size = $stream->getSize();

        $response = $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', $contentType ?? $this->getMimeTypeByExtension($filename))
            ->withHeader('Content-Disposition', "$disposition; {$this->encodeFilename($filename)}")
            ->withBody($stream);

        if ($size !== null) {
            $response = $response->withHeader('Content-Length', (string) $size);

            if ($stream->isSeekable()) {
                $response = $response->withHeader('Accept-Ranges', 'bytes');
            }
        }

        return $response;
    }

    /**
     * Creates a file response from filesystem path
     *
     * Content type detection: reads file content via stream, falls back to extension
     *
     * @param string $filePath Absolute path to file
     * @param string|null $filename Filename for Content-Disposition (defaults to basename)
     * @param string|null $contentType MIME type (auto-detected if null)
     * @param int $status HTTP status code
     * @param string $disposition Content disposition: 'attachment' or 'inline'
     *
     * @throws InvalidArgumentException If file does not exist, is not readable, or parameters are invalid
     */
    public function fileFromPath(
        string $filePath,
        ?string $filename = null,
        ?string $contentType = null,
        int $status = 200,
        string $disposition = 'attachment',
    ): ResponseInterface {
        $stream = $this->createFileStream($filePath);

        if ($filename === null) {
            $filename = basename($filePath);

            if ($filename === '') {
                throw new InvalidArgumentException(
                    sprintf('Cannot extract filename from path: %s', $filePath),
                );
            }
        }

        if ($contentType === null) {
            $contentType = $stream->isSeekable()
                ? ($this->detector->detectMimeType($stream) ?? $this->getMimeTypeByExtension($filename))
                : $this->getMimeTypeByExtension($filename);
        }

        return $this->file($stream, $filename, $contentType, $status, $disposition);
    }

    /**
     * Creates an X-Accel-Redirect response for nginx
     *
     * PHP returns headers only, nginx serves the actual file.
     * Much more efficient for large files.
     *
     * @param string $internalPath Internal nginx path (e.g., /internal-files/...)
     * @param string $filename Download filename shown to user
     * @param string|null $contentType MIME type
     * @param string|null $rateLimit Rate limit (e.g., '100k', '1m', '0' for unlimited)
     * @param string $disposition Content disposition: 'attachment' or 'inline'
     *
     * @throws InvalidArgumentException If parameters are invalid
     */
    public function xAccelRedirect(
        string $internalPath,
        string $filename,
        ?string $contentType = null,
        ?string $rateLimit = null,
        string $disposition = 'attachment',
    ): ResponseInterface {
        $this->validateDisposition($disposition);
        $this->validateFilename($filename);
        $this->validateHeaderValue($internalPath, 'X-Accel-Redirect path');

        $response = $this->responseFactory
            ->createResponse(200)
            ->withHeader('X-Accel-Redirect', $internalPath)
            ->withHeader('Content-Type', $contentType ?? $this->getMimeTypeByExtension($filename))
            ->withHeader('Content-Disposition', "$disposition; {$this->encodeFilename($filename)}");

        if ($rateLimit !== null) {
            $this->validateHeaderValue($rateLimit, 'X-Accel-Limit-Rate');
            $response = $response->withHeader('X-Accel-Limit-Rate', $rateLimit);
        }

        return $response;
    }

    /**
     * Creates an X-Sendfile response for Apache
     *
     * Requires mod_xsendfile module.
     *
     * @param string $filePath Absolute filesystem path
     * @param string $filename Download filename shown to user
     * @param string|null $contentType MIME type
     * @param string $disposition Content disposition: 'attachment' or 'inline'
     *
     * @throws InvalidArgumentException If parameters are invalid
     */
    public function xSendfile(
        string $filePath,
        string $filename,
        ?string $contentType = null,
        string $disposition = 'attachment',
    ): ResponseInterface {
        $this->validateDisposition($disposition);
        $this->validateFilename($filename);
        $this->validateHeaderValue($filePath, 'X-Sendfile path');

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('X-Sendfile', $filePath)
            ->withHeader('Content-Type', $contentType ?? $this->getMimeTypeByExtension($filename))
            ->withHeader('Content-Disposition', "$disposition; {$this->encodeFilename($filename)}");
    }

    /**
     * 400 Bad Request
     */
    public function badRequest(string $message = 'Bad Request'): ResponseInterface
    {
        return $this->text($message, 400);
    }

    /**
     * 401 Unauthorized (RFC 7235)
     *
     * @param string|null $wwwAuthenticate WWW-Authenticate header value
     *
     * @throws InvalidArgumentException If WWW-Authenticate header contains invalid characters
     */
    public function unauthorized(
        string $message = 'Unauthorized',
        ?string $wwwAuthenticate = null,
    ): ResponseInterface {
        $response = $this->text($message, 401);

        if ($wwwAuthenticate !== null) {
            $this->validateHeaderValue($wwwAuthenticate, 'WWW-Authenticate');
            $response = $response->withHeader('WWW-Authenticate', $wwwAuthenticate);
        }

        return $response;
    }

    /**
     * 403 Forbidden
     */
    public function forbidden(string $message = 'Forbidden'): ResponseInterface
    {
        return $this->text($message, 403);
    }

    /**
     * 404 Not Found
     */
    public function notFound(string $message = 'Not Found'): ResponseInterface
    {
        return $this->text($message, 404);
    }

    /**
     * 405 Method Not Allowed (RFC 7231)
     *
     * @param list<non-empty-string> $allowedMethods Allowed HTTP methods (must not be empty)
     *
     * @throws InvalidArgumentException If allowedMethods is empty
     */
    public function methodNotAllowed(array $allowedMethods): ResponseInterface
    {
        $methods = array_unique(array_filter(
            array_map(static fn($m) => is_string($m) ? strtoupper(trim($m)) : '', $allowedMethods),
            static fn($m) => $m !== '',
        ));

        if ($methods === []) {
            throw new InvalidArgumentException('Allowed methods array cannot be empty');
        }

        return $this->responseFactory
            ->createResponse(405)
            ->withHeader('Allow', implode(', ', $methods));
    }

    /**
     * 409 Conflict
     */
    public function conflict(string $message = 'Conflict'): ResponseInterface
    {
        return $this->text($message, 409);
    }

    /**
     * 410 Gone
     */
    public function gone(string $message = 'Gone'): ResponseInterface
    {
        return $this->text($message, 410);
    }

    /**
     * 422 Unprocessable Entity (RFC 4918)
     */
    public function unprocessableEntity(string $message = 'Unprocessable Entity'): ResponseInterface
    {
        return $this->text($message, 422);
    }

    /**
     * 429 Too Many Requests (RFC 6585)
     *
     * @param int|null $retryAfter Seconds until client can retry (must be non-negative)
     *
     * @throws InvalidArgumentException If retryAfter is negative
     */
    public function tooManyRequests(
        string $message = 'Too Many Requests',
        ?int $retryAfter = null,
    ): ResponseInterface {
        $response = $this->text($message, 429);

        if ($retryAfter !== null) {
            $this->validateNonNegative($retryAfter, 'Retry-After');
            $response = $response->withHeader('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    /**
     * 500 Internal Server Error
     */
    public function serverError(string $message = 'Internal Server Error'): ResponseInterface
    {
        return $this->text($message, 500);
    }

    /**
     * 501 Not Implemented
     */
    public function notImplemented(string $message = 'Not Implemented'): ResponseInterface
    {
        return $this->text($message, 501);
    }

    /**
     * 503 Service Unavailable
     *
     * @param int|null $retryAfter Seconds until service is available (must be non-negative)
     *
     * @throws InvalidArgumentException If retryAfter is negative
     */
    public function serviceUnavailable(
        string $message = 'Service Unavailable',
        ?int $retryAfter = null,
    ): ResponseInterface {
        $response = $this->text($message, 503);

        if ($retryAfter !== null) {
            $this->validateNonNegative($retryAfter, 'Retry-After');
            $response = $response->withHeader('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    /**
     * 304 Not Modified (RFC 7232)
     *
     * Used for conditional requests (If-None-Match, If-Modified-Since)
     */
    public function notModified(): ResponseInterface
    {
        return $this->responseFactory->createResponse(304);
    }

    /**
     * 412 Precondition Failed (RFC 7232)
     *
     * Used when If-Match or If-Unmodified-Since fails
     */
    public function preconditionFailed(): ResponseInterface
    {
        return $this->responseFactory->createResponse(412);
    }

    /**
     * 416 Range Not Satisfiable (RFC 7233)
     *
     * @param int $totalSize Total resource size in bytes (must be non-negative)
     *
     * @throws InvalidArgumentException If totalSize is negative
     */
    public function rangeNotSatisfiable(int $totalSize): ResponseInterface
    {
        $this->validateNonNegative($totalSize, 'Total size');

        return $this->responseFactory
            ->createResponse(416)
            ->withHeader('Content-Range', "bytes */$totalSize");
    }

    /**
     * Adds caching headers to a response (RFC 7234)
     *
     * @param ResponseInterface $response Response to modify
     * @param int $maxAge Cache duration in seconds (must be non-negative)
     * @param bool $public Allow caching by shared caches (CDN, proxies)
     * @param bool $immutable Resource will never change (aggressive caching)
     * @param int|null $sharedMaxAge Cache duration for shared caches (CDN), requires $public = true
     *
     * @throws InvalidArgumentException If maxAge/sharedMaxAge is negative, or sharedMaxAge without public
     */
    public function withCache(
        ResponseInterface $response,
        int $maxAge,
        bool $public = false,
        bool $immutable = false,
        ?int $sharedMaxAge = null,
    ): ResponseInterface {
        $this->validateNonNegative($maxAge, 'max-age');

        if ($sharedMaxAge !== null) {
            $this->validateNonNegative($sharedMaxAge, 's-maxage');

            if (!$public) {
                throw new InvalidArgumentException('s-maxage requires public caching (set $public = true)');
            }
        }

        $directives = [$public ? 'public' : 'private', "max-age=$maxAge"];

        if ($sharedMaxAge !== null) {
            $directives[] = "s-maxage=$sharedMaxAge";
        }

        if ($immutable) {
            $directives[] = 'immutable';
        }

        return $response->withHeader('Cache-Control', implode(', ', $directives));
    }

    /**
     * Adds no-cache headers to a response
     */
    public function withNoCache(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache');
    }

    /**
     * Adds ETag header to a response (RFC 7232)
     *
     * @param string $etag Entity tag value (without quotes, must not contain quotes)
     * @param bool $weak Use weak ETag (allows semantically equivalent responses)
     *
     * @throws InvalidArgumentException If ETag is empty or contains invalid characters
     */
    public function withEtag(
        ResponseInterface $response,
        string $etag,
        bool $weak = false,
    ): ResponseInterface {
        $this->validateEtag($etag);

        return $response->withHeader('ETag', $weak ? "W/\"$etag\"" : "\"$etag\"");
    }

    /**
     * Adds Last-Modified header to a response (RFC 7232)
     *
     * Note: DateTime is automatically converted to UTC/GMT as required by RFC 7231
     */
    public function withLastModified(
        ResponseInterface $response,
        DateTimeInterface $dateTime,
    ): ResponseInterface {
        return $response->withHeader(
            'Last-Modified',
            DateTimeImmutable::createFromInterface($dateTime)
                ->setTimezone(new \DateTimeZone('GMT'))
                ->format(DateTimeInterface::RFC7231),
        );
    }

    private function createResponse(string|StreamInterface $content, int $status, string $contentType): ResponseInterface
    {
        $this->validateStatusCode($status);

        if ($status === 204 || $status === 304) {
            return $this->responseFactory->createResponse($status);
        }

        $body = $content instanceof StreamInterface
            ? $content
            : $this->streamFactory->createStream($content);

        $response = $this->responseFactory
            ->createResponse($status)
            ->withBody($body);

        if ($contentType !== '') {
            $response = $response->withHeader('Content-Type', $contentType);
        }

        $size = $body->getSize();

        if ($size !== null) {
            $response = $response->withHeader('Content-Length', (string) $size);
        }

        return $response;
    }

    /**
     * Creates a stream from file path, handling errors atomically
     *
     * @throws InvalidArgumentException If file cannot be opened
     */
    private function createFileStream(string $filePath): StreamInterface
    {
        try {
            return $this->streamFactory->createStreamFromFile($filePath, 'rb');
        } catch (\Throwable $e) {
            if (!file_exists($filePath)) {
                throw new InvalidArgumentException(
                    sprintf('File does not exist: %s', $filePath),
                    previous: $e,
                );
            }

            if (!is_readable($filePath)) {
                throw new InvalidArgumentException(
                    sprintf('File is not readable: %s', $filePath),
                    previous: $e,
                );
            }

            throw new InvalidArgumentException(
                sprintf('Cannot open file: %s', $filePath),
                previous: $e,
            );
        }
    }

    private function encodeJson(mixed $data, int $flags = self::JSON_FLAGS): string
    {
        try {
            return json_encode($data, $flags | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                sprintf('Failed to encode data to JSON: %s', $e->getMessage()),
                previous: $e,
            );
        }
    }

    private function normalizeBody(mixed $body): StreamInterface
    {
        if ($body instanceof StreamInterface) {
            return $body;
        }

        if ($this->isValidResource($body)) {
            return $this->streamFactory->createStreamFromResource($body);
        }

        if (is_string($body)) {
            return $this->streamFactory->createStream($body);
        }

        throw new InvalidArgumentException(
            sprintf(
                'Body must be a StreamInterface, resource, or string content. Got: %s',
                get_debug_type($body),
            ),
        );
    }

    private function isValidResource(mixed $value): bool
    {
        return is_resource($value) && get_resource_type($value) !== 'Unknown';
    }

    private function validateStatusCode(int $status): void
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException(
                sprintf('Invalid HTTP status code: %d. Must be between 100 and 599.', $status),
            );
        }
    }

    private function validateNonNegative(int $value, string $name): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException("$name cannot be negative");
        }
    }

    private function validateDisposition(string $disposition): void
    {
        if (!in_array($disposition, self::VALID_DISPOSITIONS, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid Content-Disposition type: "%s". Must be one of: %s',
                    $disposition,
                    implode(', ', self::VALID_DISPOSITIONS),
                ),
            );
        }
    }

    private function validateFilename(string $filename): void
    {
        if (trim($filename) === '') {
            throw new InvalidArgumentException('Filename cannot be empty');
        }

        if (str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new InvalidArgumentException('Filename cannot contain path separators');
        }

        if (str_contains($filename, "\0")) {
            throw new InvalidArgumentException('Filename cannot contain null bytes');
        }
    }

    private function validateJsonpCallback(string $callback): void
    {
        if ($callback === '') {
            throw new InvalidArgumentException('JSONP callback name cannot be empty');
        }

        if (!preg_match('/^[$a-zA-Z_][$a-zA-Z0-9_]*(?:\.[$a-zA-Z_][$a-zA-Z0-9_]*|\[[\'"]\w+[\'"]])*$/', $callback)) {
            throw new InvalidArgumentException(
                sprintf('Invalid JSONP callback name: "%s"', $callback),
            );
        }
    }

    private function validateAndSanitizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new InvalidArgumentException('Redirect URL cannot be empty');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            throw new InvalidArgumentException('Redirect URL contains invalid control characters');
        }

        return $url;
    }

    private function validateEtag(string $etag): void
    {
        if ($etag === '') {
            throw new InvalidArgumentException('ETag cannot be empty');
        }

        if (str_contains($etag, '"')) {
            throw new InvalidArgumentException('ETag value must not contain double quotes');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $etag)) {
            throw new InvalidArgumentException('ETag contains invalid control characters');
        }
    }

    private function validateHeaderValue(string $value, string $name): void
    {
        if ($value === '') {
            throw new InvalidArgumentException("$name cannot be empty");
        }

        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException(
                sprintf('%s contains invalid control characters', $name),
            );
        }
    }

    /**
     * Encodes filename for Content-Disposition header (RFC 6266, RFC 5987)
     */
    private function encodeFilename(string $filename): string
    {
        $sanitized = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '_', $filename) ?? $filename;

        if (preg_match('/^[\x20-\x7E]+$/', $sanitized) && $sanitized !== '') {
            return "filename=\"$sanitized\"";
        }

        return "filename=\"$sanitized\"; filename*=UTF-8''" . rawurlencode($filename);
    }

    private function getMimeTypeByExtension(string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        if ($extension === '') {
            return self::DEFAULT_BINARY_TYPE;
        }

        return $this->mimeMap->getMimeType($extension) ?? self::DEFAULT_BINARY_TYPE;
    }
}
