<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Transport\Redis;

use Clue\React\Redis\Factory;
use Clue\React\Redis\LazyClient;
use Freddie\DependencyInjection\FreddieExtension;
use Freddie\Hub\Transport\Redis\RedisTransport;
use Freddie\Hub\Transport\Redis\ResumableProtocolFactory;
use Freddie\Hub\Transport\TransportInterface;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\ContainerBuilder;

it('gives every Redis client of the container the resumable protocol', function () {
    $container = new ContainerBuilder();
    $container->setParameter('kernel.project_dir', dirname(__DIR__, 5));
    (new FreddieExtension())->load([], $container);
    $container->setParameter('transport_dsn', 'redis://localhost?pingInterval=0.0');
    $container->getDefinition(TransportInterface::class)->setPublic(true);
    $container->compile();

    /** @var RedisTransport $transport */
    $transport = $container->get(TransportInterface::class);

    expect($transport)->toBeInstanceOf(RedisTransport::class);
    foreach ([$transport->redis, $transport->subscriber, $transport->reader] as $client) {
        expect($client)->toBeInstanceOf(LazyClient::class);
        $factory = (new ReflectionProperty(LazyClient::class, 'factory'))->getValue($client);
        $protocol = (new ReflectionProperty(Factory::class, 'protocol'))->getValue($factory);
        expect($protocol)->toBeInstanceOf(ResumableProtocolFactory::class);
    }
});
