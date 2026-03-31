<?php

declare(strict_types=1);

use App\Support\Haversine;

it('computes zero distance for identical points', function (): void {
    expect(Haversine::distanceKm(10.0, 20.0, 10.0, 20.0))->toBeLessThan(0.0001);
});

it('computes approximate km between known points', function (): void {
    // Paris ~ London rough check (~340 km)
    $km = Haversine::distanceKm(48.8566, 2.3522, 51.5074, -0.1278);
    expect($km)->toBeGreaterThan(300)->toBeLessThan(400);
});
