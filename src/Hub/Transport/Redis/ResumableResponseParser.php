<?php

declare(strict_types=1);

namespace Freddie\Hub\Transport\Redis;

use Clue\Redis\Protocol\Model\BulkReply;
use Clue\Redis\Protocol\Model\ErrorReply;
use Clue\Redis\Protocol\Model\IntegerReply;
use Clue\Redis\Protocol\Model\ModelInterface;
use Clue\Redis\Protocol\Model\MultiBulkReply;
use Clue\Redis\Protocol\Model\StatusReply;
use Clue\Redis\Protocol\Parser\ParserException;
use Clue\Redis\Protocol\Parser\ParserInterface;

use function array_key_last;
use function array_pop;
use function count;
use function strlen;
use function strpos;
use function substr;

/**
 * Same output as Clue\Redis\Protocol\Parser\ResponseParser, but an incomplete reply is resumed
 * where it stopped instead of being parsed again from its first byte on every chunk.
 */
final class ResumableResponseParser implements ParserInterface
{
    private string $buffer = '';

    /** @var list<array{int, list<ModelInterface>}> Multi-bulk replies being filled: expected count, items so far */
    private array $open = [];

    /** Size of the bulk whose header was read and whose payload is not complete yet */
    private ?int $pendingBulk = null;

    /** A bulk size below -1 can never be satisfied: the upstream parser waits forever, so do we */
    private bool $stalled = false;

    /**
     * @param string $dataChunk
     * @return list<ModelInterface>
     */
    public function pushIncoming($dataChunk): array
    {
        if ($this->stalled) {
            return [];
        }

        $this->buffer .= $dataChunk;
        $offset = 0;
        $length = strlen($this->buffer);
        $replies = [];

        while (true) {
            if (null !== $this->pendingBulk) {
                if ($length - $offset < $this->pendingBulk + 2) {
                    break;
                }
                $reply = new BulkReply(substr($this->buffer, $offset, $this->pendingBulk));
                $offset += $this->pendingBulk + 2;
                $this->pendingBulk = null;
            } else {
                $end = strpos($this->buffer, "\r\n", $offset);
                if (false === $end) {
                    break;
                }
                $line = substr($this->buffer, $offset, $end - $offset);
                $offset = $end + 2;
                $reply = $this->readHeader($line);
                if (null === $reply) {
                    if ($this->stalled) {
                        $this->buffer = '';

                        return $replies;
                    }
                    continue;
                }
            }

            while ($this->open !== [] && null !== $reply) {
                $last = array_key_last($this->open);
                $this->open[$last][1][] = $reply;
                if (count($this->open[$last][1]) < $this->open[$last][0]) {
                    $reply = null;
                    break;
                }
                $reply = new MultiBulkReply(array_pop($this->open)[1]);
            }

            if (null !== $reply) {
                $replies[] = $reply;
            }
        }

        $this->buffer = substr($this->buffer, $offset);

        return $replies;
    }

    /**
     * Returns the reply the line completes, or null when more bytes are needed
     * (a bulk payload, the items of a multi-bulk) or when the parser is stalled.
     *
     * @throws ParserException
     */
    private function readHeader(string $line): ?ModelInterface
    {
        $value = substr($line, 1);

        switch (substr($line, 0, 1)) {
            case '-':
                return new ErrorReply($value);
            case '+':
                return new StatusReply($value);
            case ':':
                return new IntegerReply($value);
            case '$':
                $size = (int) $value;
                if (-1 === $size) {
                    return new BulkReply(null);
                }
                if ($size < 0) {
                    $this->stalled = true;

                    return null;
                }
                $this->pendingBulk = $size;

                return null;
            case '*':
                $count = (int) $value;
                if (-1 === $count) {
                    return new MultiBulkReply(null);
                }
                if ($count < 1) {
                    return new MultiBulkReply([]);
                }
                $this->open[] = [$count, []];

                return null;
            default:
                throw new ParserException('Invalid message can not be parsed: "' . $line . '"');
        }
    }
}
