<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Transport\Redis;

use Clue\Redis\Protocol\Parser\ParserException;
use Clue\Redis\Protocol\Parser\ResponseParser;
use Freddie\Hub\Transport\Redis\ResumableResponseParser;

it('parses a {name} reply identically whatever the chunking', function (string $wire, array $expected) {
    foreach (ReplyParsing::chunkings($wire) as $chunks) {
        expect(ReplyParsing::parse(new ResumableResponseParser(), $chunks))->toBe($expected);
    }
})->with(ReplyParsing::validWires());

it('parses several replies pushed together or apart', function () {
    $wire = '';
    $expected = [];
    foreach (ReplyParsing::validWires() as [$replyWire, $replies]) {
        $wire .= $replyWire;
        array_push($expected, ...$replies);
    }

    expect(ReplyParsing::parse(new ResumableResponseParser(), [$wire]))->toBe($expected);
    expect(ReplyParsing::parse(new ResumableResponseParser(), str_split($wire)))->toBe($expected);
    expect(ReplyParsing::parse(new ResumableResponseParser(), str_split($wire, 7)))->toBe($expected);
    foreach (range(1, strlen($wire) - 1) as $boundary) {
        $chunks = [substr($wire, 0, $boundary), substr($wire, $boundary)];
        expect(ReplyParsing::parse(new ResumableResponseParser(), $chunks))->toBe($expected);
    }
});

it('returns nothing while a reply is incomplete and the reply once it is', function () {
    $parser = new ResumableResponseParser();

    expect($parser->pushIncoming("*2\r\n\$3\r\nfoo\r\n"))->toBe([]);
    expect($parser->pushIncoming("\$3\r\nba"))->toBe([]);
    $replies = $parser->pushIncoming("r\r\n+next");

    expect($replies)->toHaveCount(1);
    expect($replies[0]->getValueNative())->toBe(['foo', 'bar']);
    expect($parser->pushIncoming("\r\n")[0]->getValueNative())->toBe('next');
});

it('parses like the upstream parser', function (string $wire) {
    foreach (ReplyParsing::chunkings($wire) as $chunks) {
        $expected = ReplyParsing::parse(new ResponseParser(), $chunks);
        expect(ReplyParsing::parse(new ResumableResponseParser(), $chunks))->toBe($expected);
    }
})->with(array_merge(
    array_map(fn (array $case) => $case[0], ReplyParsing::validWires()),
    ReplyParsing::oddWires(),
    ['everything' => implode('', array_map(fn (array $case) => $case[0], ReplyParsing::validWires()))],
));

it('throws the upstream exception on invalid input', function () {
    $parser = new ResumableResponseParser();

    expect(fn () => $parser->pushIncoming("+OK\r\n?what\r\n"))
        ->toThrow(ParserException::class, 'Invalid message can not be parsed: "?what"');
});

it('does not re-read a large reply for each chunk', function () {
    $wire = '*3000' . "\r\n" . str_repeat('$1000' . "\r\n" . str_repeat('x', 1000) . "\r\n", 3000);
    $chunks = str_split($wire, 512);

    $parser = new ResumableResponseParser();
    $start = hrtime(true);
    $count = 0;
    foreach ($chunks as $chunk) {
        foreach ($parser->pushIncoming($chunk) as $reply) {
            $count += count($reply->getValueNative());
        }
    }
    $elapsed = (hrtime(true) - $start) / 1e9;

    expect($count)->toBe(3000);
    expect($elapsed)->toBeLessThan(1.0);
});
