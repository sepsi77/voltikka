<?php

namespace App\Services\SpotSocial;

use App\Models\SpotSocialPublication;

final readonly class SpotSocialClaimResult
{
    public function __construct(
        public bool $claimed,
        public string $reason,
        public ?SpotSocialPublication $publication,
    ) {}
}
