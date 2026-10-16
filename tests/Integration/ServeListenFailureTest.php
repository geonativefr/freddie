<?php

declare(strict_types=1);

namespace Freddie\Tests\Integration;

use Freddie\Tests\Integration\Hub\Transport\Redis\RedisHarness;
use Symfony\Component\Process\Process;

use function dirname;
use function explode;
use function fclose;
use function microtime;
use function preg_match;
use function sprintf;
use function stream_socket_get_name;
use function stream_socket_server;
use function uniqid;
use function usleep;

const EXIT_BOUND = 5.0;

function free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    preg_match('/:(\d+)$/', (string) stream_socket_get_name($socket, false), $matches);
    fclose($socket);

    return (int) $matches[1];
}

/**
 * @param array<string, string> $env
 */
function serve(array $env): Process
{
    $process = new Process(['bin/freddie', 'serve'], dirname(__DIR__, 2), $env);
    $process->start();

    return $process;
}

function listens(string $address): bool
{
    $socket = @stream_socket_client('tcp://' . $address, $errno, $error, 0.2);
    if (false === $socket) {
        return false;
    }
    fclose($socket);

    return true;
}

it('exits with an error when the port is already held by another hub', function () {
    if (null === RedisHarness::dsn()) {
        $this->markTestSkipped('FREDDIE_TEST_REDIS_DSN is not set.');
    }
    $env = [];
    foreach (explode(',', $_ENV['SYMFONY_DOTENV_VARS'] ?? '') as $key) {
        if (isset($_ENV[$key])) {
            $env[$key] = $_ENV[$key];
        }
    }
    $address = '127.0.0.1:' . free_port();
    $id = uniqid('freddie_listen_');
    $env['X_LISTEN'] = $address;
    $env['TRANSPORT_DSN'] = sprintf('%s?key=%s&channel=%s', RedisHarness::dsn(), $id, $id);
    $first = $second = null;

    try {
        $first = serve($env);
        for ($waited = 0.0; !listens($address) && $first->isRunning() && $waited < 10.0; $waited += 0.1) {
            usleep(100000);
        }
        expect($first->isRunning())->toBeTrue($first->getErrorOutput());
        expect(listens($address))->toBeTrue();

        $second = serve($env);
        $startedAt = microtime(true);
        while ($second->isRunning() && microtime(true) - $startedAt < EXIT_BOUND) {
            usleep(50000);
        }

        expect($second->isRunning())->toBeFalse('The second hub is still running after ' . EXIT_BOUND . ' seconds.');
        expect($second->getExitCode())->not->toBe(0);
        expect($second->getErrorOutput() . $second->getOutput())->toContain('Address already in use');
        expect($first->isRunning())->toBeTrue();
        expect(listens($address))->toBeTrue();
    } finally {
        $first?->stop(1, SIGKILL);
        $second?->stop(1, SIGKILL);
    }
});
