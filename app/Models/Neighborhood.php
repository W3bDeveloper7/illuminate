<?php

declare(strict_types=1);

namespace App\Models;

use App\Relations\DonutIncidentsRelation;
use Illuminate\Database\Eloquent\Model;

class Neighborhood extends Model
{
    protected $connection = 'sqlite';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'code',
        'centroid_latitude',
        'centroid_longitude',
        'raw_boundary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'centroid_latitude' => 'float',
            'centroid_longitude' => 'float',
        ];
    }

    /**
     * Challenge requirement: custom relation class (not a built-in hasMany / belongsTo, etc.).
     */
    public function incidents(): DonutIncidentsRelation
    {
        return new DonutIncidentsRelation(
            $this,
            (float) config('challenge_geodata.donut_inner_km', 0.5),
            (float) config('challenge_geodata.donut_outer_km', 2.0),
        );
    }
}
