<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

use Innesto\Roots\Mail;

test('a mail with an injected header or not one plain address is refused before mail() runs', function (string $to, string $subject): void {
    expect(fn () => (new Mail())->send($to, $subject, 'Body'))->toThrow(InvalidArgumentException::class);
})->with([
    'line break in the subject' => ['a@example.com', "Hi\r\nBcc: victim@example.net"],
    'line break in the address' => ["a@example.com\nBcc: victim@example.net", 'Hi'],
    'two addresses' => ['a@example.com, b@example.com', 'Hi'],
    'no address' => ['', 'Hi'],
]);
