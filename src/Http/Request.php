<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots\Http;

/**
 * What Roots reads from an HTTP request. Only the stub entry points touch the
 * superglobals, through fromGlobals().
 */
final class Request
{
    /**
     * @param string $entry the stub entry point: `index` or `dev`
     * @param string $basePath the URL path of `<roots>`, with a trailing slash
     * @param array<string, string> $form the POST fields
     * @param array<string, string> $cookies
     * @param array<string, string> $headers by lower-case name
     * @param array<string, string> $query the URL's query parameters
     */
    public function __construct(
        public readonly string $method,
        public readonly string $entry,
        public readonly string $basePath,
        public readonly string $host,
        public readonly bool $https,
        public readonly string $ip,
        public readonly array $form = [],
        public readonly array $cookies = [],
        public readonly array $headers = [],
        public readonly array $query = [],
    ) {
    }

    public static function fromGlobals(string $entry): self
    {
        $script = is_string($_SERVER['SCRIPT_NAME'] ?? null) ? $_SERVER['SCRIPT_NAME'] : '/index.php';
        $base = dirname($script);
        if ($entry !== 'index') {
            $base = dirname($base);
        }
        $headers = [];
        foreach (['origin' => 'HTTP_ORIGIN', 'referer' => 'HTTP_REFERER', 'accept-language' => 'HTTP_ACCEPT_LANGUAGE'] as $name => $key) {
            if (is_string($_SERVER[$key] ?? null)) {
                $headers[$name] = $_SERVER[$key];
            }
        }
        return new self(
            is_string($_SERVER['REQUEST_METHOD'] ?? null) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET',
            $entry,
            rtrim($base, '/') . '/',
            is_string($_SERVER['HTTP_HOST'] ?? null) ? $_SERVER['HTTP_HOST'] : '',
            self::httpsFromGlobals(),
            is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '',
            self::strings($_POST),
            self::strings($_COOKIE),
            $headers,
            self::strings($_GET),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** The scheme, host and port this request reached Roots on. */
    public function origin(): string
    {
        return ($this->https ? 'https' : 'http') . '://' . $this->host;
    }

    /** What the server says; X-Forwarded-* is never read, since any client can send it. */
    private static function httpsFromGlobals(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        $scheme = $_SERVER['REQUEST_SCHEME'] ?? '';
        $port = $_SERVER['SERVER_PORT'] ?? '';

        return (is_string($https) && $https !== '' && strtolower($https) !== 'off')
            || (is_string($scheme) && strtolower($scheme) === 'https')
            || ((is_string($port) || is_int($port)) && (string) $port === '443');
    }

    /**
     * @param array<mixed> $values
     * @return array<string, string> the string values only; arrays are untrusted shapes
     */
    private static function strings(array $values): array
    {
        $strings = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $strings[$key] = $value;
            }
        }

        return $strings;
    }
}
