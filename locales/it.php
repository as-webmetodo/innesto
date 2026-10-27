<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

defined('INNESTO_ROOTS') || exit;

return [
    'plural' => static fn (int $n): string => $n === 1 ? 'one' : 'other',
    'date.weekdays' => ['lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato', 'domenica'],
    'date.months' => ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'],
    'date.day_month' => '{d} {month}',
    'date.today' => 'oggi',
    'date.tomorrow' => 'domani',
    'time.pattern' => '{HH}:{mm}',
    'dev.title' => 'Innesto Roots: area Developer',
    'dev.error.https' => 'L\'area Developer richiede HTTPS. Apri questa pagina con https://.',
    'dev.error.data_dir' => 'Roots non riesce a creare la sua Data directory. Rendi scrivibile da PHP una cartella fuori dalla webroot, oppure la cartella di Roots.',
    'dev.error.forbidden' => 'Questo modulo non arriva dall\'area Developer. Ricarica la pagina e riprova.',
    'dev.error.code' => 'Il Setup code non è corretto.',
    'dev.error.password' => 'La password non è corretta.',
    'dev.error.password_short' => 'La password deve avere almeno 12 caratteri.',
    'dev.error.throttled' => 'Troppi tentativi falliti. Riprova tra {seconds} s.',
    'dev.upload.title' => 'Carica data-path.php',
    'dev.upload.text' => 'Roots non può scrivere nella sua cartella. Via FTP, carica nella cartella di Roots un file chiamato data-path.php con esattamente questo contenuto, poi ricarica la pagina.',
    'dev.setup.title' => 'Primo avvio',
    'dev.setup.text' => 'Roots ha scritto un Setup code in setup-code.php nella sua Data directory (data-path.php nella cartella di Roots dice dove). Leggilo via FTP e inseriscilo con una nuova password Developer.',
    'dev.setup.reset' => 'È in corso un reset della password. La password attuale funziona finché il reset non è completato.',
    'dev.setup.code' => 'Setup code',
    'dev.setup.submit' => 'Imposta la password ed entra',
    'dev.password' => 'Password Developer',
    'dev.password.new' => 'Nuova password Developer (almeno 12 caratteri)',
    'dev.password.current' => 'Password attuale',
    'dev.password.change.title' => 'Cambia la password',
    'dev.password.change.submit' => 'Cambia la password ed esci da ogni sessione',
    'dev.signin.title' => 'Accedi',
    'dev.signin.submit' => 'Accedi',
    'dev.home.title' => 'Area Developer',
    'dev.home.signed_in' => 'Hai effettuato l\'accesso.',
    'dev.signout' => 'Esci',
];
