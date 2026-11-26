<?php

declare(strict_types=1);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$binary = getenv('PHP_CGI_BINARY');
check(is_string($binary) && is_executable($binary), 'A matching PHP CGI binary is required.');
$script = __DIR__ . '/emit.php';

$scenarios = [
    'json' => [201, '{"ok":true}', ['content-length' => '11', 'content-type' => 'application/json; charset=utf-8']],
    'head' => [201, '', ['content-length' => '11', 'content-type' => 'application/json; charset=utf-8']],
    'file' => [200, 'prefix:payload', ['content-length' => '14', 'content-disposition' => 'attachment; filename="data.txt"', 'accept-ranges' => null]],
    'range' => [206, 'abc', ['content-length' => '3', 'content-range' => 'bytes 10-12/20', 'accept-ranges' => null]],
    'not-modified' => [304, '', ['content-length' => '14', 'etag' => '"one"']],
    'range-error' => [416, '', ['content-range' => 'bytes */20', 'content-length' => '0']],
    'offload' => [200, '', ['x-accel-redirect' => '/internal/data', 'content-length' => null]],
    'detected' => [200, 'plain text', ['content-length' => '10']],
    'denied' => [403, 'denied', ['content-length' => '6', 'x-accel-redirect' => null, 'x-sendfile' => null, 'content-encoding' => null, 'etag' => null]],
];

foreach ($scenarios as $name => [$expectedStatus, $expectedBody, $expectedFields]) {
    $process = proc_open([$binary, '-d', 'cgi.force_redirect=0', '-f', $script], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, __DIR__, array_replace(getenv(), [
        'REQUEST_METHOD' => $name === 'head' ? 'HEAD' : 'GET',
        'REDIRECT_STATUS' => '1',
        'SCRIPT_FILENAME' => $script,
        'SCRIPT_NAME' => '/emit.php',
        'DOCUMENT_ROOT' => __DIR__,
        'COMPONENTA_SCENARIO' => $name,
    ]));
    check(is_resource($process), 'Could not start CGI.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0 && $errors === '' && is_string($output), "$name: CGI failed: $errors");
    $parts = preg_split('/\r?\n\r?\n/', $output, 2);
    check(is_array($parts) && count($parts) === 2, "$name: missing HTTP fields.");
    [$fields, $body] = $parts;
    $headers = [];
    foreach (preg_split('/\r?\n/', $fields) ?: [] as $line) {
        [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
        $headers[strtolower($field)][] = trim($value);
    }
    $status = isset($headers['status'][0]) ? (int) $headers['status'][0] : 200;
    check($status === $expectedStatus, "$name: incorrect status $status.");
    check($body === $expectedBody, "$name: incorrect emitted bytes.");
    foreach ($expectedFields as $field => $value) {
        if ($value === null) {
            check(!isset($headers[$field]), "$name: unexpected $field.");
        } else {
            check(($headers[$field] ?? null) === [$value], "$name: incorrect or repeated $field: " . json_encode($headers[$field] ?? null));
        }
    }
    check(!isset($headers['transfer-encoding']), "$name: application-owned transfer coding.");
    if ($name === 'detected') {
        // Verify only that the response consumes the MIME 2 return contract;
        // classification accuracy is outside the scope of this fixture.
        check(isset($headers['content-type'][0]) && $headers['content-type'][0] !== '', "$name: missing media type.");
    }
    echo "$name: passed\n";
}
echo count($scenarios) . " responder/emitter interoperability scenarios passed.\n";
