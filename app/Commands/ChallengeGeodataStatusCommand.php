<?php

declare(strict_types=1);

namespace App\Commands;

use Illuminate\Support\Facades\DB;
use LaravelZero\Framework\Commands\Command;

use function Termwind\render;

class ChallengeGeodataStatusCommand extends Command
{
    protected $signature = 'challenge:geodata-status';

    protected $description = 'Show resolved SQLite path and row counts for Stage 3 geodata';

    public function handle(): int
    {
        $path = (string) config('database.connections.sqlite.database');
        $exists = is_file($path);
        $readable = $exists && is_readable($path);

        $n = 0;
        $i = 0;
        if ($readable) {
            try {
                $n = (int) DB::connection('sqlite')->table('neighborhoods')->count();
                $i = (int) DB::connection('sqlite')->table('incidents')->count();
            } catch (\Throwable) {
                // Tables may not exist yet.
            }
        }

        render('<div class="mx-2 mb-1"><span class="px-1 bg-gray text-white uppercase">sqlite</span> <span class="ml-1">'.\e($path).'</span></div>');
        render('<div class="mx-2 mb-1"><span class="px-1 bg-gray text-white uppercase">file</span> <span class="ml-1">'
            .($exists ? 'exists' : 'missing')
            .($readable ? ', readable' : '')
            .'</span></div>');
        render('<div class="mx-2 mb-1"><span class="px-1 bg-gray text-white uppercase">rows</span> <span class="ml-1">neighborhoods: '.$n.', incidents: '.$i.'</span></div>');
        render('<div class="mx-2 mb-1"><span class="px-1 bg-gray text-white uppercase">env</span> <span class="ml-1">ILLUMINATE_GEODATA_SQLITE overrides the default path.</span></div>');

        return self::SUCCESS;
    }
}
