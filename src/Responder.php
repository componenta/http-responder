<?php

declare(strict_types=1);

namespace Componenta\Http;

use Componenta\Detector\MimeTypeDetectorInterface;
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
    private MimeTypeDetectorInterface $detector;

    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        ?MimeTypeDetectorInterface $detector = null,
        ?MimeMapInterface $mimeMap = null,
    ) {
        $this->mimeMap = $mimeMap ?? new MimeMap();
        $this->detector = $detector ?? new FinfoDetector($this->mimeMap);
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
     * Note: Responses with status 1xx, 204, 205 or 304 have no content.
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
                $contentType ?? $this->detector->detectMimeType($content)->value ?? self::DEFAULT_BINARY_TYPE,
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
                $contentType ?? $this->detector->detectMimeType($stringContent)->value ?? self::DEFAULT_BINARY_TYPE,
            );
        }

        if ($this->isValidResource($content)) {
            if ($code !== null && !self::allowsContent($code)) {
                return $this->empty($code);
            }
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

        $response = $this->responseFactory->createResponse($status);
        if ($response->getBody()->getSize() !== 0) {
            $response = $response->withBody($this->streamFactory->createStream());
        }
        return $response->withoutHeader('Content-Length')->withoutHeader('Transfer-Encoding')->withoutHeader('Trailer');
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

        return $this->empty($status)
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
     * Redirect back to a root-relative path or an absolute referrer on an
     * explicitly configured trusted HTTP(S) origin. Otherwise use fallback.
     * The application must provide a trusted fallback, never request input.
     */
    public function back(?string $referer, string $fallback = '/', ?string $trustedOrigin = null): ResponseInterface
    {
        $origin = null;
        if ($trustedOrigin !== null) {
            $origin = self::canonicalOrigin($trustedOrigin);
            if ($origin === null) {
                throw new InvalidArgumentException('Trusted redirect origin must be an explicit HTTP(S) origin.');
            }
        }
        if ($referer !== null && $referer !== ''
            && preg_match('/[\x00-\x20\x7f]/', $referer) !== 1
            && !str_contains($referer, '\\')) {
            if (str_starts_with($referer, '/') && !str_starts_with($referer, '//')) {
                return $this->redirect($referer, 302);
            }
            if ($origin !== null && preg_match('~\A(https?://[^/?#]+)(?:[/?#].*)?\z~iD', $referer, $parts) === 1
                && self::canonicalOrigin($parts[1]) === $origin) {
                return $this->redirect($referer, 302);
            }
        }

        return $this->redirect($fallback, 302);
    }

    private static function canonicalOrigin(string $origin): ?string
    {
        if (preg_match('~\A(https?)://(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)(?::([0-9]{1,5}))?\z~iD', $origin, $parts) !== 1) {
            return null;
        }
        $scheme = strtolower($parts[1]);
        $host = strtolower($parts[2]);
        if (str_starts_with($host, '[')) {
            $address = substr($host, 1, -1);
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }
            $binary = inet_pton($address);
            $canonical = $binary === false ? false : inet_ntop($binary);
            if ($canonical === false) {
                return null;
            }
            $host = '[' . $canonical . ']';
        } elseif (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        $port = isset($parts[3]) ? (int) $parts[3] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            return null;
        }
        return $scheme . '://' . $host . ':' . $port;
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

        if (!self::allowsContent($status)) {
            $this->validateFileBody($body);
            return $this->empty($status);
        }
        $stream = $this->normalizeBody($body);

        $size = $stream->getSize();

        $response = $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', $contentType ?? $this->getMimeTypeByExtension($filename))
            ->withHeader('Content-Disposition', "$disposition; {$this->encodeFilename($filename)}")
            ->withoutHeader('Content-Length')
            ->withoutHeader('Transfer-Encoding')
            ->withoutHeader('Trailer')
            ->withBody($stream);

        if ($size !== null && $stream->isSeekable()) {
            $response = $response->withHeader('Content-Length', (string) $size);
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
        $this->validateStatusCode($status);
        $this->validateDisposition($disposition);
        $this->validateFilePath($filePath);
        $filename ??= basename($filePath);
        $this->validateFilename($filename);
        if (!self::allowsContent($status)) {
            return $this->empty($status);
        }

        $stream = $this->createFileStream($filePath);
        try {
            if ($contentType === null) {
                $contentType = $stream->isSeekable()
                    ? ($this->detector->detectMimeType($stream)->value ?? $this->getMimeTypeByExtension($filename))
                    : $this->getMimeTypeByExtension($filename);
            }
            return $this->file($stream, $filename, $contentType, $status, $disposition);
        } catch (\Throwable $exception) {
            try {
                $stream->close();
            } catch (\Throwable) {
                // Preserve the original failure.
            }
            throw $exception;
        }
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

        $response = $this->empty(200)
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

        return $this->empty(200)
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
     * @param array<array-key, mixed> $allowedMethods Non-empty array of case-sensitive HTTP method tokens
     *
     * @throws InvalidArgumentException If allowedMethods is empty
     */
    public function methodNotAllowed(array $allowedMethods): ResponseInterface
    {
        if ($allowedMethods === []) {
            throw new InvalidArgumentException('Allowed methods array cannot be empty');
        }
        $methods = [];
        foreach ($allowedMethods as $method) {
            if (!is_string($method) || preg_match("@^[!#$%&'*+.^_\x60|~0-9A-Za-z-]+$@D", $method) !== 1) {
                throw new InvalidArgumentException('Allowed methods must be valid case-sensitive HTTP method tokens.');
            }
            $methods[] = $method;
        }

        return $this->empty(405)
            ->withHeader('Allow', implode(', ', array_unique($methods)));
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
        return $this->empty(304);
    }

    /**
     * 412 Precondition Failed (RFC 7232)
     *
     * Used when If-Match or If-Unmodified-Since fails
     */
    public function preconditionFailed(): ResponseInterface
    {
        return $this->empty(412);
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

        return $this->empty(416)
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

    private static function allowsContent(int $status): bool
    {
        return $status >= 200 && $status !== 204 && $status !== 205 && $status !== 304;
    }

    private function createResponse(string|StreamInterface $content, int $status, string $contentType): ResponseInterface
    {
        $this->validateStatusCode($status);

        if (!self::allowsContent($status)) {
            return $this->empty($status);
        }

        $body = $content instanceof StreamInterface
            ? $content
            : $this->streamFactory->createStream($content);

        $response = $this->responseFactory
            ->createResponse($status)
            ->withoutHeader('Content-Length')
            ->withoutHeader('Transfer-Encoding')
            ->withoutHeader('Trailer')
            ->withoutHeader('Content-Type')
            ->withBody($body);

        if ($contentType !== '') {
            $response = $response->withHeader('Content-Type', $contentType);
        }

        $size = $body->getSize();

        if ($size !== null && $body->isSeekable()) {
            $response = $response->withHeader('Content-Length', (string) $size);
        }

        return $response;
    }

    /** Only ordinary local filesystem paths may be passed to the file API. */
    private function validateFilePath(string $filePath): void
    {
        if ($filePath === '' || str_contains($filePath, "\0")
            || preg_match('~\A[A-Za-z][A-Za-z0-9+.-]*://~', $filePath) === 1
            || !is_file($filePath) || !is_readable($filePath)) {
            throw new InvalidArgumentException('File path must identify a readable regular file.');
        }
    }

    private function createFileStream(string $filePath): StreamInterface
    {
        try {
            return $this->streamFactory->createStreamFromFile($filePath, 'rb');
        } catch (\RuntimeException | InvalidArgumentException $exception) {
            throw new InvalidArgumentException('Cannot open file for reading.', previous: $exception);
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

    private function validateFileBody(mixed $body): void
    {
        if (!$body instanceof StreamInterface && !is_string($body) && !$this->isValidResource($body)) {
            throw new InvalidArgumentException(
                sprintf('Body must be a StreamInterface, resource, or string content. Got: %s', get_debug_type($body)),
            );
        }
    }

    /** @phpstan-assert-if-true resource $value */
    private function isValidResource(mixed $value): bool
    {
        return is_resource($value) && get_resource_type($value) === 'stream';
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
        if (trim($filename) === '' || in_array(trim($filename), ['.', '..'], true)
            || preg_match('//u', $filename) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $filename) === 1
            || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new InvalidArgumentException('Filename must be a non-empty UTF-8 basename without control characters.');
        }
    }

    private function validateJsonpCallback(string $callback): void
    {
        if (preg_match('/\A[$a-zA-Z_][$a-zA-Z0-9_]*(?:\.[$a-zA-Z_][$a-zA-Z0-9_]*|\[(?:\'[a-zA-Z0-9_]+\'|"[a-zA-Z0-9_]+")\])*\z/D', $callback) !== 1) {
            throw new InvalidArgumentException('Invalid JSONP callback name.');
        }
    }

    private function validateAndSanitizeUrl(string $url): string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $url) === 1) {
            throw new InvalidArgumentException('Redirect URL contains invalid control characters.');
        }
        $url = trim($url, ' ');
        if ($url === '' || str_contains($url, ' ') || str_contains($url, '\\')) {
            throw new InvalidArgumentException('Redirect URL must be a non-empty URI reference without spaces or backslashes.');
        }
        return $url;
    }

    private function validateEtag(string $etag): void
    {
        // RFC 9110 etagc: %x21 / %x23-7E / obs-text.
        if ($etag === '' || preg_match('/[\x00-\x20\x22\x7f]/', $etag) === 1) {
            throw new InvalidArgumentException('ETag must be non-empty and contain only valid entity-tag characters.');
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
        // RFC 6266: legacy filename must be ASCII and not contain sequences
        // some clients mistake for escapes. Preserve the real UTF-8 name
        // in filename* when any character needed a fallback.
        $fallback = preg_replace('/[^\x20-\x7e]|["\\\\]|%(?=[0-9A-Fa-f]{2})/u', '_', $filename);
        if ($fallback === null) {
            throw new InvalidArgumentException('Filename cannot be encoded.');
        }
        $header = 'filename="' . $fallback . '"';
        return $fallback === $filename ? $header : $header . "; filename*=UTF-8''" . rawurlencode($filename);
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
