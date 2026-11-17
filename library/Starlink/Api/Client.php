<?php

namespace Icinga\Module\Starlink\Api;

use RuntimeException;

/**
 * Minimal Starlink Public API v2 client (OIDC client_credentials, php-curl).
 *
 * Read-only by design: only GET plus the two telemetry POSTs are allowed.
 */
class Client
{
    const BASE = 'https://starlink.com/api';
    const TOKEN_URL = self::BASE . '/auth/connect/token';
    const POST_ALLOW = ['/public/v2/telemetry/stream', '/public/v2/telemetry/query'];

    /** @var string */
    protected $clientId;

    /** @var string */
    protected $clientSecret;

    /** @var ?string */
    protected $token;

    /** @var float */
    protected $tokenExpires = 0;

    /** @var int */
    protected $timeout = 90;

    public function __construct(string $clientId, string $clientSecret)
    {
        if (! extension_loaded('curl')) {
            throw new RuntimeException('php-curl is required');
        }
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    /**
     * Plain KEY=VALUE file (never shell-sourced, so *, (, $ in secrets are fine).
     * One pair of surrounding quotes is stripped, "export " prefixes are accepted.
     */
    public static function fromCredentialsFile(string $file): self
    {
        if (! is_readable($file)) {
            throw new RuntimeException("Credentials file $file is not readable by " . get_current_user()
                . ' (uid ' . getmyuid() . ')');
        }
        $values = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (strpos($line, 'export ') === 0) {
                $line = ltrim(substr($line, 7));
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            $len = strlen($val);
            if ($len >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[$len - 1] === $val[0]) {
                $val = substr($val, 1, -1);
            }
            $values[$key] = $val;
        }
        if (empty($values['CLIENT_ID']) || empty($values['CLIENT_SECRET'])) {
            throw new RuntimeException("$file must define CLIENT_ID and CLIENT_SECRET");
        }

        return new static($values['CLIENT_ID'], $values['CLIENT_SECRET']);
    }

    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * @return array [int status, string body, array lowercased headers]
     */
    protected function http(string $method, string $url, array $headers, ?string $body): array
    {
        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => array_merge(['User-Agent: icingaweb2-module-starlink/0.1'], $headers),
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $result = curl_exec($ch);
        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new ApiException("$method $url: transport error: $error");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, (string) $result, $responseHeaders];
    }

    protected function token(bool $force = false): string
    {
        if (! $force && $this->token !== null && $this->tokenExpires - 60 > microtime(true)) {
            return $this->token;
        }
        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
        [$status, $text] = $this->http('POST', self::TOKEN_URL, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], $body);
        $json = json_decode($text, true);
        if ($status !== 200 || ! isset($json['access_token'])) {
            throw new ApiException(sprintf('Token request failed: HTTP %d: %s', $status, substr($text, 0, 300)), $status);
        }
        $this->token = $json['access_token'];
        $this->tokenExpires = microtime(true) + (int) ($json['expires_in'] ?? 600);

        return $this->token;
    }

    public function request(string $method, string $path, ?array $json = null, array $query = []): array
    {
        $method = strtoupper($method);
        if ($method === 'POST' && ! in_array($path, self::POST_ALLOW, true)) {
            throw new RuntimeException("Refusing non-allowlisted POST $path");
        }
        if ($method !== 'GET' && $method !== 'POST') {
            throw new RuntimeException("Refusing method $method");
        }
        $url = self::BASE . $path . ($query ? '?' . http_build_query($query) : '');
        $body = $json === null ? null : json_encode($json);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $headers = ['Authorization: Bearer ' . $this->token($attempt > 0), 'Accept: application/json'];
            if ($body !== null) {
                $headers[] = 'Content-Type: application/json';
            }
            [$status, $text, $respHeaders] = $this->http($method, $url, $headers, $body);
            if ($status === 401 && $attempt === 0) {
                continue; // token rejected early, mint a new one once
            }
            break;
        }
        if ($status === 429) {
            $retry = isset($respHeaders['retry-after']) ? (int) $respHeaders['retry-after'] : 30;
            throw new ApiException("$method $path: rate limited", 429, $retry);
        }
        if ($status < 200 || $status >= 300) {
            throw new ApiException(sprintf('%s %s: HTTP %d: %s', $method, $path, $status, substr($text, 0, 500)), $status);
        }
        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new ApiException("$method $path: response is not JSON", $status);
        }

        return $decoded;
    }

    /**
     * One stream batch. NOTE: Starlink advances this service account's read
     * index on every successful response; persist before doing anything else.
     */
    public function stream(int $batchSize = 1000, int $maxLingerMs = 15000): array
    {
        return $this->request('POST', '/public/v2/telemetry/stream', [
            'batchSize'   => $batchSize,
            'maxLingerMs' => $maxLingerMs,
        ]);
    }

    public function account(): array
    {
        return $this->request('GET', '/public/v2/account');
    }
}
