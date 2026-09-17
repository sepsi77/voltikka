<?php

declare(strict_types=1);

require_once __DIR__.'/compare.php';

function displayedBenefitAmount(mixed $amount): mixed
{
    return is_numeric($amount) ? round((float) $amount, 2) : $amount;
}

function transportReady(array $run): bool
{
    foreach (['5000_cold', '5000_warm', '2000', '18000'] as $phase) {
        $record = $run['phases'][$phase] ?? null;
        $validation = $record['transport_validation'] ?? null;
        if (! is_array($validation) || $validation['error_count'] !== 0
            || $validation['checked_count'] !== count($record['rows'])
            || $validation['valid_count'] !== $validation['checked_count']) {
            return false;
        }
    }

    return true;
}

function compareResults(array $baseline, array $candidate): array
{
    foreach (['as_of', 'database_sha256', 'flags_sha256', 'baseline_commit_required', 'effective_pricing_config', 'effective_market_config'] as $key) {
        requireThat($baseline[$key] === $candidate[$key], 'Input mismatch: '.$key);
    }
    $report = ['baseline_commit_required' => DEPLOYED_COMMIT, 'scope' => 'Staged-code recalculation, not V5 re-analysis. RECOMPUTED engine order, not live cached ranking.', 'candidate_transport_ready' => transportReady($candidate), 'phases' => []];
    foreach ($baseline['phases'] as $phase => $before) {
        $after = $candidate['phases'][$phase];
        requireThat(array_keys($before['rows']) === array_keys($after['rows']), 'Contract universe mismatch.');
        $oldRanks = array_flip($before['recomputed_engine_order']);
        $newRanks = array_flip($after['recomputed_engine_order']);
        $changes = [];
        $differences = [];
        $reasonsBefore = [];
        $reasonsAfter = [];
        $listedBefore = 0;
        $listedAfter = 0;
        foreach ($before['rows'] as $id => $old) {
            $new = $after['rows'][$id];
            $listedBefore += (int) $old['listed'];
            $listedAfter += (int) $new['listed'];
            $oldReason = $old['cost']['comparability'];
            $newReason = $new['cost']['comparability'];
            $reasonsBefore[$oldReason] = ($reasonsBefore[$oldReason] ?? 0) + 1;
            $reasonsAfter[$newReason] = ($reasonsAfter[$newReason] ?? 0) + 1;
            $delta = is_numeric($old['cost']['total_cost']) && is_numeric($new['cost']['total_cost']) ? $new['cost']['total_cost'] - $old['cost']['total_cost'] : null;
            if ($delta !== null) {
                $differences[] = abs($delta);
            }
            $oldTotal = $old['cost']['total_cost'];
            $newTotal = $new['cost']['total_cost'];
            $oldDisplayed = is_numeric($oldTotal) ? round((float) $oldTotal, 2) : null;
            $newDisplayed = is_numeric($newTotal) ? round((float) $newTotal, 2) : null;
            $rawTotalChanged = $delta !== null ? (float) $oldTotal !== (float) $newTotal : $oldTotal !== $newTotal;
            $benefitChanged = displayedBenefitAmount($old['cost']['discount_savings_total'] ?? null) !== displayedBenefitAmount($new['cost']['discount_savings_total'] ?? null)
                || ($old['cost']['includes_discounts'] ?? null) !== ($new['cost']['includes_discounts'] ?? null)
                || displayedBenefitAmount($old['cost']['contract_term']['discount_savings_total'] ?? null) !== displayedBenefitAmount($new['cost']['contract_term']['discount_savings_total'] ?? null);
            $oldTermMetadata = $old['cost']['contract_term'] ?? null;
            $newTermMetadata = $new['cost']['contract_term'] ?? null;
            if (is_array($oldTermMetadata)) {
                unset($oldTermMetadata['discount_savings_total']);
            }
            if (is_array($newTermMetadata)) {
                unset($newTermMetadata['discount_savings_total']);
            }
            $fields = [];
            foreach (['total_cost', 'base_total_cost', 'contract_term', 'discount_savings_total', 'includes_discounts', 'comparability', 'estimate_method', 'assumptions', 'energy_rule_comparison', 'offer_terms', 'reset_estimate', 'supplier_adjusted_estimate', 'spot_estimate'] as $key) {
                if (($old['cost'][$key] ?? null) !== ($new['cost'][$key] ?? null)) {
                    $fields[] = $key;
                }
            }
            $rankBefore = isset($oldRanks[$id]) ? $oldRanks[$id] + 1 : null;
            $rankAfter = isset($newRanks[$id]) ? $newRanks[$id] + 1 : null;
            if ($fields !== [] || $old['listed'] !== $new['listed'] || $rankBefore !== $rankAfter) {
                $changes[$id] = ['metadata' => $candidate['audit']['contracts'][$id], 'changed_fields' => $fields, 'annual_delta_eur' => $delta, 'annual_displayed_delta_eur' => $delta !== null ? round($newDisplayed - $oldDisplayed, 2) : null, 'raw_total_changed' => $rawTotalChanged, 'displayed_total_changed' => $oldDisplayed !== $newDisplayed, 'benefit_changed' => $benefitChanged, 'term_metadata_changed' => $oldTermMetadata !== $newTermMetadata, 'rank_before' => $rankBefore, 'rank_after' => $rankAfter, 'rank_movement' => $rankBefore !== null && $rankAfter !== null ? $rankBefore - $rankAfter : null, 'before' => $old, 'after' => $new];
            }
        }
        sort($differences);
        $n = count($differences);
        $examples = $changes;
        uasort($examples, fn ($a, $b) => abs($b['annual_delta_eur'] ?? 0) <=> abs($a['annual_delta_eur'] ?? 0));
        $report['phases'][$phase] = ['transport_validation_before' => $before['transport_validation'] ?? null, 'transport_validation_after' => $after['transport_validation'] ?? null, 'count' => count($before['rows']), 'listed_before' => $listedBefore, 'listed_after' => $listedAfter, 'excluded_before' => count($before['rows']) - $listedBefore, 'excluded_after' => count($after['rows']) - $listedAfter, 'comparability_before' => $reasonsBefore, 'comparability_after' => $reasonsAfter, 'changed_raw_total_count' => count(array_filter($changes, fn ($r) => $r['raw_total_changed'])), 'changed_displayed_total_count' => count(array_filter($changes, fn ($r) => $r['displayed_total_changed'])), 'changed_benefit_count' => count(array_filter($changes, fn ($r) => $r['benefit_changed'])), 'changed_term_metadata_count' => count(array_filter($changes, fn ($r) => $r['term_metadata_changed'])), 'absolute_delta_population' => 'All paired raw numeric totals, including unchanged totals; displayed totals are rounded to two decimals', 'median_absolute_delta_eur' => $n ? ($differences[intdiv($n - 1, 2)] + $differences[intdiv($n, 2)]) / 2 : null, 'max_absolute_delta_eur' => $n ? max($differences) : null, 'metrics_before' => $before['metrics'], 'metrics_after' => $after['metrics'], 'changes' => $changes, 'largest_examples' => array_slice($examples, 0, 15, true)];
    }
    foreach (['baseline' => $baseline, 'candidate' => $candidate] as $label => $run) {
        $report[$label.'_warm_matches_cold'] = $run['phases']['5000_cold']['rows'] === $run['phases']['5000_warm']['rows'];
        requireThat($report[$label.'_warm_matches_cold'], 'Cold/warm mismatch: '.$label);
    }

    return $report;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    umask(0077);
    try {
        $options = getopt('', ['baseline:', 'candidate:', 'output:', 'require-candidate-transport']);
        requireThat((fileperms(dirname($options['output'])) & 0077) === 0, 'Use a private output directory.');
        $baseline = json_decode(file_get_contents($options['baseline']), true, 512, JSON_THROW_ON_ERROR);
        $candidate = json_decode(file_get_contents($options['candidate']), true, 512, JSON_THROW_ON_ERROR);
        $report = compareResults($baseline, $candidate);
        $report['artifacts'] = ['baseline' => realpath($options['baseline']), 'candidate' => realpath($options['candidate']), 'baseline_sha256' => hash_file('sha256', $options['baseline']), 'candidate_sha256' => hash_file('sha256', $options['candidate'])];
        privateJson($options['output'], $report);
        echo "Private comparison written.\n";
        if (array_key_exists('require-candidate-transport', $options) && ! $report['candidate_transport_ready']) {
            fwrite(STDERR, "Candidate transport gate failed; comparison is diagnostic only.\n");
            exit(2);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Comparison failed; no report is valid.\n");
        exit(1);
    }
}
