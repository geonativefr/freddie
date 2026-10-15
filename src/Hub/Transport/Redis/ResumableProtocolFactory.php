<?php

declare(strict_types=1);

namespace Freddie\Hub\Transport\Redis;

use Clue\Redis\Protocol\Factory;
use Clue\Redis\Protocol\Parser\ParserInterface;

final class ResumableProtocolFactory extends Factory
{
    public function createResponseParser(): ParserInterface
    {
        return new ResumableResponseParser();
    }
}
