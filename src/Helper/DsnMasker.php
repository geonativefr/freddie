<?php

declare(strict_types=1);

namespace Freddie\Helper;

use function preg_replace;

final class DsnMasker
{
    public const string MASK = '***';

    private const string USER_INFO_PATTERN = '~^([^:/?#]+://[^:@/?#]*:)[^@/?#]*(@)~';

    private const string QUERY_PATTERN = '~([?&]password=)[^&#]*~';

    public static function mask(string $dsn): string
    {
        $masked = preg_replace(self::USER_INFO_PATTERN, '$1' . self::MASK . '$2', $dsn);

        return (string) preg_replace(self::QUERY_PATTERN, '$1' . self::MASK, (string) $masked);
    }
}
