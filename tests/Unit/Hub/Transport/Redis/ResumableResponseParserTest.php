<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Transport\Redis;

use Clue\Redis\Protocol\Model\ErrorReply;
use Clue\Redis\Protocol\Model\ModelInterface;
use Clue\Redis\Protocol\Model\MultiBulkReply;
use Clue\Redis\Protocol\Parser\ParserException;
use Clue\Redis\Protocol\Parser\ParserInterface;
use Clue\Redis\Protocol\Parser\ResponseParser;
use Freddie\Hub\Transport\Redis\ResumableResponseParser;
use ReflectionProperty;

/**
 * @return mixed A comparable description of a parsed reply, nested replies included
 */
function describe_reply(ModelInterface $reply): mixed
{
    if ($reply instanceof MultiBulkReply) {
        $data = (new ReflectionProperty(MultiBulkReply::class, 'data'))->getValue($reply);

        return [MultiBulkReply::class, null === $data ? null : array_map(describe_reply(...), $data)];
    }

    return [$reply::class, $reply instanceof ErrorReply ? $reply->getMessage() : $reply->getValueNative()];
}

/**
 * @param list<string> $chunks
 * @return list<mixed>
 */
function parse_chunks(ParserInterface $parser, array $chunks): array
{
    $replies = [];
    try {
        foreach ($chunks as $chunk) {
            foreach ($parser->pushIncoming($chunk) as $reply) {
                $replies[] = describe_reply($reply);
            }
        }
    } catch (ParserException $exception) {
        $replies[] = [ParserException::class, $exception->getMessage()];
    }

    return $replies;
}

/**
 * @return list<list<string>>
 */
function chunkings(string $wire): array
{
    $chunkings = [[$wire], str_split($wire)];
    for ($i = 1, $length = strlen($wire); $i < $length; $i++) {
        $chunkings[] = [substr($wire, 0, $i), substr($wire, $i)];
    }

    return $chunkings;
}

function valid_wires(): array
{
    return [
        'status' => ['+OK' . "\r\n", [['Clue\Redis\Protocol\Model\StatusReply', 'OK']]],
        'error' => ['-ERR boom' . "\r\n", [[ErrorReply::class, 'ERR boom']]],
        'integer' => [":42\r\n", [['Clue\Redis\Protocol\Model\IntegerReply', 42]]],
        'negative integer' => [":-7\r\n", [['Clue\Redis\Protocol\Model\IntegerReply', -7]]],
        'bulk' => ["\$5\r\nhello\r\n", [['Clue\Redis\Protocol\Model\BulkReply', 'hello']]],
        'null bulk' => ["\$-1\r\n", [['Clue\Redis\Protocol\Model\BulkReply', null]]],
        'empty bulk' => ["\$0\r\n\r\n", [['Clue\Redis\Protocol\Model\BulkReply', '']]],
        'binary-safe bulk' => ["\$8\r\na\r\nb\r\n\0c\r\n", [['Clue\Redis\Protocol\Model\BulkReply', "a\r\nb\r\n\0c"]]],
        'multi-bulk' => ["*2\r\n\$3\r\nfoo\r\n:1\r\n", [[MultiBulkReply::class, [
            ['Clue\Redis\Protocol\Model\BulkReply', 'foo'],
            ['Clue\Redis\Protocol\Model\IntegerReply', 1],
        ]]]],
        'null multi-bulk' => ["*-1\r\n", [[MultiBulkReply::class, null]]],
        'empty multi-bulk' => ["*0\r\n", [[MultiBulkReply::class, []]]],
        'nested multi-bulk' => ["*3\r\n*2\r\n+a\r\n:2\r\n*0\r\n*1\r\n\$-1\r\n", [[MultiBulkReply::class, [
            [MultiBulkReply::class, [
                ['Clue\Redis\Protocol\Model\StatusReply', 'a'],
                ['Clue\Redis\Protocol\Model\IntegerReply', 2],
            ]],
            [MultiBulkReply::class, []],
            [MultiBulkReply::class, [['Clue\Redis\Protocol\Model\BulkReply', null]]],
        ]]]],
        'multi-bulk of binary-safe bulks' => ["*2\r\n\$4\r\n\r\n\r\n\r\n\$2\r\n\r\n\r\n", [[MultiBulkReply::class, [
            ['Clue\Redis\Protocol\Model\BulkReply', "\r\n\r\n"],
            ['Clue\Redis\Protocol\Model\BulkReply', "\r\n"],
        ]]]],
    ];
}

function odd_wires(): array
{
    return [
        'garbage length' => "\$abc\r\n\r\n",
        'bulk terminator is not checked' => "\$3\r\nabcXY+OK\r\n",
        'multi-bulk length below -1' => "*-2\r\n+OK\r\n",
        'bulk length below -1 never completes' => "\$-2\r\n+OK\r\n:1\r\n",
        'empty line' => "\r\n",
        'unknown type' => "?what\r\n",
        'unknown type after a valid reply' => "+OK\r\n?what\r\n",
        'unknown type in a multi-bulk' => "*2\r\n:1\r\n?what\r\n",
        'unknown type behind an unknown length' => "*1\r\n*2\r\n+a\r\n!b\r\n",
        'reply before an unfinished one' => "+OK\r\n*2\r\n:1\r\n",
    ];
}

it('parses a {name} reply identically whatever the chunking', function (string $wire, array $expected) {
    foreach (chunkings($wire) as $chunks) {
        expect(parse_chunks(new ResumableResponseParser(), $chunks))->toBe($expected);
    }
})->with(valid_wires());

it('parses several replies pushed together or apart', function () {
    $wire = '';
    $expected = [];
    foreach (valid_wires() as [$replyWire, $replies]) {
        $wire .= $replyWire;
        array_push($expected, ...$replies);
    }

    expect(parse_chunks(new ResumableResponseParser(), [$wire]))->toBe($expected);
    expect(parse_chunks(new ResumableResponseParser(), str_split($wire)))->toBe($expected);
    expect(parse_chunks(new ResumableResponseParser(), str_split($wire, 7)))->toBe($expected);
    foreach (range(1, strlen($wire) - 1) as $boundary) {
        $chunks = [substr($wire, 0, $boundary), substr($wire, $boundary)];
        expect(parse_chunks(new ResumableResponseParser(), $chunks))->toBe($expected);
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
    foreach (chunkings($wire) as $chunks) {
        expect(parse_chunks(new ResumableResponseParser(), $chunks))->toBe(parse_chunks(new ResponseParser(), $chunks));
    }
})->with(array_merge(
    array_map(fn (array $case) => $case[0], valid_wires()),
    odd_wires(),
    ['everything' => implode('', array_map(fn (array $case) => $case[0], valid_wires()))],
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
