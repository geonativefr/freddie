<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Command;

use Freddie\Command\ServeCommand;
use Freddie\Hub\Hub;
use React\EventLoop\Loop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

it('works', function () {
    Loop::futureTick(fn () => Loop::stop());
    $params = new ParameterBag(['transport_dsn' => 'php://localhost?size=10000']);
    $command = new ServeCommand(new Hub(), $params);
    $commandTester = new CommandTester($command);
    $commandTester->execute([]);
    expect($commandTester->getStatusCode())->toBe(Command::SUCCESS);
});

it('masks the transport password in its output', function () {
    Loop::futureTick(fn () => Loop::stop());
    $params = new ParameterBag(['transport_dsn' => 'redis://john:secret@127.0.0.1:6379?key=foo&password=other']);
    $command = new ServeCommand(new Hub(), $params);
    $commandTester = new CommandTester($command);
    $commandTester->execute([]);
    $display = $commandTester->getDisplay();
    expect($display)->toContain('Using transport: redis://john:***@127.0.0.1:6379?key=foo&password=***.')
        ->and($display)->not->toContain('secret')
        ->and($display)->not->toContain('other');
});
