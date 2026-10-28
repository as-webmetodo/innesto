<?php

// SPDX-License-Identifier: MPL-2.0

declare(strict_types=1);

namespace Innesto\Roots;

use Throwable;

/**
 * Sends PHP warnings, uncaught errors and fatals to the Roots log, tagged
 * with the Module whose code was running (fatals are also counted against
 * it). Until the request has located the Data directory there is no log, and
 * they go to error_log().
 */
final class ErrorHandler
{
    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

    private ?RootsLog $log = null;

    private ?FaultFence $fence = null;

    /** Installs the handler and the shutdown hook for this request. */
    public static function install(): self
    {
        $handler = new self();
        set_error_handler($handler->handleError(...));
        register_shutdown_function(static fn () => $handler->shutdown(error_get_last()));

        return $handler;
    }

    /** From here on, errors go to $log, tagged through $fence. */
    public function attach(RootsLog $log, FaultFence $fence): void
    {
        $this->log = $log;
        $this->fence = $fence;
    }

    /** @return bool true: PHP's own handler never runs, so nothing reaches the output */
    public function handleError(int $level, string $message, string $file = '', int $line = 0): bool
    {
        // An error silenced with @, or not reported, stays silent.
        if ((error_reporting() & $level) === 0) {
            return true;
        }
        $this->write('warning', 'php.warning', $message, ['level' => $level, 'file' => $file, 'line' => $line]);

        return true;
    }

    public function uncaught(Throwable $error): void
    {
        $this->write('error', 'php.uncaught', $error::class . ': ' . $error->getMessage(), ['file' => $error->getFile(), 'line' => $error->getLine()]);
    }

    /** @param array{type: int, message: string, file: string, line: int}|null $last error_get_last() at shutdown */
    public function shutdown(?array $last): void
    {
        if ($last === null || ($last['type'] & self::FATAL) === 0) {
            return;
        }
        $context = ['file' => $last['file'], 'line' => $last['line']];
        $module = $this->fence?->current();
        if ($module !== null) {
            $this->fence->record($module, 'php.fatal', $last['message'], $context);

            return;
        }
        $this->write('error', 'php.fatal', $last['message'], $context);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $event, string $message, array $context): void
    {
        if ($this->log === null) {
            error_log("Innesto Roots: $event: $message " . json_encode($context));

            return;
        }
        try {
            $this->log->write($level, $this->fence?->current() ?? 'roots', $event, $message, $context);
        } catch (Throwable $error) {
            error_log("Innesto Roots: $event: $message; the Roots log failed: " . $error->getMessage());
        }
    }
}
