<?php

namespace App\Services\ContractCard\DTO;

/**
 * One footer entry. Two visual classes only, and the class is decided here rather than in
 * Blade so both card templates cannot drift apart again.
 *
 * - `warning`: a filled coral pill. Something that qualifies or limits the price.
 * - `fact`: a quiet inline tag. A promotion or the energy source.
 *
 * Warnings render before facts and are capped at two, so a card can never turn into a wall
 * of caveats. See app/Services/ContractCard/AGENTS.md for the priority order.
 */
readonly class CardFooterItem
{
    public const WARNING = 'warning';

    public const FACT = 'fact';

    /**
     * @param string $kind self::WARNING | self::FACT
     * @param string $icon Icon key: warn | tag | leaf.
     */
    public function __construct(
        public string $kind,
        public string $text,
        public string $icon,
    ) {
    }

    public static function warning(string $text, string $icon = 'warn'): self
    {
        return new self(self::WARNING, $text, $icon);
    }

    public static function fact(string $text, string $icon): self
    {
        return new self(self::FACT, $text, $icon);
    }

    public function isWarning(): bool
    {
        return $this->kind === self::WARNING;
    }
}
