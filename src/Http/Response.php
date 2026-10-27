<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots\Http;

/** What Roots answers; send() is the only place that emits it. */
final class Response
{
    /** @param list<array{string, string}> $headers name and value, repeatable (Set-Cookie) */
    public function __construct(public readonly int $status, public readonly array $headers = [], public readonly string $body = '')
    {
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self($status, [['Location', $location]]);
    }

    /** The first value of header $name, if any. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as [$header, $value]) {
            if (strcasecmp($header, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** @return list<string> every value of header $name */
    public function headers(string $name): array
    {
        $values = [];
        foreach ($this->headers as [$header, $value]) {
            if (strcasecmp($header, $name) === 0) {
                $values[] = $value;
            }
        }

        return $values;
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, [...$this->headers, [$name, $value]], $this->body);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as [$name, $value]) {
            header("$name: $value", false);
        }
        echo $this->body;
    }
}
