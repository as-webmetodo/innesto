<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use InvalidArgumentException;

/** The mail service of a Module that requires the `mail` capability: PHP's mail(), plain text. */
final class Mail
{
    /** Whether this host can send mail at all: mail() is missing when disabled. */
    public static function available(): bool
    {
        return function_exists('mail');
    }

    /** @return bool whether the host's mailer accepted the message */
    public function send(string $to, string $subject, string $body): bool
    {
        // A line break in a header would let the caller add headers of its own.
        if (preg_match('/[\r\n]/', $to . $subject) === 1 || preg_match('/^[^@\s,]+@[^@\s,]+$/', $to) !== 1) {
            throw new InvalidArgumentException('A mail needs one plain address and a one-line subject');
        }

        return mail($to, $subject, $body, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
