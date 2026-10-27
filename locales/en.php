<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

// Roots' catalogue for English; it must hold every key.
return [
    'plural' => static fn (int $n): string => $n === 1 ? 'one' : 'other',
    'date.weekdays' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
    'date.months' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    'date.day_month' => '{month} {d}',
    'date.today' => 'today',
    'date.tomorrow' => 'tomorrow',
    'time.pattern' => '{h}:{mm} {a}',
    'time.am' => 'AM',
    'time.pm' => 'PM',
    'dev.title' => 'Innesto Roots: Developer area',
    'dev.error.https' => 'The Developer area needs HTTPS. Open this page with https://.',
    'dev.error.data_dir' => 'Roots cannot create its Data directory. Make a directory outside the webroot, or the Roots folder, writable by PHP.',
    'dev.error.forbidden' => 'This form did not come from the Developer area. Reload the page and try again.',
    'dev.error.code' => 'The Setup code is not right.',
    'dev.error.password' => 'The password is not right.',
    'dev.error.password_short' => 'The password needs at least 12 characters.',
    'dev.error.throttled' => 'Too many failed attempts. Try again in {seconds} s.',
    'dev.upload.title' => 'Upload data-path.php',
    'dev.upload.text' => 'Roots cannot write to its folder. Over FTP, upload a file named data-path.php with exactly this content into the Roots folder, then reload this page.',
    'dev.setup.title' => 'First run',
    'dev.setup.text' => 'Roots wrote a Setup code into setup-code.php in its Data directory (data-path.php in the Roots folder says where). Read it over FTP and enter it with a new Developer password.',
    'dev.setup.reset' => 'A password reset is in progress. The current password still works until it completes.',
    'dev.setup.code' => 'Setup code',
    'dev.setup.submit' => 'Set the password and sign in',
    'dev.password' => 'Developer password',
    'dev.password.new' => 'New Developer password (at least 12 characters)',
    'dev.password.current' => 'Current password',
    'dev.password.change.title' => 'Change the password',
    'dev.password.change.submit' => 'Change the password and sign out everywhere',
    'dev.signin.title' => 'Sign in',
    'dev.signin.submit' => 'Sign in',
    'dev.home.title' => 'Developer area',
    'dev.home.signed_in' => 'You are signed in.',
    'dev.signout' => 'Sign out',
];
