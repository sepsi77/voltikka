<?php

namespace App\Services\PriceForecasting;

class ForecastOutlook
{
    public const UNCERTAINTY = 'Suuntaa antava arvio, ei varma hintakehitys.';

    public static function category(?string $direction): ?string
    {
        return match ($direction) {
            'rising' => 'rising',
            'falling' => 'falling',
            'flat', 'slightly_rising', 'slightly_falling' => 'flat',
            default => null,
        };
    }

    public static function presentation(?string $direction): array
    {
        $category = self::category($direction);
        [$tone, $label, $headline] = match ($category) {
            'rising' => ['up', 'Nousua odotettavissa', 'Hintojen odotetaan nousevan'],
            'falling' => ['down', 'Laskua odotettavissa', 'Hintojen odotetaan laskevan'],
            'flat' => ['neutral', 'Suunnilleen ennallaan', 'Hintatason odotetaan pysyvän suunnilleen ennallaan'],
            default => ['neutral', 'Ennuste ei saatavilla', 'Hintanäkymää ei ole saatavilla'],
        };

        return ['key' => $category ?? 'unknown', 'tone' => $tone, 'label' => $label, 'headline' => $headline];
    }

    public static function summary(array $categories): string
    {
        $known = array_filter($categories, fn ($category) => in_array($category, ['rising', 'falling', 'flat'], true));
        if (count($known) === 0) {
            return 'none';
        }
        if (count($known) !== 3 || count($categories) !== 3) {
            return 'incomplete';
        }
        if (count(array_unique($known)) !== 1) {
            return 'mixed';
        }

        return match (reset($known)) {
            'rising' => 'up', 'falling' => 'down', 'flat' => 'stable',
        };
    }

    public static function outcome(?string $forecast, ?string $actual): ?string
    {
        if (! in_array($forecast, ['rising', 'falling', 'flat'], true)
            || ! in_array($actual, ['rising', 'falling', 'flat'], true)) {
            return null;
        }

        return match (true) {
            $forecast === $actual => 'correct',
            $forecast === 'flat' => 'missed_move',
            $actual === 'flat' => 'false_move',
            default => 'wrong_way',
        };
    }
}
