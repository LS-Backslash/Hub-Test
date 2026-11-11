<?php
declare(strict_types=1);

/**
 * Google Ad Manager: Anmeldung per Dienstkonto und Zugriff auf
 *  - die neue REST-API (Ad Manager API, admanager.googleapis.com/v1) und
 *  - die SOAP-API (ads.google.com/apis/ads/publisher/<version>) als Ausweichweg.
 *
 * Konfiguration in config.php, Eintrag 'gam':
 *   'network_code' => '…', 'key_file' => '/data/wwwcust/ads/home/config/admanager-key.json',
 *   'application_name' => 'pd-webintern', 'soap_version' => 'v202605'
 *
 * Der private Schlüssel bleibt in der JSON-Datei auf dem Server; er wird nie ausgegeben oder geloggt.
 * Das Dienstkonto wird mit einer anderen Anwendung geteilt: hier wird nur ein kurzlebiges Zugriffstoken
 * angefordert – das beeinflusst andere Nutzer des Dienstkontos nicht.
 */

const GAM_SCOPE = 'https://www.googleapis.com/auth/admanager';
const GAM_REST_BASE = 'https://admanager.googleapis.com/v1/';
const GAM_SOAP_BASE = 'https://ads.google.com/apis/ads/publisher/';
const GAM_TIMEOUT = 30;

final class GamError extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0, public readonly ?array $details = null)
    {
        parent::__construct($message);
    }
}

function gam_config(): array
{
    $c = config('gam');
    if (!is_array($c) || empty($c['network_code'])) {
        throw new GamError('Ad Manager ist nicht konfiguriert (config.php, Eintrag "gam" mit network_code).');
    }
    return [
        'network_code' => (string) $c['network_code'],
        'key_file' => (string) ($c['key_file'] ?? APP_CONFIG . '/admanager-key.json'),
        'application_name' => (string) ($c['application_name'] ?? 'pd-webintern'),
        'soap_version' => (string) ($c['soap_version'] ?? 'v202605'),
        'rest_base' => (string) ($c['rest_base'] ?? GAM_REST_BASE),       // für Tests überschreibbar
        'soap_base' => (string) ($c['soap_base'] ?? GAM_SOAP_BASE),
    ];
}

/** Liest die Schlüsseldatei; gibt nur prüfbare, nicht geheime Infos nach außen. */
function gam_key(): array
{
    $file = gam_config()['key_file'];
    if (!is_file($file)) {
        throw new GamError("Schlüsseldatei fehlt: {$file}");
    }
    $key = json_decode((string) file_get_contents($file), true);
    if (!is_array($key) || ($key['type'] ?? '') !== 'service_account' || empty($key['private_key']) || empty($key['client_email'])) {
        throw new GamError('Schlüsseldatei ist kein gültiger Dienstkonto-Schlüssel (type, private_key, client_email).');
    }
    return $key;
}

function gam_b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** Baut die signierte Anmelde-Anfrage (JWT, RS256) für das Dienstkonto. */
function gam_jwt(array $key, int $now): string
{
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    if (!empty($key['private_key_id'])) {
        $header['kid'] = $key['private_key_id'];
    }
    $claims = [
        'iss' => $key['client_email'],
        'scope' => GAM_SCOPE,
        'aud' => $key['token_uri'] ?? 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ];
    $input = gam_b64url(json_encode($header)) . '.' . gam_b64url(json_encode($claims));
    $pkey = openssl_pkey_get_private($key['private_key']);
    if ($pkey === false || !openssl_sign($input, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
        throw new GamError('Privater Schlüssel konnte nicht gelesen werden (Datei beschädigt?).');
    }
    return $input . '.' . gam_b64url($signature);
}

/** Zugriffstoken (1 Stunde gültig), pro Prozess zwischengespeichert. */
function gam_token(): string
{
    static $cache = null;
    if ($cache !== null && $cache['expires'] > time() + 60) {
        return $cache['token'];
    }
    $key = gam_key();
    $res = gam_http('POST', $key['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => gam_jwt($key, time()),
    ]));
    $data = json_decode($res['body'], true);
    if ($res['status'] !== 200 || empty($data['access_token'])) {
        $why = $data['error_description'] ?? $data['error'] ?? "HTTP {$res['status']}";
        throw new GamError("Anmeldung bei Google fehlgeschlagen: {$why}", $res['status']);
    }
    $cache = ['token' => $data['access_token'], 'expires' => time() + (int) ($data['expires_in'] ?? 3600)];
    return $cache['token'];
}

