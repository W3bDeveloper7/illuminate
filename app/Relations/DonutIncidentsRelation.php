<?php

declare(strict_types=1);

namespace App\Relations;

use App\Models\Incident;
use App\Models\Neighborhood;
use App\Support\Haversine;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Custom Eloquent relation: incidents within an annulus (donut) around
 * the parent neighborhood's centroid.
 *
 * Distance is computed with the Haversine formula, matching the km radii
 * specified in the challenge requirements (0.5 km inner, 2.0 km outer).
 *
 * Boundary convention: (innerKm, outerKm] — exclusive inner, inclusive outer.
 * Sort: increasing geodesic distance, with natural-order code as tiebreaker.
 *
 * @extends Relation<Incident, Neighborhood, EloquentCollection<int, Incident>>
 */
class DonutIncidentsRelation extends Relation
{
    protected bool $eagerDonutLoading = false;

    public function __construct(
        Neighborhood $parent,
        protected float $innerRadiusKm,
        protected float $outerRadiusKm,
    ) {
        parent::__construct(Incident::query(), $parent);
    }

    public function addConstraints(): void {}

    /** @param array<int, Neighborhood> $models */
    public function addEagerConstraints(array $models): void
    {
        $this->eagerDonutLoading = true;
    }

    /** @param array<int, Neighborhood> $models */
    public function initRelation(array $models, $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    /**
     * @param  array<int, Neighborhood>  $models
     * @param  EloquentCollection<int, Incident>  $results
     */
    public function match(array $models, EloquentCollection $results, $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->filterAndSort($model, $results));
        }

        $this->eagerDonutLoading = false;

        return $models;
    }

    public function getResults(): EloquentCollection
    {
        if (! $this->parent->exists) {
            return $this->related->newCollection();
        }

        return $this->get();
    }

    /**
     * @param  array<int, mixed|string>|string  $columns
     * @return EloquentCollection<int, Incident>
     */
    public function get($columns = ['*'])
    {
        /** @var EloquentCollection<int, Incident> $results */
        $results = $this->query->get($columns);

        if ($this->eagerDonutLoading) {
            return $results;
        }

        /** @var Neighborhood $parent */
        $parent = $this->parent;

        return $this->filterAndSort($parent, $results);
    }

    /**
     * @return EloquentCollection<int, Incident>
     */
    protected function filterAndSort(Neighborhood $neighborhood, EloquentCollection $incidents): EloquentCollection
    {
        $cLat = (float) $neighborhood->centroid_latitude;
        $cLng = (float) $neighborhood->centroid_longitude;

        $filtered = $incidents->filter(function (Incident $incident) use ($cLat, $cLng): bool {
            $d = $this->distanceKm($cLat, $cLng, $incident);

            return $d > $this->innerRadiusKm && $d <= $this->outerRadiusKm;
        });

        return $filtered->sortBy([
            fn (Incident $a, Incident $b): int => $this->distanceKm($cLat, $cLng, $a) <=> $this->distanceKm($cLat, $cLng, $b),
            fn (Incident $a, Incident $b): int => strnatcmp((string) $a->code, (string) $b->code),
        ])->values();
    }

    private function distanceKm(float $centroidLat, float $centroidLng, Incident $incident): float
    {
        return Haversine::distanceKm(
            $centroidLat,
            $centroidLng,
            (float) $incident->latitude,
            (float) $incident->longitude
        );
    }
}
