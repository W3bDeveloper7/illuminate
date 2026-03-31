<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations'));

        // Laravel Zero's database component merges config as array_merge(app file, foundation),
        // so foundation's sqlite path (e.g. database/database.sqlite) wins over config/database.php.
        // Stage 3 must always use the challenge SQLite file unless overridden explicitly.
        $geodataSqlite = env('ILLUMINATE_GEODATA_SQLITE', storage_path('app/challenge_geodata.sqlite'));
        config([
            'database.connections.sqlite.database' => $geodataSqlite,
        ]);
    }

    /**
     * Register any application services.
     */
    public function register(): void {}
}
