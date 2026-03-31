<?php

declare(strict_types=1);

/**
 * Remote SQL for gis_data.neighborhoods — extracts bounding-box centroid
 * and raw boundary text (for Shoelace centroid computation in PHP).
 */
$sqlNeighborhoods = <<<'SQL'
SELECT
    id::text AS id,
    name::text AS code,
    split_part(trim(both '()' from center(box(boundary))::text), ',', 2)::double precision AS centroid_latitude,
    split_part(trim(both '()' from center(box(boundary))::text), ',', 1)::double precision AS centroid_longitude,
    boundary::text AS raw_boundary
FROM gis_data.neighborhoods
SQL;

/**
 * Remote SQL for gis_data.incidents — extracts incident code from nested
 * metadata JSON (metadata->'incident'->>'code') and location from native point.
 */
$sqlIncidents = <<<'SQL'
SELECT
    id::text AS id,
    COALESCE(metadata->'incident'->>'code', metadata->>'code', metadata->>'fragment', metadata->>'token', id::text) AS code,
    split_part(trim(both '()' from location::text), ',', 2)::double precision AS latitude,
    split_part(trim(both '()' from location::text), ',', 1)::double precision AS longitude
FROM gis_data.incidents
SQL;

return [
    'neighborhood_code' => env('CHALLENGE_NEIGHBORHOOD_CODE', 'NB-7A2F'),

    'donut_inner_km' => (float) env('CHALLENGE_DONUT_INNER_KM', 0.5),

    'donut_outer_km' => (float) env('CHALLENGE_DONUT_OUTER_KM', 2.0),

    'remote_sql' => [
        'neighborhoods' => env('GEODATA_SQL_NEIGHBORHOODS', $sqlNeighborhoods),
        'incidents' => env('GEODATA_SQL_INCIDENTS', $sqlIncidents),
    ],

    'fallback_remote_sql' => [
        'neighborhoods' => 'SELECT id::text AS id, code::text AS code, centroid_latitude::double precision, centroid_longitude::double precision FROM neighborhoods',
        'incidents' => 'SELECT id::text AS id, code::text AS code, latitude::double precision, longitude::double precision FROM incidents',
    ],

    'remote_sql_variants' => [
        'neighborhoods' => [
            $sqlNeighborhoods,
        ],
        'incidents' => [
            $sqlIncidents,
        ],
    ],
];
