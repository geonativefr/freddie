<?php

declare(strict_types=1);

namespace Freddie\Tests\Integration;

use Symfony\Component\Process\Process;

use function dirname;
use function fclose;
use function preg_match;
use function restore_error_handler;
use function set_error_handler;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_server;

/**
 * Starts real `bin/freddie serve` processes and probes the port they listen on.
 */
final class ServeHarness
{
    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        preg_match('/:(\d+)$/', (string) stream_socket_get_name($socket, false), $matches);
        fclose($socket);

        return (int) $matches[1];
    }

    /**
     * @param array<string, string> $env
     */
    public static function serve(array $env): Process
    {
        $process = new Process(['bin/freddie', 'serve'], dirname(__DIR__, 2), $env);
        $process->start();

        return $process;
    }

    public static function listens(string $address): bool
    {
        set_error_handler(static fn () => true);
        try {
            $socket = stream_socket_client('tcp://' . $address, $errno, $error, 0.2);
        } finally {
            restore_error_handler();
        }
        if (false === $socket) {
            return false;
        }
        fclose($socket);

        return true;
    }
}
