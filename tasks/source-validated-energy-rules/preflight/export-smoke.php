<?php

declare(strict_types=1);

require __DIR__.'/export.php';

function checkExport(bool $ok): void
{
    if (! $ok) {
        throw new RuntimeException('Offline export assertion failed.');
    }
}

$db = new PDO('sqlite::memory:');
$columns = ['id' => ['COLUMN_NAME' => 'id', 'DATA_TYPE' => 'bigint', 'COLLATION_NAME' => null]];
preflightCreateTable($db, 'fixture', array_values($columns));
$part = ['COLUMN_NAME' => 'id', 'NON_UNIQUE' => 0, 'SUB_PART' => null, 'INDEX_TYPE' => 'BTREE'];
$sql = preflightIndexSql('fixture', 'PRIMARY', [1 => $part], $columns);
checkExport(str_starts_with($sql, 'CREATE INDEX'));
$db->exec($sql);
$db->exec('INSERT INTO fixture VALUES (1),(2)');
checkExport(preflightPrimaryKeyDuplicates($db, 'fixture', [1 => $part]) === 0);
$db->exec('INSERT INTO fixture VALUES (1)');
checkExport(preflightPrimaryKeyDuplicates($db, 'fixture', [1 => $part]) === 1);
foreach (['text_primary', 'text_unique', 'decimal_unique', 'ordinary'] as $case) {
    $c = ['id' => ['COLUMN_NAME' => 'id', 'DATA_TYPE' => $case === 'decimal_unique' ? 'decimal' : 'varchar', 'COLLATION_NAME' => $case === 'decimal_unique' ? null : 'utf8mb4_unicode_ci']];
    $p = $part;
    $p['NON_UNIQUE'] = $case === 'ordinary' ? 1 : 0;
    preflightCreateTable($db, $case, array_values($c));
    $sql = preflightIndexSql($case, $case === 'text_primary' ? 'PRIMARY' : 'lookup', [1 => $p], $c);
    checkExport(str_starts_with($sql, 'CREATE INDEX'));
    $db->exec($sql);
    checkExport((int) $db->query('PRAGMA index_list('.preflightIdentifier($case).')')->fetch(PDO::FETCH_ASSOC)['unique'] === 0);
}
checkExport((int) $db->query('PRAGMA index_list(fixture)')->fetch(PDO::FETCH_ASSOC)['unique'] === 0);
$before = $db->query('SELECT * FROM fixture ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$db->exec('ANALYZE');
checkExport($before === $db->query('SELECT * FROM fixture ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
checkExport((int) $db->query('SELECT COUNT(*) FROM sqlite_stat1')->fetchColumn() > 0);
foreach (['prefix', 'expression', 'partial', 'missing_column', 'incomplete', 'unknown_uniqueness', 'missing_prefix_metadata', 'missing_collation_metadata', 'index_type', 'invalid_identifier', 'inconsistent_uniqueness', 'empty'] as $case) {
    $p = $part;
    $c = $columns;
    $sequence = 1;
    match ($case) {
        'prefix' => $p['SUB_PART'] = 8,
        'expression' => $p['EXPRESSION'] = 'lower(id)',
        'partial' => $p['WHERE'] = 'id > 0',
        'missing_column' => $p['COLUMN_NAME'] = 'absent',
        'incomplete' => $sequence = 2,
        'unknown_uniqueness' => $p['NON_UNIQUE'] = null,
        'index_type' => $p['INDEX_TYPE'] = 'FULLTEXT',
        default => null,
    };
    if ($case === 'missing_prefix_metadata') {
        unset($p['SUB_PART']);
    }
    if ($case === 'missing_collation_metadata') {
        unset($c['id']['COLLATION_NAME']);
    }
    $parts = [$sequence => $p];
    if ($case === 'inconsistent_uniqueness') {
        $parts[2] = array_replace($p, ['NON_UNIQUE' => 1]);
    }
    $rejected = false;
    try {
        preflightIndexSql('fixture', $case === 'invalid_identifier' ? 'invalid-name' : 'test', $case === 'empty' ? [] : $parts, $c);
    } catch (RuntimeException) {
        $rejected = true;
    }
    checkExport($rejected);
}
$p = $part;
$p['NON_UNIQUE'] = 1;
checkExport(str_starts_with(preflightIndexSql('fixture', 'ordinary', [1 => $p], $columns), 'CREATE INDEX'));
$db->exec('CREATE TABLE electricity_contracts (id INTEGER, current_source_observation_id INTEGER, published_interpretation_id INTEGER)');
$db->exec('CREATE TABLE contract_source_observations (id INTEGER, source_snapshot_id INTEGER)');
$db->exec('CREATE TABLE contract_interpretations (id INTEGER, source_snapshot_id INTEGER, analysis_source_observation_id INTEGER)');
$db->exec('INSERT INTO electricity_contracts VALUES (1,1,1),(2,2,2),(3,3,3),(4,4,4)');
$db->exec('INSERT INTO contract_source_observations VALUES (1,1),(2,2),(3,3),(4,4)');
$db->exec('INSERT INTO contract_interpretations VALUES (1,1,NULL),(2,2,99),(3,99,NULL),(4,4,4)');
$facts = preflightPublicationFacts($db);
checkExport(array_column($facts['current_publication_source_mismatches'], 'id') === [2, 3]);
checkExport(array_column($facts['current_publication_null_analysis_source_pointers'], 'id') === [1, 3]);
$graph = preflightGraph([['id' => 'a', 'replaced_by_contract_id' => 'b'], ['id' => 'b', 'replaced_by_contract_id' => 'a'], ['id' => 'c', 'replaced_by_contract_id' => 'missing']], ['a', 'orphan']);
checkExport(count($graph['cycles']) === 1 && count($graph['missing_replacements']) === 1 && $graph['missing_active_ids'] === ['orphan']);
echo "PASS: nonunique lookup indexes (text primary/unique and decimal unique supported), explicit primary-key duplicates, twelve invalid index cases, ANALYZE row equality, NULL/mismatch separation, graph anomalies.\n";
