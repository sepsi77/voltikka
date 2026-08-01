<?php

namespace App\Enums;

enum TargetGroup: string
{
    case Household = 'Household';
    case Company = 'Company';
    case Both = 'Both';
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

        return strcasecmp($normalized, 'Consumer') === 0
            ? self::Household
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
