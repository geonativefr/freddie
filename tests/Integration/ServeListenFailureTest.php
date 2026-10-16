<?php

declare(strict_types=1);

namespace Freddie\Tests\Integration;

use Freddie\Tests\Integration\Hub\Transport\Redis\RedisHarness;

use function explode;
use function microtime;
use function sprintf;
use function uniqid;
use function usleep;

it('exits with an error when the port is already held by another hub', function () {
    if (null === RedisHarness::dsn()) {
        $this->markTestSkipped('FREDDIE_TEST_REDIS_DSN is not set.');
    }
    $exitBound = 5.0;
    $env = [];
    foreach (explode(',', $_ENV['SYMFONY_DOTENV_VARS'] ?? '') as $key) {
        if (isset($_ENV[$key])) {
            $env[$key] = $_ENV[$key];
        }
    }
    $address = '127.0.0.1:' . ServeHarness::freePort();
    $id = uniqid('freddie_listen_');
    $env['X_LISTEN'] = $address;
    $env['TRANSPORT_DSN'] = sprintf('%s?key=%s&channel=%s', RedisHarness::dsn(), $id, $id);
    $first = $second = null;

    try {
        $first = ServeHarness::serve($env);
        for ($waited = 0.0; !ServeHarness::listens($address) && $first->isRunning() && $waited < 10.0; $waited += 0.1) {
            usleep(100000);
        }
        expect($first->isRunning())->toBeTrue($first->getErrorOutput());
        expect(ServeHarness::listens($address))->toBeTrue();

        $second = ServeHarness::serve($env);
        $startedAt = microtime(true);
        while ($second->isRunning() && microtime(true) - $startedAt < $exitBound) {
            usleep(50000);
        }

        expect($second->isRunning())->toBeFalse(sprintf('Still running after %s seconds.', $exitBound));
        expect($second->getExitCode())->not->toBe(0);
        expect($second->getErrorOutput() . $second->getOutput())->toContain('Address already in use');
        expect($first->isRunning())->toBeTrue();
        expect(ServeHarness::listens($address))->toBeTrue();
    } finally {
        $first?->stop(1, SIGKILL);
        $second?->stop(1, SIGKILL);
    }
});
