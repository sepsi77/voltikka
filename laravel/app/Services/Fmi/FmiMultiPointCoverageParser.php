<?php

namespace App\Services\Fmi;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Parse an FMI "multipointcoverage" WFS response into plain arrays.
 *
 * The document is parsed without a DTD, entity substitution or network access; a document
 * with a DOCTYPE is rejected. Each wfs:member holds a list of positions "lat lon unixtime" and a
 * matching list of value tuples (one value per field, "NaN" for no value). The result groups them
 * per position: timestamps plus one value list per field, null for no value.
 */
class FmiMultiPointCoverageParser
{
    private const NAMESPACES = [
        'wfs' => 'http://www.opengis.net/wfs/2.0',
        'om' => 'http://www.opengis.net/om/2.0',
        'gml' => 'http://www.opengis.net/gml/3.2',
        'gmlcov' => 'http://www.opengis.net/gmlcov/1.0',
        'swe' => 'http://www.opengis.net/swe/2.0',
    ];

    /**
     * @return list<array{
     *     result_time: ?string,
     *     fields: list<string>,
     *     points: list<array{id: string, latitude: float, longitude: float}>,
     *     series: list<array{latitude: float, longitude: float, timestamps: list<int>, values: array<string, list<?float>>}>
     * }>
     *
     * @throws FmiRequestException
     */
    public function parse(string $xml): array
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new FmiRequestException('FMI response has a DOCTYPE; it is not parsed.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        if (! $loaded || $document->documentElement === null) {
            throw new FmiRequestException('FMI response is not valid XML.');
        }
        if ($document->documentElement->localName === 'ExceptionReport') {
            throw new FmiRequestException('FMI returned an exception report.');
        }
        if ($document->documentElement->localName !== 'FeatureCollection') {
            throw new FmiRequestException('FMI response is not a feature collection.');
        }

        $xpath = new DOMXPath($document);
        foreach (self::NAMESPACES as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        $members = [];
        foreach ($xpath->query('/wfs:FeatureCollection/wfs:member/*') as $observation) {
            if ($observation instanceof DOMElement) {
                $members[] = $this->member($xpath, $observation);
            }
        }

        return $members;
    }

    /**
     * @return array{result_time: ?string, fields: list<string>, points: list<array{id: string, latitude: float, longitude: float}>, series: list<array{latitude: float, longitude: float, timestamps: list<int>, values: array<string, list<?float>>}>}
     */
    private function member(DOMXPath $xpath, DOMElement $observation): array
    {
        $resultTime = trim((string) $xpath->evaluate('string(om:resultTime//gml:timePosition)', $observation));

        $fields = [];
        foreach ($xpath->query('.//swe:field', $observation) as $field) {
            $fields[] = (string) $field->getAttribute('name');
        }
        if ($fields === []) {
            throw new FmiRequestException('FMI response has no fields.');
        }

        $points = [];
        foreach ($xpath->query('om:featureOfInterest//gml:Point', $observation) as $point) {
            $position = preg_split('/\s+/', trim((string) $xpath->evaluate('string(gml:pos)', $point))) ?: [];
            if (count($position) >= 2 && is_numeric($position[0]) && is_numeric($position[1])) {
                $points[] = [
                    'id' => (string) $point->getAttributeNS(self::NAMESPACES['gml'], 'id'),
                    'latitude' => (float) $position[0],
                    'longitude' => (float) $position[1],
                ];
            }
        }

        $positions = $this->tokens((string) $xpath->evaluate('string(.//gmlcov:positions)', $observation));
        $values = $this->tokens((string) $xpath->evaluate('string(.//gml:doubleOrNilReasonTupleList)', $observation));

        if (count($positions) % 3 !== 0) {
            throw new FmiRequestException('FMI positions are not "lat lon time" triples.');
        }
        $rowCount = intdiv(count($positions), 3);
        $fieldCount = count($fields);
        if (count($values) !== $rowCount * $fieldCount) {
            throw new FmiRequestException('FMI value count does not match the positions.');
        }

        // Columnar per position: about 16 bytes per value instead of one PHP array per row.
        $series = [];
        for ($row = 0; $row < $rowCount; $row++) {
            [$latitude, $longitude, $time] = array_slice($positions, $row * 3, 3);
            if (! is_numeric($latitude) || ! is_numeric($longitude) || ! ctype_digit($time)) {
                throw new FmiRequestException('FMI position is not numeric.');
            }

            $key = $latitude.' '.$longitude;
            $series[$key] ??= [
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
                'timestamps' => [],
                'values' => array_fill_keys($fields, []),
            ];
            $series[$key]['timestamps'][] = (int) $time;
            foreach ($fields as $index => $field) {
                $value = $values[$row * $fieldCount + $index];
                $series[$key]['values'][$field][] = is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
            }
        }
        unset($positions, $values);

        return [
            'result_time' => $resultTime !== '' ? $resultTime : null,
            'fields' => $fields,
            'points' => $points,
            'series' => array_values($series),
        ];
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $text = trim($text);

        return $text === '' ? [] : (preg_split('/\s+/', $text) ?: []);
    }
}
