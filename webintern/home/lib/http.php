<?php
declare(strict_types=1);

/**
 * HTTP-GET mit cURL (bevorzugt) oder PHP-Streams als Rückfall.
 *
 * Optionale Einstellungen in config.php:
 *   'http_proxy'   => 'http://proxy.example:3128'  (falls die IT einen Proxy vorschreibt)
 *   'http_ca_file' => '/pfad/zu/ca-bundle.crt'      (nur bei eigener Zertifizierungsstelle)
 *
 * @return array{ok: bool, status: int, body: string, headers: list<string>, error: ?string}
 */
function fetch_url(string $url, int $timeout = 20, array $headers = []): array
{
    $headerLines = ['Accept: text/plain, */*'];
    foreach ($headers as $name => $value) {
        $headerLines[] = "{$name}: {$value}";
    }
    if (!preg_match('#^https?://#i', $url)) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'error' => 'Nur http(s)-Adressen erlaubt'];
    }

    return function_exists('curl_init')
        ? fetch_url_curl($url, $timeout, $headerLines)
        : fetch_url_stream($url, $timeout, $headerLines);
}

function fetch_url_curl(string $url, int $timeout, array $headerLines): array
{
    $responseHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => config('http_user_agent', 'pu-webintern/1.0'),
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_ENCODING => '', // gzip/deflate/br automatisch entpacken
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
            if (trim($line) !== '') {
                $responseHeaders[] = trim($line);
            }
            return strlen($line);
        },
    ]);
    if ($proxy = config('http_proxy')) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
    }
    if ($ca = config('http_ca_file')) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    }

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = $body === false ? (curl_error($ch) ?: 'Abruf fehlgeschlagen') : null;
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'status' => $status, 'body' => '', 'headers' => $responseHeaders, 'error' => $error];
    }
    $ok = $status >= 200 && $status < 300;
    return ['ok' => $ok, 'status' => $status, 'body' => (string) $body, 'headers' => $responseHeaders, 'error' => $ok ? null : "HTTP {$status}"];
}

function fetch_url_stream(string $url, int $timeout, array $headerLines): array
{
    $http = [
        'method' => 'GET',
        'timeout' => $timeout,
        'follow_location' => 1,
        'max_redirects' => 5,
        'ignore_errors' => true, // auch bei 4xx/5xx den Body lesen
        'user_agent' => config('http_user_agent', 'pu-webintern/1.0'),
        'header' => implode("\r\n", $headerLines),
    ];
    if ($proxy = config('http_proxy')) {
        $http['proxy'] = preg_replace('#^https?://#', 'tcp://', $proxy);
        $http['request_fulluri'] = true;
    }
    $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
    if ($ca = config('http_ca_file')) {
        $ssl['cafile'] = $ca;
    }
    $context = stream_context_create(['http' => $http, 'ssl' => $ssl]);

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
