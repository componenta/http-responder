# Componenta HTTP Responder

Требуются Componenta Config 4 и MIME Type Detector 2.

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

Новые ответы со статусами 1xx, 204, 205 и 304 не содержат тела. Переданный в `respond()` готовый `ResponseInterface` возвращается неизменным.

### Перенаправления

| Метод | Статус |
|---|---|
| `redirect(string $url, int $status = 302)` | Перенаправление с произвольным статусом. |
| `movedPermanently(string $url)` | `301` |
| `found(string $url)` | `302` |
| `seeOther(string $url)` | `303` |
| `temporaryRedirect(string $url)` | `307` |
| `permanentRedirect(string $url)` | `308` |
| `back(?string $referer, string $fallback = '/', ?string $trustedOrigin = null)` | `302` на локальный путь или адрес явно доверенного origin, иначе на запасной адрес. |

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

Для работы необходимы `componenta/config ^4.0` и `componenta/mimetype-detector ^2.0`. До публикации MIME Type Detector 2 проверка CI использует закреплённый снимок ветки рефакторинга через локальный репозиторий Composer только в тестовой среде. Производственное ограничение остаётся `^2.0`.

## Проверки контрактов и границы безопасности

`back()` перенаправляет только на путь с одним начальным `/` либо на абсолютный URL точного явно доверенного HTTP(S)-origin (`trustedOrigin`). Иначе используется доверенный `fallback`. Остальные методы перенаправления требуют проверенного приложением назначения. `methodNotAllowed()` сохраняет регистр допустимых HTTP-методов и отклоняет некорректные значения. `withEtag()` проверяет символы entity-tag по RFC 9110. Имена файлов должны быть корректными UTF-8 именами без разделителей пути и управляющих символов; для международных имён создаётся ASCII-вариант и `filename*`. Файловые методы работают с обычными читаемыми файлами, не открывают stream wrappers и каталоги. Авторизация доступа к пути остаётся задачей приложения. Поддержка диапазонов не объявляется автоматически: производитель ответа должен действительно реализовать Range. При запрещённом содержимом переданные вызывающим кодом ресурсы не захватываются и файлы не открываются. MIME-тип не подтверждает безопасность содержимого; JSONP допустим только для публичных данных и доверенных callbacks.

`composer check` запускает Pest и PHPStan; `composer mutation` проверяет мутации исходного кода пакета; `composer benchmark` измеряет основной API через PHPBench с фиксированным тестовым детектором, не измеряя сам MIME-детектор.

Перематываемые потоки устанавливаются в начало перед созданием ответа: заявленная длина соответствует всему телу. Неперематываемые потоки сохраняют текущую позицию; их полный размер не используется как длина оставшегося содержимого.

Если содержимое ответа допускается, поток тела должен быть доступен для чтения. Статус и поля ответа проверяются до оборачивания переданного ресурса; после успешного оборачивания владение потоком определяется PSR-7/PSR-17.

При создании нового ответа удаляются значения фабрики, относящиеся к чужому представлению, фреймингу, выдаче файла или поддержке диапазонов. Общие поля приложения, например заголовки безопасности, сохраняются. Готовый `ResponseInterface` в `respond()` по-прежнему возвращается без изменений. Контрактные тесты выполняются с фабриками PSR-17 Nyholm и Guzzle.
