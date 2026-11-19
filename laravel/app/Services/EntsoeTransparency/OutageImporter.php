<?php

namespace App\Services\EntsoeTransparency;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use SimpleXMLElement;

/**
 * Store ENTSO-E unavailability documents (A77 production units, A78 transmission, A80
 * generation units) in entsoe_outages: one row per document version and TimeSeries.
 *
 * Rows are insert-only on (document_mrid, revision_number, time_series_mrid): a version is
 * immutable evidence of what was known when it was published. Cancelled versions
 * (docStatus A09) are kept and flagged. The available-power curve is stored as constant
 * segments (A03 expanded), not per minute.
 */
class OutageImporter
{
    private const INSERT_CHUNK_SIZE = 200;

    /**
     * @param  list<string>  $documents
     * @return int Number of new version rows
     */
    public function import(string $documentType, string $zone, array $documents, CarbonInterface $fetchedAt): int
    {
        $now = now();
        $fetched = $fetchedAt->copy()->utc()->format('Y-m-d H:i:s');
        $records = [];

        foreach ($documents as $document) {
            $xml = new SimpleXMLElement($document);
            $mrid = (string) $xml->mRID;
            $revision = (int) $xml->revisionNumber;
            $interval = $xml->{'unavailability_Time_Period.timeInterval'};
            $start = (string) $interval->start;
            $end = (string) $interval->end;
            if ($mrid === '' || $start === '' || $end === '') {
                continue;
            }

            $startTime = CarbonImmutable::parse($start, 'UTC')->utc();
            $endTime = CarbonImmutable::parse($end, 'UTC')->utc();
            $docStatus = (string) $xml->docStatus->value;
            $created = (string) $xml->createdDateTime;
            $documentReasons = $this->reasons($xml);

            foreach ($xml->TimeSeries as $series) {
                $curveType = (string) $series->curveType ?: 'A01';
                $segments = [];
                foreach ($series->Available_Period as $period) {
                    array_push($segments, ...EntsoeCurve::segments($period, $curveType));
                }

                $resource = $this->resource($series);
                $records[] = $resource + [
                    'document_type' => (string) $xml->type ?: $documentType,
                    'zone' => $zone,
                    'document_mrid' => $mrid,
                    'revision_number' => $revision,
                    'time_series_mrid' => (string) $series->mRID ?: '1',
                    'business_type' => (string) $series->businessType ?: null,
                    'doc_status' => $docStatus !== '' ? $docStatus : null,
                    'is_cancelled' => $docStatus === 'A09',
                    'document_created_at' => $created !== '' ? CarbonImmutable::parse($created, 'UTC')->utc()->format('Y-m-d H:i:s') : null,
                    'start_utc' => EntsoeCurve::format($startTime),
                    'end_utc' => EntsoeCurve::format($endTime),
                    'start_timestamp' => $startTime->getTimestamp(),
                    'end_timestamp' => $endTime->getTimestamp(),
                    'min_available_mw' => $segments === [] ? null : min(array_column($segments, 'mw')),
                    'available_periods' => json_encode($segments, JSON_THROW_ON_ERROR),
                    'reasons' => json_encode(array_merge($this->reasons($series), $documentReasons), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'fetched_at' => $fetched,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $inserted = 0;
        foreach (array_chunk($records, self::INSERT_CHUNK_SIZE) as $chunk) {
            $inserted += DB::table('entsoe_outages')->insertOrIgnore($chunk);
        }

        return $inserted;
    }

    /**
     * The version identity of each document (mRID, revision, outage start). The staged fetch step
     * reads it from the fetched bodies to plan the version-history queries (S19).
     *
     * @param  list<string>  $documents
     * @return array<string, array{mrid: string, revision: int, start: CarbonImmutable}> keyed "mrid|revision"
     */
    public function versions(array $documents): array
    {
        $versions = [];
        foreach ($documents as $document) {
            $xml = new SimpleXMLElement($document);
            $mrid = (string) $xml->mRID;
            $start = (string) $xml->{'unavailability_Time_Period.timeInterval'}->start;
            if ($mrid === '' || $start === '' || (string) $xml->{'unavailability_Time_Period.timeInterval'}->end === '') {
                continue;
            }
            $revision = (int) $xml->revisionNumber;
            $versions[$mrid.'|'.$revision] = ['mrid' => $mrid, 'revision' => $revision, 'start' => CarbonImmutable::parse($start, 'UTC')->utc()];
        }

        return $versions;
    }

    /**
     * The documents with revision > 1 whose previous revision is neither stored nor among the
     * given (just fetched) versions. Read-only: the fetch step plans with it and writes nothing.
     *
     * @param  array<string, array{mrid: string, revision: int, start: CarbonImmutable}>  $versions  keyed "mrid|revision"
     * @return list<array{mrid: string, start: CarbonImmutable}>
     */
    public function missingPreviousRevisions(array $versions): array
    {
        $candidates = array_filter($versions, fn (array $version) => $version['revision'] > 1);
        if ($candidates === []) {
            return [];
        }

        $stored = array_fill_keys(array_keys($versions), true);
        foreach (array_chunk(array_unique(array_column($candidates, 'mrid')), 500) as $mrids) {
            DB::table('entsoe_outages')
                ->whereIn('document_mrid', $mrids)
                ->select(['document_mrid', 'revision_number'])
                ->distinct()
                ->get()
                ->each(function ($row) use (&$stored) {
                    $stored[$row->document_mrid.'|'.$row->revision_number] = true;
                });
        }

        $missing = [];
        foreach ($candidates as $version) {
            if (! isset($stored[$version['mrid'].'|'.($version['revision'] - 1)])) {
                $missing[$version['mrid']] ??= ['mrid' => $version['mrid'], 'start' => $version['start']];
            }
        }

        return array_values($missing);
    }

    /**
     * Unit fields of a generation/production TimeSeries, or the first asset of a transmission one.
     *
     * @return array<string, mixed>
     */
    private function resource(SimpleXMLElement $series): array
    {
        $nominal = (string) $series->{'production_RegisteredResource.pSRType.powerSystemResources.nominalP'};

        if (isset($series->Asset_RegisteredResource)) {
            $assets = [];
            foreach ($series->Asset_RegisteredResource as $asset) {
                $assets[] = [
                    'mrid' => (string) $asset->mRID,
                    'name' => (string) $asset->name,
                    'psr_type' => (string) $asset->{'asset_PSRType.psrType'},
                    'location' => (string) $asset->{'location.name'},
                ];
            }

            return [
                'resource_mrid' => $assets[0]['mrid'] ?: null,
                'resource_name' => $assets[0]['name'] ?: null,
                'location_name' => $assets[0]['location'] ?: null,
                'psr_type' => $assets[0]['psr_type'] ?: null,
                'unit_mrid' => null,
                'unit_name' => null,
                'nominal_mw' => null,
                'assets' => json_encode($assets, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ];
        }

        return [
            'resource_mrid' => (string) $series->{'production_RegisteredResource.mRID'} ?: null,
            'resource_name' => (string) $series->{'production_RegisteredResource.name'} ?: null,
            'location_name' => (string) $series->{'production_RegisteredResource.location.name'} ?: null,
            'psr_type' => (string) $series->{'production_RegisteredResource.pSRType.psrType'} ?: null,
            'unit_mrid' => (string) $series->{'production_RegisteredResource.pSRType.powerSystemResources.mRID'} ?: null,
            'unit_name' => (string) $series->{'production_RegisteredResource.pSRType.powerSystemResources.name'} ?: null,
            'nominal_mw' => is_numeric($nominal) ? (float) $nominal : null,
            'assets' => null,
        ];
    }

    /**
     * @return list<array{code: string, text: string}>
     */
    private function reasons(SimpleXMLElement $element): array
    {
        $reasons = [];
        foreach ($element->Reason as $reason) {
            $code = (string) $reason->code;
            if ($code !== '') {
                $reasons[] = ['code' => $code, 'text' => trim((string) $reason->text)];
            }
        }

        return $reasons;
    }
}
