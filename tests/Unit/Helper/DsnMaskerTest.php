<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Helper;

use Freddie\Helper\DsnMasker;

it('masks the password of a DSN', function (string $dsn, string $expected) {
    expect(DsnMasker::mask($dsn))->toBe($expected);
})->with([
    'redis, password without user' => ['redis://:secret@127.0.0.1:6379', 'redis://:***@127.0.0.1:6379'],
    'redis, password with user' => ['redis://john:secret@127.0.0.1:6379', 'redis://john:***@127.0.0.1:6379'],
    'rediss, password without user' => [
        'rediss://:secret@redis.example.org:6380',
        'rediss://:***@redis.example.org:6380',
    ],
    'rediss, password with user' => [
        'rediss://john:secret@redis.example.org:6380',
        'rediss://john:***@redis.example.org:6380',
    ],
    'user-info password with path and query' => [
        'redis://john:secret@127.0.0.1:6379/2?key=foo&channel=bar',
        'redis://john:***@127.0.0.1:6379/2?key=foo&channel=bar',
    ],
    'password query parameter, first' => [
        'redis://127.0.0.1:6379?password=secret&key=foo&channel=bar',
        'redis://127.0.0.1:6379?password=***&key=foo&channel=bar',
    ],
    'password query parameter, middle' => [
        'redis://127.0.0.1:6379?key=foo&password=secret&channel=bar',
        'redis://127.0.0.1:6379?key=foo&password=***&channel=bar',
    ],
    'password query parameter, last' => [
        'redis://127.0.0.1:6379?key=foo&channel=bar&password=secret',
        'redis://127.0.0.1:6379?key=foo&channel=bar&password=***',
    ],
    'password query parameter on rediss' => [
        'rediss://127.0.0.1:6380?password=secret',
        'rediss://127.0.0.1:6380?password=***',
    ],
    'both password forms' => [
        'redis://:secret@127.0.0.1:6379?password=other&key=foo',
        'redis://:***@127.0.0.1:6379?password=***&key=foo',
    ],
    'no password, redis' => ['redis://127.0.0.1:6379', 'redis://127.0.0.1:6379'],
    'no password, redis with query' => [
        'redis://127.0.0.1:6379?key=foo&channel=bar',
        'redis://127.0.0.1:6379?key=foo&channel=bar',
    ],
    'no password, php' => ['php://default', 'php://default'],
    'no password, php with query' => ['php://localhost?size=10000', 'php://localhost?size=10000'],
    'password containing a colon' => ['redis://u:pa:ss@h', 'redis://u:***@h'],
    'at sign in the query without user-info' => ['redis://h:6379?key=a@b', 'redis://h:6379?key=a@b'],
    'password query parameter repeated' => [
        'redis://127.0.0.1:6379?password=a&password=b',
        'redis://127.0.0.1:6379?password=***&password=***',
    ],
    'password query parameter before a fragment' => ['redis://h?password=x#frag', 'redis://h?password=***#frag'],
    'password with an IPv6 host' => ['redis://:pw@[::1]:6379', 'redis://:***@[::1]:6379'],
    'user without password' => ['redis://john@host', 'redis://john@host'],
    'empty password query parameter' => ['redis://h?password=', 'redis://h?password=***'],
    'unix socket' => ['redis+unix://:pw@/var/run/redis.sock', 'redis+unix://:***@/var/run/redis.sock'],
]);
