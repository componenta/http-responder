# Componenta HTTP Responder

Requires Componenta Config 4 and MIME Type Detector 2.

Response builder for PSR-7 HTTP responses. The package centralizes common response creation: JSON, text, HTML, redirects, downloads, inline files, error responses, and cache headers.

Use this package in controllers, route handlers, and middleware that need to create responses without depending on a concrete PSR-7 implementation.

## Boundary

This package only builds PSR-7 responses. It does not emit responses, does not create server requests, and does not choose a PSR-7 implementation. Use `componenta/http-emitter` to send responses and one of the `componenta/http-psr-*` packages to register PSR-17 factories.

## Installation

```bash
composer require componenta/http-responder
```

The package declares `Componenta\Http\ResponderConfigProvider` in `extra.componenta.config-providers`.
When `componenta/composer-plugin` is installed, the provider is added to the generated provider list automatically.

Register one PSR-17 implementation package, for example `componenta/http-psr-nyholm`, so the container has response and stream factories.

## Configuration

`ResponderConfigProvider` registers `Responder` from:

| Dependency | Purpose |
|---|---|
| `ResponseFactoryInterface` | Creates PSR-7 responses. |
| `StreamFactoryInterface` | Creates body streams. |
| `MimeTypeDetectorInterface` | Detects a MIME type from content. |
| `MimeMapInterface` | Maps filename extensions to MIME types. |

The provider also includes the mime type detector provider for file responses.

## Main API

`Responder` is the consumer-facing service. It can either infer a response from arbitrary content or create a specific response type explicitly.

```php
use Componenta\Http\Responder;

/** @var Responder $responder */

$json = $responder->json(['status' => 'ok']);
$redirect = $responder->seeOther('/dashboard');
$file = $responder->downloadFile('/tmp/report.pdf');
```

### Content responses

| Method | Purpose |
|---|---|
| `respond(?int $code = null, mixed $content = null, ?string $contentType = null)` | Builds a response from `null`, `ResponseInterface`, `StreamInterface`, `string`, `array`, `JsonSerializable`, `Arrayable`, `Stringable`, or a stream resource. |
| `empty(int $status = 204)` | Creates an empty response. |
| `text(string $content, int $status = 200)` | Creates a `text/plain; charset=utf-8` response. |
| `html(string $content, int $status = 200)` | Creates a `text/html; charset=utf-8` response. |
| `xml(string $content, int $status = 200)` | Creates an `application/xml; charset=utf-8` response. |
| `json(mixed $data, int $status = 200, int $flags = ...)` | JSON-encodes data with `JSON_THROW_ON_ERROR`, `JSON_UNESCAPED_UNICODE`, and `JSON_UNESCAPED_SLASHES` by default. |
| `jsonp(mixed $data, string $callback, int $status = 200)` | Creates a JavaScript callback response after validating the callback name. |

New responses with status 1xx, 204, 205 or 304 omit content. A `ResponseInterface` passed to `respond()` is returned unchanged. For a prohibited body, file helpers do not take ownership of caller streams/resources or open a file unnecessarily. For an actual file response, ownership of the returned stream belongs to the response consumer.

### Redirects

| Method | Status |
|---|---|
| `redirect(string $url, int $status = 302)` | Custom redirect status. |
| `movedPermanently(string $url)` | `301` |
| `found(string $url)` | `302` |
| `seeOther(string $url)` | `303` |
| `temporaryRedirect(string $url)` | `307` |
| `permanentRedirect(string $url)` | `308` |
| `back(?string $referer, string $fallback = '/', ?string $trustedOrigin = null)` | `302` to a root-relative referrer or one on the explicitly trusted origin; otherwise fallback. |

Redirect URLs must be non-empty URI references without raw spaces, control characters or backslashes. `redirect()` and its shortcuts require an application-authorized destination. `back()` defaults to root-relative paths; an absolute referrer is allowed only on the exact trusted HTTP(S) origin provided by the application. The fallback must be trusted configuration.

### Files

`download()` and `inline()` accept a `StreamInterface`, stream resource, or string content. `downloadFile()` and `inlineFile()` read ordinary readable regular filesystem files; stream wrappers and directories are rejected. The application must authorize the path before calling these helpers. MIME detection uses the injected MIME 2 contract and map. A filename must be a valid UTF-8 basename; non-ASCII names get an ASCII fallback plus RFC 8187 `filename*`. The responder never infers range support solely from seekability.

```php
$response = $responder->inlineFile('/srv/files/manual.pdf');
$nginx = $responder->xAccelRedirect('/internal/manual.pdf', 'manual.pdf');
```

| Method | Purpose |
|---|---|
| `download()` / `downloadFile()` | Builds `Content-Disposition: attachment` responses. |
| `inline()` / `inlineFile()` | Builds `Content-Disposition: inline` responses. |
| `file()` / `fileFromPath()` | Lower-level file response helpers with an explicit disposition. |
| `xAccelRedirect()` | Header-only response for nginx internal file serving. |
| `xSendfile()` | Header-only response for Apache `mod_xsendfile`. |

### Error and conditional responses

Convenience methods are available for common HTTP statuses: `badRequest()`, `unauthorized()`, `forbidden()`, `notFound()`, `methodNotAllowed()`, `conflict()`, `gone()`, `unprocessableEntity()`, `tooManyRequests()`, `serverError()`, `notImplemented()`, `serviceUnavailable()`, `notModified()`, `preconditionFailed()`, and `rangeNotSatisfiable()`.

`unauthorized()` can add `WWW-Authenticate`; `tooManyRequests()` and `serviceUnavailable()` can add `Retry-After`.

### Cache headers

Use `withCache()`, `withNoCache()`, `withEtag()`, and `withLastModified()` to add cache-related headers to an existing response. The methods return a modified PSR-7 response and do not mutate the original instance.

## Errors

The responder throws `InvalidArgumentException` when it receives unsupported content, invalid status codes, invalid redirect or header values, unreadable files, invalid filenames, invalid cache values, or invalid JSONP callback names.

## Development against the next MIME detector release

The runtime constraint is `componenta/mimetype-detector: ^2.0`. Until that release is published, the audit CI uses the exact public refactor snapshot `0d4b88e899fe828f0af41c57f6d47f615191d901` through a local Composer path repository with a temporary `^2.0@dev` constraint. The published package must not depend on a branch alias. The detector is not vendored or modified.

`methodNotAllowed()` preserves the exact case of valid HTTP method tokens and rejects invalid entries. `withEtag()` requires a non-empty RFC 9110 entity-tag value without surrounding quotes. JSONP is legacy script execution, not a safe way to expose private or authenticated data; only use an application-authorized callback and public data. Treat detected MIME types and filenames as metadata, not proof that active content is safe to display inline.

## Tests and benchmarks

`composer check` runs Pest and PHPStan. `composer mutation` uses the package's own source and Pest mutation testing. `composer benchmark` runs PHPBench over construction, text, JSON, response dispatch, redirects, downloads, empty responses and cache headers. The MIME detector is replaced with a fixed public-contract stub in these benchmarks; the detector itself is not benchmarked.

Seekable response bodies are rewound before being attached so a consumer can read the whole representation from its current cursor. Non-seekable bodies retain their current cursor; their total stream size is not used as the response length.
