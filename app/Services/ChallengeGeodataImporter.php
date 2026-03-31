<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;

class ChallengeGeodataImporter
{
    /**
     * @return array{neighborhoods: int, incidents: int}
     *
     * @throws RuntimeException
     */
    public function importFromPostgres(PDO $postgres): array
    {
        $neighborhoodRows = $this->fetchRemoteRows($postgres, 'neighborhoods');
        $incidentRows = $this->fetchRemoteRows($postgres, 'incidents');

        DB::connection('sqlite')->transaction(function () use ($neighborhoodRows, $incidentRows): void {
            DB::connection('sqlite')->table('incidents')->delete();
            DB::connection('sqlite')->table('neighborhoods')->delete();

            $now = now()->toDateTimeString();

            foreach ($neighborhoodRows as $row) {
                $normalized = $this->normalizeNeighborhoodRow($row);
                if ($normalized['id'] === '' && $normalized['code'] === '') {
                    continue;
                }

                DB::connection('sqlite')->table('neighborhoods')->insert([
                    'id' => $normalized['id'] ?: $normalized['code'],
                    'code' => $normalized['code'] ?: $normalized['id'],
                    'centroid_latitude' => $normalized['centroid_latitude'],
                    'centroid_longitude' => $normalized['centroid_longitude'],
                    'raw_boundary' => $normalized['raw_boundary'] ?: null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ($incidentRows as $row) {
                $normalized = $this->normalizeIncidentRow($row);
                if ($normalized['id'] === '' && $normalized['code'] === '') {
                    continue;
                }

                DB::connection('sqlite')->table('incidents')->insert([
                    'id' => $normalized['id'] ?: $normalized['code'],
                    'code' => $normalized['code'] ?: $normalized['id'],
                    'latitude' => $normalized['latitude'],
                    'longitude' => $normalized['longitude'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        $stats = [
            'neighborhoods' => (int) DB::connection('sqlite')->table('neighborhoods')->count(),
            'incidents' => (int) DB::connection('sqlite')->table('incidents')->count(),
        ];

        if ($stats['neighborhoods'] === 0) {
            throw new RuntimeException('Import wrote zero neighborhoods — check remote SQL and column aliases.');
        }

        if ($stats['incidents'] === 0) {
            throw new RuntimeException('Import wrote zero incidents — check remote SQL and column aliases.');
        }

        return $stats;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRemoteRows(PDO $pdo, string $key): array
    {
        $variants = config("challenge_geodata.remote_sql_variants.{$key}", []);
        $attempts = array_values(array_filter(array_merge(
            is_array($variants) ? $variants : [],
            [config("challenge_geodata.remote_sql.{$key}")],
            [config("challenge_geodata.fallback_remote_sql.{$key}")],
        ), static fn ($sql): bool => is_string($sql) && $sql !== ''));

        $attempts = array_values(array_unique($attempts));
        $lastException = null;

        foreach ($attempts as $sql) {
            try {
                $stmt = $pdo->query($sql);
                if ($stmt === false) {
                    continue;
                }

                /** @var list<array<string, mixed>> $rows */
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if ($rows !== []) {
                    return $rows;
                }
            } catch (PDOException $e) {
                $lastException = $e;
            }
        }

        $discovered = $this->tryFetchByDiscovery($pdo, $key);
        if ($discovered !== null) {
            return $discovered;
        }

        throw new RuntimeException(
            "Could not load {$key} from remote. All SQL variants failed or returned zero rows."
                .($lastException ? ' '.$lastException->getMessage() : ''),
            0,
            $lastException
        );
    }

    /**
     * Introspect information_schema to discover table structure and build a SELECT.
     *
     * @return list<array<string, mixed>>|null
     */
    private function tryFetchByDiscovery(PDO $pdo, string $key): ?array
    {
        $like = $key === 'neighborhoods' ? '%neighbor%' : '%incident%';

        try {
            $stmt = $pdo->prepare(<<<'SQL'
                SELECT table_schema, table_name
                FROM information_schema.tables
                WHERE table_schema NOT IN ('pg_catalog', 'information_schema')
                  AND table_type = 'BASE TABLE'
                  AND table_name ILIKE ?
                ORDER BY CASE WHEN table_schema = 'gis_data' THEN 0 ELSE 1 END,
                         LENGTH(table_name) ASC
                LIMIT 1
                SQL);
            $stmt->execute([$like]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (! $row || empty($row['table_schema']) || empty($row['table_name'])) {
                return null;
            }

            $schema = (string) $row['table_schema'];
            $table = (string) $row['table_name'];
            $columns = $this->fetchColumnMeta($pdo, $schema, $table);

            if ($columns === []) {
                return null;
            }

            return $key === 'neighborhoods'
                ? $this->buildNeighborhoodQuery($pdo, $schema, $table, $columns)
                : $this->buildIncidentQuery($pdo, $schema, $table, $columns);
        } catch (PDOException) {
            return null;
        }
    }

    /**
     * @return list<array{column_name: string, udt_name: string}>
     */
    private function fetchColumnMeta(PDO $pdo, string $schema, string $table): array
    {
        $stmt = $pdo->prepare(<<<'SQL'
            SELECT column_name, udt_name
            FROM information_schema.columns
            WHERE table_schema = ? AND table_name = ?
            ORDER BY ordinal_position
            SQL);
        $stmt->execute([$schema, $table]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param  list<array{column_name: string, udt_name: string}>  $columns
     * @return list<array<string, mixed>>|null
     */
    private function buildNeighborhoodQuery(PDO $pdo, string $schema, string $table, array $columns): ?array
    {
        $names = array_column($columns, 'column_name');
        $t = $this->quoteIdent($schema).'.'.$this->quoteIdent($table);
        $idCol = $this->pickColumn($names, ['id', 'uuid', 'neighborhood_id', 'gid']) ?? $names[0] ?? null;
        $codeCol = $this->pickColumn($names, ['code', 'neighborhood_code', 'name', 'slug', 'identifier']) ?? $idCol;

        if (! $idCol) {
            return null;
        }

        $polygonCol = $this->findColumnByType($columns, 'polygon');
        if ($polygonCol) {
            $p = $this->quoteIdent($polygonCol);
            $center = "center(box({$p}))";
            $sql = "SELECT {$this->quoteIdent($idCol)}::text AS id, "
                ."{$this->quoteIdent($codeCol)}::text AS code, "
                ."split_part(trim(both '()' from {$center}::text), ',', 2)::double precision AS centroid_latitude, "
                ."split_part(trim(both '()' from {$center}::text), ',', 1)::double precision AS centroid_longitude, "
                ."{$p}::text AS raw_boundary "
                ."FROM {$t}";

            return $this->tryQuery($pdo, $sql);
        }

        return null;
    }

    /**
     * @param  list<array{column_name: string, udt_name: string}>  $columns
     * @return list<array<string, mixed>>|null
     */
    private function buildIncidentQuery(PDO $pdo, string $schema, string $table, array $columns): ?array
    {
        $names = array_column($columns, 'column_name');
        $t = $this->quoteIdent($schema).'.'.$this->quoteIdent($table);
        $idCol = $this->pickColumn($names, ['id', 'uuid', 'incident_id', 'gid']) ?? $names[0] ?? null;
        $codeCol = $this->pickColumn($names, ['code', 'fragment', 'token', 'chunk', 'name']) ?? $idCol;

        if (! $idCol) {
            return null;
        }

        $hasMetadata = $this->findColumnByType($columns, 'jsonb') !== null
            && in_array('metadata', $names, true);

        $codeExpr = $hasMetadata
            ? "COALESCE(metadata->'incident'->>'code', metadata->>'code', metadata->>'fragment', {$this->quoteIdent($codeCol)}::text)"
            : "{$this->quoteIdent($codeCol)}::text";

        $pointCol = $this->findColumnByType($columns, 'point');
        if ($pointCol) {
            $pt = $this->quoteIdent($pointCol);
            $sql = "SELECT {$this->quoteIdent($idCol)}::text AS id, "
                ."{$codeExpr} AS code, "
                ."split_part(trim(both '()' from {$pt}::text), ',', 2)::double precision AS latitude, "
                ."split_part(trim(both '()' from {$pt}::text), ',', 1)::double precision AS longitude "
                ."FROM {$t}";

            return $this->tryQuery($pdo, $sql);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, code: string, centroid_latitude: float, centroid_longitude: float, raw_boundary: string}
     */
    private function normalizeNeighborhoodRow(array $row): array
    {
        $id = trim((string) ($row['id'] ?? ''));
        $code = trim((string) ($row['code'] ?? $row['name'] ?? $id));
        $centroidLat = (float) ($row['centroid_latitude'] ?? 0);
        $centroidLng = (float) ($row['centroid_longitude'] ?? 0);
        $rawBoundary = trim((string) ($row['raw_boundary'] ?? ''));

        return [
            'id' => $id,
            'code' => $code,
            'centroid_latitude' => $centroidLat,
            'centroid_longitude' => $centroidLng,
            'raw_boundary' => $rawBoundary,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, code: string, latitude: float, longitude: float}
     */
    private function normalizeIncidentRow(array $row): array
    {
        return [
            'id' => trim((string) ($row['id'] ?? '')),
            'code' => trim((string) ($row['code'] ?? $row['fragment'] ?? $row['token'] ?? '')),
            'latitude' => (float) ($row['latitude'] ?? 0),
            'longitude' => (float) ($row['longitude'] ?? 0),
        ];
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $candidates
     */
    private function pickColumn(array $haystack, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (in_array($c, $haystack, true)) {
                return $c;
            }
        }

        return null;
    }

    /**
     * @param  list<array{column_name: string, udt_name: string}>  $columns
     */
    private function findColumnByType(array $columns, string $type): ?string
    {
        foreach ($columns as $c) {
            if (strtolower($c['udt_name']) === $type) {
                return $c['column_name'];
            }
        }

        return null;
    }

    private function quoteIdent(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function tryQuery(PDO $pdo, string $sql): ?array
    {
        try {
            $stmt = $pdo->query($sql);

            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : null;
        } catch (PDOException) {
            return null;
        }
    }
}
