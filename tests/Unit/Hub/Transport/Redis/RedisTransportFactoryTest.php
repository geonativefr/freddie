<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Transport\Redis;

use Freddie\Hub\Transport\Redis\RedisTransport;
use Freddie\Hub\Transport\Redis\RedisTransportFactory;
use Freddie\Hub\Transport\Redis\ResumableProtocolFactory;
use Freddie\Hub\Transport\Redis\ResumableResponseParser;
use Clue\React\Redis\Client;
use Clue\React\Redis\Factory;
use ReflectionProperty;

it('supports Redis DSNs', function (string $dsn, bool $expected) {
    $factory = new RedisTransportFactory();
    expect($factory->supports($dsn))->toBe($expected);
})->with(function () {
    yield ['redis://localhost?pingInterval=0.0', true];
    yield ['rediss://some.secure.place.com', true];
    yield ['notredis://shrug', false];
});

it('instantiates a Redis transport', function (string $dsn, RedisTransport $expected) {
    $factory = new RedisTransportFactory();
    expect($factory->create($dsn))->toEqual($expected);
})->with(function () {
    $redisFactory = new Factory();
    yield ['redis://localhost?foo=bar&pingInterval=0.0', new RedisTransport(
        $redisFactory->createLazyClient('redis://localhost?foo=bar&pingInterval=0.0'),
        $redisFactory->createLazyClient('redis://localhost?foo=bar&pingInterval=0.0'),
        options: ['pingInterval' => 0.0],
    )];
    yield ['redis://localhost?size=1000&trimInterval=2.5&pingInterval=0.0', new RedisTransport(
        $redisFactory->createLazyClient('redis://localhost?size=1000&trimInterval=2.5&pingInterval=0.0'),
        $redisFactory->createLazyClient('redis://localhost?size=1000&trimInterval=2.5&pingInterval=0.0'),
        options: ['size' => 1000, 'trimInterval' => 2.5, 'pingInterval' => 0.0],
    )];
})->skip('Pest object comparison does not work as expected');

it('instantiates 2 different clients', function () {
    $factory = new RedisTransportFactory();
    /** @var RedisTransport $transport */
    $transport = $factory->create('redis://localhost?size=1000&pingInterval=0.0');
    expect($transport->redis)->toBeInstanceOf(Client::class);
    expect($transport->subscriber)->toBeInstanceOf(Client::class);
    expect($transport->redis)->not()->toBe($transport->subscriber);
});

it('reads the reconciliation backlog on a 3rd connection, not the pinged one', function () {
    $factory = new RedisTransportFactory();
    /** @var RedisTransport $transport */
    $transport = $factory->create('redis://localhost?size=1000&pingInterval=0.0');
    expect($transport->reader)->toBeInstanceOf(Client::class);
    expect($transport->reader)->not()->toBe($transport->redis);
    expect($transport->reader)->not()->toBe($transport->subscriber);
});

it('creates resumable response parsers', function () {
    expect((new ResumableProtocolFactory())->createResponseParser())->toBeInstanceOf(ResumableResponseParser::class);
});

it('talks to Redis with the resumable protocol by default', function () {
    $factory = (new ReflectionProperty(RedisTransportFactory::class, 'factory'))->getValue(new RedisTransportFactory());
    $protocol = (new ReflectionProperty(Factory::class, 'protocol'))->getValue($factory);

    expect($protocol)->toBeInstanceOf(ResumableProtocolFactory::class);
});

it('keeps using the Redis factory it is given', function () {
    $given = new Factory();
    $property = new ReflectionProperty(RedisTransportFactory::class, 'factory');

    $transportFactory = new RedisTransportFactory($given);

    expect($property->getValue($transportFactory))->toBe($given);
});
