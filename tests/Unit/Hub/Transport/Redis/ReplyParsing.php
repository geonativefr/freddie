<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Transport\Redis;

use Clue\Redis\Protocol\Model\BulkReply;
use Clue\Redis\Protocol\Model\ErrorReply;
use Clue\Redis\Protocol\Model\IntegerReply;
use Clue\Redis\Protocol\Model\ModelInterface;
use Clue\Redis\Protocol\Model\MultiBulkReply;
use Clue\Redis\Protocol\Model\StatusReply;
use Clue\Redis\Protocol\Parser\ParserException;
use Clue\Redis\Protocol\Parser\ParserInterface;
use ReflectionProperty;

final class ReplyParsing
{
    /**
     * @return mixed A comparable description of a parsed reply, nested replies included
     */
    public static function describe(ModelInterface $reply): mixed
    {
        if ($reply instanceof MultiBulkReply) {
            $data = (new ReflectionProperty(MultiBulkReply::class, 'data'))->getValue($reply);

            return [MultiBulkReply::class, null === $data ? null : array_map(self::describe(...), $data)];
        }

        return [$reply::class, $reply instanceof ErrorReply ? $reply->getMessage() : $reply->getValueNative()];
    }

    /**
     * @param list<string> $chunks
     * @return list<mixed>
     */
    public static function parse(ParserInterface $parser, array $chunks): array
    {
        $replies = [];
        try {
            foreach ($chunks as $chunk) {
                foreach ($parser->pushIncoming($chunk) as $reply) {
                    $replies[] = self::describe($reply);
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
    public static function chunkings(string $wire): array
    {
        $chunkings = [[$wire], str_split($wire)];
        for ($i = 1, $length = strlen($wire); $i < $length; $i++) {
            $chunkings[] = [substr($wire, 0, $i), substr($wire, $i)];
        }

        return $chunkings;
    }

    public static function validWires(): array
    {
        return [
            'status' => ['+OK' . "\r\n", [[StatusReply::class, 'OK']]],
            'error' => ['-ERR boom' . "\r\n", [[ErrorReply::class, 'ERR boom']]],
            'integer' => [":42\r\n", [[IntegerReply::class, 42]]],
            'negative integer' => [":-7\r\n", [[IntegerReply::class, -7]]],
            'bulk' => ["\$5\r\nhello\r\n", [[BulkReply::class, 'hello']]],
            'null bulk' => ["\$-1\r\n", [[BulkReply::class, null]]],
            'empty bulk' => ["\$0\r\n\r\n", [[BulkReply::class, '']]],
            'binary-safe bulk' => ["\$8\r\na\r\nb\r\n\0c\r\n", [[BulkReply::class, "a\r\nb\r\n\0c"]]],
            'multi-bulk' => ["*2\r\n\$3\r\nfoo\r\n:1\r\n", [[MultiBulkReply::class, [
                [BulkReply::class, 'foo'],
                [IntegerReply::class, 1],
            ]]]],
            'null multi-bulk' => ["*-1\r\n", [[MultiBulkReply::class, null]]],
            'empty multi-bulk' => ["*0\r\n", [[MultiBulkReply::class, []]]],
            'nested multi-bulk' => ["*3\r\n*2\r\n+a\r\n:2\r\n*0\r\n*1\r\n\$-1\r\n", [[MultiBulkReply::class, [
                [MultiBulkReply::class, [
                    [StatusReply::class, 'a'],
                    [IntegerReply::class, 2],
                ]],
                [MultiBulkReply::class, []],
                [MultiBulkReply::class, [[BulkReply::class, null]]],
            ]]]],
            'multi-bulk of binary-safe bulks' => ["*2\r\n\$4\r\n\r\n\r\n\r\n\$2\r\n\r\n\r\n", [[MultiBulkReply::class, [
                [BulkReply::class, "\r\n\r\n"],
                [BulkReply::class, "\r\n"],
            ]]]],
        ];
    }

    public static function oddWires(): array
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
}