/** Einfacher HTTP-Aufruf über cURL (mit optionalem Proxy/CA aus config.php). */
function gam_http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => GAM_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'pd-webintern/1.0',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if ($proxy = config('http_proxy')) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
    }
    if ($ca = config('http_ca_file')) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    }
    $out = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = $out === false ? curl_error($ch) : null;
    curl_close($ch);
    if ($out === false) {
        throw new GamError('Keine Verbindung zu Google: ' . $err);
    }
    return ['status' => $status, 'body' => (string) $out];
}

/**
 * REST-API aufrufen. $path relativ zu v1/, z. B. "networks/123/adUnits".
 *
 * @return array dekodierte JSON-Antwort
 */
function gam_rest(string $method, string $path, array $query = [], ?array $body = null): array
{
    $cfg = gam_config();
    $url = rtrim($cfg['rest_base'], '/') . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
    $res = gam_http($method, $url, [
        'Authorization: Bearer ' . gam_token(),
        'Content-Type: application/json',
        'Accept: application/json',
    ], $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $data = json_decode($res['body'], true);
    if ($res['status'] < 200 || $res['status'] >= 300) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $res['status']);
        throw new GamError(gam_explain($res['status'], $msg), $res['status'], is_array($data) ? $data : null);
    }
    return is_array($data) ? $data : [];
}

/** Übersetzt typische Google-Fehler in verständliche Hinweise. */
function gam_explain(int $status, string $msg): string
{
    $hint = match (true) {
        $status === 401 => 'Anmeldung abgelehnt.',
        $status === 403 && (str_contains($msg, 'has not been used') || str_contains($msg, 'is disabled'))
            => 'Die „Google Ad Manager API“ ist im Google-Cloud-Projekt nicht aktiviert (für die REST-API nötig).',
        $status === 403 && stripos($msg, 'network') !== false
            => 'Kein Zugriff auf dieses Netzwerk – Netzwerk-Code prüfen bzw. ob das Dienstkonto dort als Nutzer eingetragen ist.',
        $status === 403 => 'Keine Berechtigung – Rolle des Dienstkontos im Ad Manager prüfen.',
        $status === 404 => 'Nicht gefunden – Netzwerk-Code oder Funktion in der REST-API (Beta) nicht verfügbar.',
        $status === 429 => 'Zu viele Anfragen – Google bremst, später erneut versuchen.',
        default => null,
    };
    return trim(($hint ? $hint . ' ' : '') . "(Google: {$msg})");
}

/**
 * SOAP-API aufrufen, z. B. gam_soap('NetworkService', 'getCurrentNetwork').
 */
function gam_soap(string $service, string $method, array $params = []): mixed
{
    $cfg = gam_config();
    $ns = 'https://www.google.com/apis/ads/publisher/' . $cfg['soap_version'];
    $wsdl = rtrim($cfg['soap_base'], '/') . '/' . $cfg['soap_version'] . "/{$service}?wsdl";
    $context = stream_context_create(['http' => [
        'header' => 'Authorization: Bearer ' . gam_token(),
        'timeout' => GAM_TIMEOUT,
        'user_agent' => 'pd-webintern/1.0',
    ]]);
    try {
        $client = new SoapClient($wsdl, [
            'stream_context' => $context,
            'exceptions' => true,
            'trace' => false,
            'cache_wsdl' => WSDL_CACHE_DISK,
            'connection_timeout' => 10,
            'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
        ] + gam_soap_proxy());
        $client->__setSoapHeaders(new SoapHeader($ns, 'RequestHeader', [
            'networkCode' => $cfg['network_code'],
            'applicationName' => $cfg['application_name'],
        ]));
        $result = $client->__soapCall($method, [$params]);
        return $result->rval ?? $result;
    } catch (SoapFault $f) {
        throw new GamError('SOAP-API: ' . $f->getMessage());
    }
}

/** Proxy-Einstellung für SoapClient aus config.php (http_proxy), falls gesetzt. */
function gam_soap_proxy(): array
{
    $proxy = config('http_proxy');
    if (!$proxy || !($p = parse_url($proxy)) || empty($p['host'])) {
        return [];
    }
    return ['proxy_host' => $p['host'], 'proxy_port' => (int) ($p['port'] ?? 8080)];
}

