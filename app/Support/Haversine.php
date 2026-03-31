<?php

declare(strict_types=1);

namespace App\Support;

final class Haversine
{
    private const EARTH_RADIUS_KM = 6371.0;

    public static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $φ1 = deg2rad($lat1);
        $φ2 = deg2rad($lat2);
        $Δφ = deg2rad($lat2 - $lat1);
        $Δλ = deg2rad($lon2 - $lon1);

        $a = sin($Δφ / 2) ** 2 + cos($φ1) * cos($φ2) * sin($Δλ / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }
}
