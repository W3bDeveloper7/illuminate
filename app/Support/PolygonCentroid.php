<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Computes the true geometric centroid (area-weighted center of mass) of a simple polygon
 * using the Shoelace formula, matching PostGIS ST_Centroid() for planar coordinates.
 *
 * Accepts native PostgreSQL polygon text: ((x1,y1),(x2,y2),...,(xn,yn))
 * where x = longitude, y = latitude per standard GIS convention.
 */
final class PolygonCentroid
{
    /**
     * @return array{latitude: float, longitude: float}
     *
     * @throws InvalidArgumentException When fewer than 3 vertices are found
     */
    public static function fromPgPolygon(string $pgPolygonText): array
    {
        $vertices = self::parseVertices($pgPolygonText);

        if (count($vertices) < 3) {
            throw new InvalidArgumentException(
                'Polygon must have at least 3 vertices, got '.count($vertices)
            );
        }

        return self::computeCentroid($vertices);
    }

    /**
     * @return list<array{0: float, 1: float}> [x, y] pairs (longitude, latitude)
     */
    private static function parseVertices(string $text): array
    {
        // Numeric capture avoids the leading '(' from the outer polygon delimiters.
        preg_match_all(
            '/\(\s*([-+]?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?)\s*,\s*([-+]?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?)\s*\)/',
            $text,
            $matches,
            PREG_SET_ORDER
        );

        $vertices = [];
        foreach ($matches as $m) {
            $vertices[] = [(float) $m[1], (float) $m[2]];
        }

        return $vertices;
    }

    /**
     * Shoelace centroid for a simple polygon.
     *
     * @param  list<array{0: float, 1: float}>  $vertices  [x, y] pairs
     * @return array{latitude: float, longitude: float}
     */
    private static function computeCentroid(array $vertices): array
    {
        $n = count($vertices);
        $signedArea = 0.0;
        $cx = 0.0;
        $cy = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            $cross = $vertices[$i][0] * $vertices[$j][1] - $vertices[$j][0] * $vertices[$i][1];
            $signedArea += $cross;
            $cx += ($vertices[$i][0] + $vertices[$j][0]) * $cross;
            $cy += ($vertices[$i][1] + $vertices[$j][1]) * $cross;
        }

        $signedArea *= 0.5;

        if (abs($signedArea) < 1e-15) {
            // Degenerate polygon — fall back to vertex average.
            $avgX = array_sum(array_column($vertices, 0)) / $n;
            $avgY = array_sum(array_column($vertices, 1)) / $n;

            return ['latitude' => $avgY, 'longitude' => $avgX];
        }

        $factor = 1.0 / (6.0 * $signedArea);

        return [
            'latitude' => $cy * $factor,
            'longitude' => $cx * $factor,
        ];
    }
}
