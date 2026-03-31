<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\ApiClient;
use App\Services\ChallengeCredentialStore;
use App\Services\ChallengeGeodataImporter;
use App\Services\ChallengeSshCredentials;
use App\Services\ConfigStore;
use App\Services\SshPostgresTunnel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LaravelZero\Framework\Commands\Command;
use PDO;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

use function Termwind\render;
use function Termwind\renderUsing;

class ChallengeGeodataImportCommand extends Command
{
    protected $signature = 'challenge:geodata-import
                            {--fresh-credentials : Fetch SSH/DB credentials from the API before connecting}';

    protected $description = 'Stage 3 (1/2): Import remote PostgreSQL into local SQLite using Neighborhood & Incident Eloquent models (uses Stage 2 SSH tunnel)';

    public function handle(
        ConfigStore $config,
        SshPostgresTunnel $tunnel,
        ChallengeGeodataImporter $importer,
    ): int {
        if (! extension_loaded('pdo_pgsql')) {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">PHP extension pdo_pgsql is required for import.</span></div>');

            return self::FAILURE;
        }

        if (! extension_loaded('pdo_sqlite')) {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">PHP extension pdo_sqlite is required for local storage.</span></div>');

            return self::FAILURE;
        }

        $sqlitePath = (string) config('database.connections.sqlite.database');
        $dir = dirname($sqlitePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        if (! file_exists($sqlitePath)) {
            touch($sqlitePath);
        }

        $migrateOutput = new BufferedOutput;
        $code = Artisan::call('migrate:fresh', ['--force' => true], $migrateOutput);
        // Kernel::call() points Termwind at BufferedOutput; restore console or later render() is invisible.
        if (function_exists('Termwind\renderUsing')) {
            renderUsing($this->output);
        }
        if ($code !== 0) {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">'.\e($migrateOutput->fetch()).'</span></div>');

            return self::FAILURE;
        }

        $store = ChallengeCredentialStore::default();

        try {
            if ($this->option('fresh-credentials')) {
                $token = $config->getToken();
                if (! $token) {
                    render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">No token configured. Run: illuminate --token=&lt;your-token&gt;</span></div>');

                    return self::FAILURE;
                }
                $client = new ApiClient($token);
                $payload = $client->getChallengeSshKey();
                $credentials = ChallengeSshCredentials::fromApiPayload($payload);
                $store->saveApiPayload($payload, $credentials);
            } else {
                $credentials = $store->loadCredentials();
            }
        } catch (Throwable $e) {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">'.\e($e->getMessage()).'</span></div>');

            return self::FAILURE;
        }

        $identityPath = $store->identityFilePath();
        $localPort = $tunnel->allocateLocalPort();
        $process = null;

        try {
            $process = $tunnel->start($identityPath, $credentials, $localPort);

            $dsn = sprintf(
                'pgsql:host=127.0.0.1;port=%d;dbname=%s',
                $localPort,
                $credentials->pgDatabase
            );

            $pdo = new PDO($dsn, $credentials->pgUser, $credentials->pgPassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 60,
            ]);

            $stats = $importer->importFromPostgres($pdo);
        } catch (Throwable $e) {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">'.\e($e->getMessage()).'</span></div>');

            return self::FAILURE;
        } finally {
            if ($process !== null && $process->isRunning()) {
                $process->stop(10);
            }
        }

        $sampleCodes = DB::connection('sqlite')->table('neighborhoods')
            ->orderBy('code')
            ->limit(25)
            ->pluck('code')
            ->all();
        $sampleText = $sampleCodes === [] ? '(none)' : implode(', ', array_map(static fn (mixed $c): string => (string) $c, $sampleCodes));

        render('<div class="mx-2 mb-1"><span class="px-1 bg-green text-white uppercase">ok</span> <span class="ml-1">Imported into SQLite at '.\e($sqlitePath).' — neighborhoods: '.$stats['neighborhoods'].', incidents: '.$stats['incidents'].'.</span></div>');
        render('<div class="mx-2 mb-1"><span class="px-1 bg-gray text-white uppercase">hint</span> <span class="ml-1">Sample neighborhood codes: '.$sampleText.'</span></div>');

        return self::SUCCESS;
    }
}
