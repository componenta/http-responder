# Componenta HTTP Responder

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

When `respond()` receives status `204` or `304`, it returns an empty response even if content is supplied.

### Redirects

| Method | Status |
|---|---|
| `redirect(string $url, int $status = 302)` | Custom redirect status. |
| `movedPermanently(string $url)` | `301` |
| `found(string $url)` | `302` |
| `seeOther(string $url)` | `303` |
| `temporaryRedirect(string $url)` | `307` |
| `permanentRedirect(string $url)` | `308` |
| `back(?string $referer, string $fallback = '/')` | `302` to the referrer or fallback URL. |

Redirect URLs are trimmed and rejected when empty or when they contain control characters.

### Files

`download()` and `inline()` accept a `StreamInterface`, stream resource, or string content. `downloadFile()` and `inlineFile()` read from an absolute filesystem path and auto-detect the content type from the stream when possible.

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
