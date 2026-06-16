# Componenta HTTP Responder

Построитель HTTP-ответов PSR-7. Пакет централизует создание JSON, текста, HTML, XML, перенаправлений, файловых ответов, ответов об ошибках и заголовков кеширования.

Используйте его в контроллерах, обработчиках маршрутов и промежуточных обработчиках, которым нужно создавать ответы без зависимости от конкретной реализации PSR-7.

## Граница пакета

Пакет только строит PSR-7 ответы. Он не отправляет ответы клиенту, не создает серверные запросы и не выбирает реализацию PSR-7. Для отправки ответов используйте `componenta/http-emitter`, а для регистрации фабрик PSR-17 подключите один из пакетов `componenta/http-psr-*`.

## Установка

```bash
composer require componenta/http-responder
```

Пакет объявляет `Componenta\Http\ResponderConfigProvider` в `extra.componenta.config-providers`.
Если установлен `componenta/composer-plugin`, провайдер автоматически добавляется в сгенерированный список провайдеров.

Зарегистрируйте одну реализацию PSR-17, например `componenta/http-psr-nyholm`, чтобы в контейнере были фабрики ответов и потоков.

## Конфигурация

`ResponderConfigProvider` регистрирует `Responder` из следующих зависимостей:

| Зависимость | Назначение |
|---|---|
| `ResponseFactoryInterface` | Создает PSR-7 ответы. |
| `StreamFactoryInterface` | Создает потоки тела ответа. |

Провайдер также подключает провайдер определения MIME-типа для файловых ответов.

## Основной API

`Responder` — основной сервис для пользовательского кода. Он умеет вывести тип ответа из переданного содержимого или явно создать нужный вид ответа.

```php
use Componenta\Http\Responder;

/** @var Responder $responder */

$json = $responder->json(['status' => 'ok']);
$redirect = $responder->seeOther('/dashboard');
$file = $responder->downloadFile('/tmp/report.pdf');
```

### Ответы с содержимым

| Метод | Назначение |
|---|---|
| `respond(?int $code = null, mixed $content = null, ?string $contentType = null)` | Строит ответ из `null`, `ResponseInterface`, `StreamInterface`, `string`, `array`, `JsonSerializable`, `Arrayable`, `Stringable` или потокового ресурса. |
| `empty(int $status = 204)` | Создает пустой ответ. |
| `text(string $content, int $status = 200)` | Создает ответ `text/plain; charset=utf-8`. |
| `html(string $content, int $status = 200)` | Создает ответ `text/html; charset=utf-8`. |
| `xml(string $content, int $status = 200)` | Создает ответ `application/xml; charset=utf-8`. |
| `json(mixed $data, int $status = 200, int $flags = ...)` | Кодирует данные в JSON с `JSON_THROW_ON_ERROR`, `JSON_UNESCAPED_UNICODE` и `JSON_UNESCAPED_SLASHES` по умолчанию. |
| `jsonp(mixed $data, string $callback, int $status = 200)` | Создает JavaScript-ответ с функцией обратного вызова после проверки имени функции. |

Если `respond()` получает статус `204` или `304`, он вернет пустой ответ даже при переданном содержимом.

### Перенаправления

| Метод | Статус |
|---|---|
| `redirect(string $url, int $status = 302)` | Перенаправление с произвольным статусом. |
| `movedPermanently(string $url)` | `301` |
| `found(string $url)` | `302` |
| `seeOther(string $url)` | `303` |
| `temporaryRedirect(string $url)` | `307` |
| `permanentRedirect(string $url)` | `308` |
| `back(?string $referer, string $fallback = '/')` | `302` на адрес из `Referer` или запасной адрес. |

Адрес перенаправления обрезается по краям и отклоняется, если он пустой или содержит управляющие символы.

### Файлы

`download()` и `inline()` принимают `StreamInterface`, потоковый ресурс или строковое содержимое. `downloadFile()` и `inlineFile()` читают абсолютный путь в файловой системе и по возможности определяют тип содержимого по потоку.

```php
$response = $responder->inlineFile('/srv/files/manual.pdf');
$nginx = $responder->xAccelRedirect('/internal/manual.pdf', 'manual.pdf');
```

| Метод | Назначение |
|---|---|
| `download()` / `downloadFile()` | Создают ответы с `Content-Disposition: attachment`. |
| `inline()` / `inlineFile()` | Создают ответы с `Content-Disposition: inline`. |
| `file()` / `fileFromPath()` | Низкоуровневые файловые ответы с явным способом отображения. |
| `xAccelRedirect()` | Ответ только с заголовками для внутренней отдачи файла через nginx. |
| `xSendfile()` | Ответ только с заголовками для Apache `mod_xsendfile`. |

### Ошибки и условные ответы

Для типовых HTTP-статусов есть методы `badRequest()`, `unauthorized()`, `forbidden()`, `notFound()`, `methodNotAllowed()`, `conflict()`, `gone()`, `unprocessableEntity()`, `tooManyRequests()`, `serverError()`, `notImplemented()`, `serviceUnavailable()`, `notModified()`, `preconditionFailed()` и `rangeNotSatisfiable()`.

`unauthorized()` может добавить `WWW-Authenticate`; `tooManyRequests()` и `serviceUnavailable()` могут добавить `Retry-After`.

### Заголовки кеширования

Используйте `withCache()`, `withNoCache()`, `withEtag()` и `withLastModified()`, чтобы добавить заголовки кеширования к существующему ответу. Методы возвращают измененный PSR-7 ответ и не мутируют исходный экземпляр.

## Ошибки

`Responder` выбрасывает `InvalidArgumentException`, если получает неподдерживаемое содержимое, некорректный статус, недопустимый адрес перенаправления или заголовок, нечитаемый файл, некорректное имя файла, некорректное значение кеширования или недопустимое имя функции для JSONP.
