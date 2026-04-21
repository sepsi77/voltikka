<?php

namespace App\Services;

use App\Models\ElectricityContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ContractReplacementMatcher
{
    /**
     * Words that mostly describe promotions or marketing copy, not the base product.
     *
     * @var array<int, string>
     */
    protected array $noiseTokens = [
        'ensimmaiset', 'ensimmainen', 'ilman', 'kkmaksua', 'kuukausimaksu', 'kuukausimaksua',
        'perusmaksu', 'perusmaksut', 'marginaali', 'marginaalia', 'alennus', 'etu', 'tarjous',
        'suosittu', 'kotimainen', 'valinta', 'vaikuta', 'itse', 'sahkosi', 'hintaan', 'hinta',
        'joka', 'ei', 'heilu', 'tuotettu', 'sertifioima', 'sertifioitu', 'luonnonsuojeluliiton',
        'ensimmainenkuukausi', 'kiinnitysmahdollisuus', 'mahdollisuus', 'suomen', 'edullisinta',
        'edullisin', 'paastottomasti', '100', '0', '50', '2kk', '3kk', '4kk', '6kk', '9kk', '12kk',
        '18kk', '24kk', '36kk',
    ];

    /**
     * Product identity tokens that help distinguish similar-looking sibling products.
     *
     * @var array<int, string>
     */
    protected array $identityTokens = [
        'jousto', 'joustosahko', 'kiintea', 'varma', 'vakaa', 'kesto', 'perussahko', 'vuosisahko',
        'voima', 'duo', 'eko', 'ilmasto', 'ilmastoviisas', 'tuuli', 'taystuuli', 'vesi', 'taysvesi',
        'aurinko', 'aurinkosahko', 'luonnonvoima', 'porssisahko', 'optimi', 'helppo', 'aktiivinen',
        'vartti', 'toimitusvelvollinen', 'verraton', 'vankka', 'vire', 'fiksusahko', 'kvartaalisahko',
        'biokvartaalisahko', 'tuulikvartaalisahko', 'hintalukko', 'mix', 'opiskelija', 'opiskelijan',
        'yritys', 'yrityksille', 'hiilivapaa', 'fossiilivapaa', 'fossiiliton', 'co2vapaa', 'co2paastoton',
        'uusiutuva',
    ];

    /**
     * Tokens that define the product variant/flavor and should not drift silently.
     *
     * @var array<int, string>
     */
    protected array $profileTokens = [
        'aurinko', 'aurinkosahko', 'tuuli', 'taystuuli', 'vesi', 'taysvesi', 'luonnonvoima',
        'hiilivapaa', 'fossiilivapaa', 'fossiiliton', 'co2vapaa', 'co2paastoton', 'ilmasto',
        'eko', 'uusiutuva', 'yrityksille', 'yritys',
    ];

    /**
     * @var array<string, Collection<int, ElectricityContract>>
     */
    protected array $activeCandidatesByCompany = [];

    public function findBestReplacement(ElectricityContract $inactive): ?array
    {
        $candidates = $this->getCandidatesFor($inactive)
            ->map(fn (ElectricityContract $candidate) => $this->scoreCandidate($inactive, $candidate))
            ->sortByDesc('score')
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        $best = $candidates->first();
        $runnerUp = $candidates->first(function (array $candidate) use ($best) {
            return $this->candidateSignature($candidate['candidate']) !== $this->candidateSignature($best['candidate']);
        });

        $best['confidence'] = $this->classifyConfidence(
            $best['score'],
            $runnerUp['score'] ?? null,
            $best['signals']
        );

        $best['runner_up_score'] = $runnerUp['score'] ?? null;

        return $best;
    }

    public function findMatchesForInactiveContracts(): Collection
    {
        return ElectricityContract::query()
            ->with('activeContract')
            ->whereDoesntHave('activeContract')
            ->orderBy('company_name')
            ->orderBy('name')
            ->get()
            ->map(function (ElectricityContract $inactive) {
                return [
                    'inactive' => $inactive,
                    'match' => $this->findBestReplacement($inactive),
                ];
            });
    }

    protected function getCandidatesFor(ElectricityContract $inactive): Collection
    {
        $company = $inactive->company_name;

        if (! isset($this->activeCandidatesByCompany[$company])) {
            $this->activeCandidatesByCompany[$company] = ElectricityContract::query()
                ->active()
                ->where('company_name', $company)
                ->get();
        }

        return $this->activeCandidatesByCompany[$company]
            ->filter(function (ElectricityContract $candidate) use ($inactive) {
                if ($candidate->id === $inactive->id) {
                    return false;
                }

                if ($candidate->contract_type !== $inactive->contract_type) {
                    return false;
                }

                if ($candidate->metering !== $inactive->metering) {
                    return false;
                }

                if (($candidate->pricing_model ?? null) !== ($inactive->pricing_model ?? null)) {
                    return false;
                }

                if (($candidate->target_group ?? null) !== ($inactive->target_group ?? null)) {
                    return false;
                }

                if ($inactive->contract_type === 'FixedTerm'
                    && ($candidate->fixed_time_range ?? null) !== ($inactive->fixed_time_range ?? null)) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    protected function scoreCandidate(ElectricityContract $inactive, ElectricityContract $candidate): array
    {
        $inactiveName = $this->normalizeName($inactive->name);
        $candidateName = $this->normalizeName($candidate->name);

        $inactiveBase = $this->baseTokens($inactive);
        $candidateBase = $this->baseTokens($candidate);
        $inactiveIdentity = $this->identityTokenSet($inactiveName);
        $candidateIdentity = $this->identityTokenSet($candidateName);
        $inactiveProfile = $this->profileTokenSet($inactiveName);
        $candidateProfile = $this->profileTokenSet($candidateName);

        $baseJaccard = $this->jaccard($inactiveBase, $candidateBase);
        $identityJaccard = $this->jaccard($inactiveIdentity, $candidateIdentity);
        $profileJaccard = empty($inactiveProfile) && empty($candidateProfile)
            ? null
            : $this->jaccard($inactiveProfile, $candidateProfile);
        $fullSimilarity = $this->stringSimilarity($inactiveName, $candidateName);
        $compactSimilarity = $this->stringSimilarity(
            implode(' ', $inactiveBase),
            implode(' ', $candidateBase)
        );

        $score = 0;
        $signals = [];

        // Structural signals: already hard filters, but still worth some score.
        $score += 10;
        $signals[] = 'same_provider';
        $score += 5;
        $signals[] = 'same_contract_type';
        $score += 5;
        $signals[] = 'same_metering';
        $score += 5;
        $signals[] = 'same_pricing_model';
        $score += 5;
        $signals[] = 'same_target_group';

        if ($inactive->contract_type === 'FixedTerm') {
            $score += 5;
            $signals[] = 'same_fixed_time_range';
        }

        $score += (int) round($baseJaccard * 25);
        $score += (int) round($identityJaccard * 15);
        $score += (int) round(($profileJaccard ?? 0) * 15);
        $score += (int) round($fullSimilarity * 10);
        $score += (int) round($compactSimilarity * 10);

        if ($baseJaccard >= 0.99) {
            $score += 5;
            $signals[] = 'base_name_exact';
        }

        if ($identityJaccard >= 0.99 && ! empty($inactiveIdentity)) {
            $score += 5;
            $signals[] = 'identity_tokens_exact';
        }

        if ($inactiveName === $candidateName) {
            $score += 5;
            $signals[] = 'full_name_exact';
        }

        if (! empty($inactiveIdentity) && ! empty($candidateIdentity) && $identityJaccard === 0.0) {
            $score -= 10;
            $signals[] = 'identity_mismatch';
        }

        if ($profileJaccard !== null && $profileJaccard >= 0.99 && ! empty($inactiveProfile)) {
            $score += 5;
            $signals[] = 'profile_tokens_exact';
        }

        if (! empty($inactiveProfile) && ! empty($candidateProfile) && $profileJaccard === 0.0) {
            $score -= 20;
            $signals[] = 'profile_mismatch';
        }

        if ($this->looksLikePromoVariant($inactive, $candidate)) {
            $score += 5;
            $signals[] = 'promo_variant';
        }

        return [
            'candidate' => $candidate,
            'score' => min(100, $score),
            'signals' => $signals,
            'metrics' => [
                'base_jaccard' => round($baseJaccard, 3),
                'identity_jaccard' => round($identityJaccard, 3),
                'profile_jaccard' => $profileJaccard === null ? null : round($profileJaccard, 3),
                'full_similarity' => round($fullSimilarity, 3),
                'compact_similarity' => round($compactSimilarity, 3),
            ],
        ];
    }

    protected function classifyConfidence(int $score, ?int $runnerUpScore, array $signals): string
    {
        $gap = $runnerUpScore === null ? $score : $score - $runnerUpScore;
        $hasExactBaseName = in_array('base_name_exact', $signals, true);
        $hasExactIdentity = in_array('identity_tokens_exact', $signals, true) || in_array('full_name_exact', $signals, true);

        if ($score >= 90 && $hasExactBaseName && $hasExactIdentity) {
            return 'high';
        }

        if ($score >= 84 && $gap >= 6 && $hasExactBaseName) {
            return 'high';
        }

        if ($score >= 74 && $gap >= 3) {
            return 'medium';
        }

        return 'low';
    }

    protected function candidateSignature(ElectricityContract $candidate): string
    {
        return implode('|', [
            $candidate->company_name,
            $candidate->name,
            $candidate->contract_type,
            $candidate->metering,
            $candidate->pricing_model,
            $candidate->target_group,
            $candidate->fixed_time_range,
        ]);
    }

    protected function normalizeName(?string $value): string
    {
        $value = Str::lower(Str::ascii((string) $value));
        $value = str_replace(['€', '%', '&'], [' euro ', ' prosenttia ', ' ja '], $value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return array<int, string>
     */
    protected function tokenize(string $value): array
    {
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(explode(' ', $value)));
    }

    /**
     * @return array<int, string>
     */
    protected function baseTokens(ElectricityContract $contract): array
    {
        $tokens = $this->tokenize($this->normalizeName($contract->name));

        $filtered = array_filter($tokens, function (string $token) use ($contract) {
            if (in_array($token, $this->noiseTokens, true)) {
                return false;
            }

            if (preg_match('/^\d+$/', $token)) {
                // Fixed-term duration is already guarded structurally.
                return $contract->contract_type !== 'FixedTerm';
            }

            if (preg_match('/^\d+kk$/', $token)) {
                return $contract->contract_type !== 'FixedTerm';
            }

            return true;
        });

        return array_values(array_unique($filtered));
    }

    /**
     * @return array<int, string>
     */
    protected function identityTokenSet(string $normalizedName): array
    {
        $tokens = $this->tokenize($normalizedName);

        $matched = array_values(array_unique(array_filter($tokens, fn (string $token) => in_array($token, $this->identityTokens, true))));

        sort($matched);

        return $matched;
    }

    /**
     * @return array<int, string>
     */
    protected function profileTokenSet(string $normalizedName): array
    {
        $tokens = $this->tokenize($normalizedName);

        $matched = array_values(array_unique(array_filter($tokens, fn (string $token) => in_array($token, $this->profileTokens, true))));

        sort($matched);

        return $matched;
    }

    /**
     * @param array<int, string> $a
     * @param array<int, string> $b
     */
    protected function jaccard(array $a, array $b): float
    {
        $a = array_values(array_unique($a));
        $b = array_values(array_unique($b));

        if (empty($a) && empty($b)) {
            return 1.0;
        }

        $intersection = array_intersect($a, $b);
        $union = array_unique(array_merge($a, $b));

        return count($union) > 0 ? count($intersection) / count($union) : 0.0;
    }

    protected function stringSimilarity(string $a, string $b): float
    {
        if ($a === '' && $b === '') {
            return 1.0;
        }

        similar_text($a, $b, $percent);

        return $percent / 100;
    }

    protected function looksLikePromoVariant(ElectricityContract $inactive, ElectricityContract $candidate): bool
    {
        if ($inactive->contract_type === 'FixedTerm') {
            return false;
        }

        $inactiveBase = implode(' ', $this->baseTokens($inactive));
        $candidateBase = implode(' ', $this->baseTokens($candidate));

        if ($inactiveBase === '' || $candidateBase === '') {
            return false;
        }

        return $inactiveBase === $candidateBase;
    }
}
