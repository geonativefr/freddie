<?php

declare(strict_types=1);

namespace Freddie\Helper;

use function preg_replace;

final class DsnMasker
{
    public const string MASK = '***';

    private const array PASSWORD_PATTERNS = [
        '/(:)[^:\/]*(@)/',
        '/([?&]password=).*?($|&)/',
    ];

    public static function mask(string $dsn): string
    {
        return (string) preg_replace(self::PASSWORD_PATTERNS, '$1' . self::MASK . '$2', $dsn);
    }
}
