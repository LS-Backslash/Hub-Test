<?php
declare(strict_types=1);

/**
 * HTTP-Abruf ohne cURL (die Extension fehlt auf dem Server).
 * Nutzt PHP-Streams; allow_url_fopen und OpenSSL sind aktiv.
 *
 * @return array{ok: bool, status: int, body: string, headers: list<string>, error: ?string}
 */
function fetch_url(string $url, int $timeout = 20, array $headers = []): array
{
    $headerLines = ['Accept: */*'];
    foreach ($headers as $name => $value) {
        $headerLines[] = "{$name}: {$value}";
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'follow_location' => 1,
            'max_redirects' => 5,
            'ignore_errors' => true, // auch bei 4xx/5xx den Body lesen
            'user_agent' => config('http_user_agent', 'pu-webintern/1.0'),
            'header' => implode("\r\n", $headerLines),
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $error = null;
    set_error_handler(static function (int $no, string $msg) use (&$error): bool {
        $error = $msg;
        return true;
    });
    try {
        $body = file_get_contents($url, false, $context);
    } finally {
        restore_error_handler();
    }

    $responseHeaders = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);

    // Bei Redirects stehen mehrere Statuszeilen drin, die letzte zählt.
    $status = 0;
    foreach ($responseHeaders as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        }
    }

    if ($body === false) {
        return ['ok' => false, 'status' => $status, 'body' => '', 'headers' => $responseHeaders, 'error' => $error ?? 'Abruf fehlgeschlagen'];
    }

    $ok = $status >= 200 && $status < 300;
    return ['ok' => $ok, 'status' => $status, 'body' => $body, 'headers' => $responseHeaders, 'error' => $ok ? null : "HTTP {$status}"];
}
