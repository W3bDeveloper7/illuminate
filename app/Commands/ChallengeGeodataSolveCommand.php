<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\Neighborhood;
use App\Services\ApiClient;
use App\Services\ConfigStore;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use function Termwind\render;

class ChallengeGeodataSolveCommand extends Command
{
    protected $signature = 'challenge:geodata-solve
                            {--code= : Neighborhood code (default NB-7A2F from config)}
                            {--inner= : Inner donut radius km (default 0.5)}
                            {--outer= : Outer donut radius km (default 2.0)}
                            {--submit : Submit the concatenated incident codes via the API}';

    protected $description = 'Stage 3: NB-7A2F donut (0.5–2 km), incidents ordered by increasing distance from centroid; concat codes → illuminate submit <flag>';

    public function handle(ConfigStore $config): int
    {
        if (! extension_loaded('pdo_sqlite')) {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">PHP extension pdo_sqlite is required.</span></div>');

            return self::FAILURE;
        }

        $code = trim((string) ($this->option('code') ?: config('challenge_geodata.neighborhood_code', 'NB-7A2F')));
        $needle = strtolower($code);

        try {
            /** @var Neighborhood|null $neighborhood */
            $neighborhood = Neighborhood::query()
                ->where(function ($q) use ($needle): void {
                    $q->whereRaw('LOWER(TRIM(code)) = ?', [$needle])
                        ->orWhereRaw('LOWER(TRIM(id)) = ?', [$needle]);
                })
                ->first();
        } catch (Throwable $e) {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">'.\e($e->getMessage()).'</span></div>');

            return self::FAILURE;
        }

        if ($neighborhood === null) {
            $codes = Neighborhood::query()->orderBy('code')->limit(40)->pluck('code')->all();
            $ids = Neighborhood::query()->orderBy('id')->limit(40)->pluck('id')->all();
            $codesText = $codes === [] ? '(no rows in neighborhoods table — import may have failed)' : implode(', ', array_map(static fn (mixed $c): string => (string) $c, $codes));
            $idsText = $ids === [] ? '(none)' : implode(', ', array_map(static fn (mixed $i): string => (string) $i, $ids));

            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">Neighborhood not found for '.\e($code).' (matched code or id case-insensitively). Run challenge:geodata-import and check counts.</span></div>');
            render('<div class="mx-2 mb-1"><span class="ml-1">Stored codes: '.$codesText.'</span></div>');
            render('<div class="mx-2 mb-1"><span class="ml-1">Stored ids: '.$idsText.'</span></div>');

            return self::FAILURE;
        }

        $innerOpt = $this->option('inner');
        $outerOpt = $this->option('outer');
        if ($innerOpt !== null || $outerOpt !== null) {
            config([
                'challenge_geodata.donut_inner_km' => (float) ($innerOpt ?? config('challenge_geodata.donut_inner_km')),
                'challenge_geodata.donut_outer_km' => (float) ($outerOpt ?? config('challenge_geodata.donut_outer_km')),
            ]);
        }

        $innerKm = (float) config('challenge_geodata.donut_inner_km');
        $outerKm = (float) config('challenge_geodata.donut_outer_km');

        $neighborhood->unsetRelation('incidents');
        $incidents = $neighborhood->incidents;

        $flag = $incidents->pluck('code')->implode('');

        render('<div class="mx-2 mb-1"><span class="px-1 bg-blue text-white uppercase">info</span> <span class="ml-1">Matches: '.$incidents->count().' incidents (annulus '.$innerKm.'–'.$outerKm.' km from centroid, sorted by increasing distance).</span></div>');

        if ($flag === '') {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-yellow text-black uppercase">warn</span> <span class="ml-1">No incidents in the donut. Check radii, data import, and neighborhood code.</span></div>');

            return self::FAILURE;
        }

        render('<div class="mx-2 mb-1"><span class="px-1 bg-green text-white uppercase">flag</span> <span class="ml-1">'.\e($flag).'</span></div>');

        $flagLen = strlen($flag);
        $flagCrc = hash('crc32b', $flag);
        render('<div class="mx-2 mb-1"><span class="px-1 bg-blue text-white uppercase">info</span> <span class="ml-1">Paste must match exactly: length '.\e((string) $flagLen).', crc32b '.\e($flagCrc).' (wrong length or digits ⇒ submit returns Incorrect).</span></div>');

        if ($this->option('submit')) {
            $token = $config->getToken();
            if (! $token) {
                render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">No token configured.</span></div>');

                return self::FAILURE;
            }

            $client = new ApiClient($token);
            $result = $client->submitAnswer($flag);

            $content = $result['content'] ?? '';
            if (is_string($content) && $content !== '') {
                render($content);
            }

            return ($result['status'] ?? '') === 'correct' ? self::SUCCESS : self::FAILURE;
        }

        return self::SUCCESS;
    }
}
