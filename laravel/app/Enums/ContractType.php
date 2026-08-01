<?php

namespace App\Enums;

enum ContractType: string
{
    case OpenEnded = 'OpenEnded';
    case FixedTerm = 'FixedTerm';
    case Unknown = 'Unknown';

    public static function fromSource(mixed $value): self
    {
        if (! is_string($value)) {
            return self::Unknown;
        }

        $normalized = trim($value);

        foreach (self::cases() as $case) {
            if (strcasecmp($normalized, $case->value) === 0) {
                return $case;
            }
        }

        return strcasecmp($normalized, 'Fixed') === 0
            ? self::FixedTerm
            : self::Unknown;
    }

    /** @return list<string> */
    public static function publishableValues(): array
    {
        return array_values(array_map(
            fn (self $case): string => $case->value,
            array_filter(self::cases(), fn (self $case): bool => $case !== self::Unknown),
        ));
    }
}
