<?php

namespace App\Services\SpotSocial;

final readonly class SpotSocialReadinessResult
{
    /**
     * @param  list<string>  $incompleteDates
     */
    public function __construct(
        public bool $ready,
        public array $incompleteDates,
    ) {}
}
