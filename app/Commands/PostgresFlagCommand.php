<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\ApiClient;
use App\Services\ChallengeCredentialStore;
use App\Services\ChallengeSshCredentials;
use App\Services\ConfigStore;
use App\Services\PostgresFlagScanner;
use App\Services\SshPostgresTunnel;
use LaravelZero\Framework\Commands\Command;
use PDO;
use Throwable;

use function Termwind\render;

class PostgresFlagCommand extends Command
{
    protected $signature = 'challenge:postgres-flag
                            {--fresh : Fetch credentials from the API before connecting}
                            {--submit : Submit the discovered flag}';

    protected $description = 'Stage 2: SSH tunnel to PostgreSQL and search for the challenge flag';

    public function handle(
        ConfigStore $config,
        SshPostgresTunnel $tunnel,
        PostgresFlagScanner $scanner,
    ): int {
        if (! extension_loaded('pdo_pgsql')) {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">PHP extension <span>pdo_pgsql</span> is required. Enable it in php.ini (e.g. uncomment <span>extension=pdo_pgsql</span>).</span></div>');

            return self::FAILURE;
        }

        $store = ChallengeCredentialStore::default();

        try {
            if ($this->option('fresh')) {
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
        $flag = null;

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
                PDO::ATTR_TIMEOUT => 20,
            ]);

            $flag = $scanner->findFlag($pdo);
        } catch (Throwable $e) {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">'.\e($e->getMessage()).'</span></div>');

            return self::FAILURE;
        } finally {
            if ($process !== null && $process->isRunning()) {
                $process->stop(10);
            }
        }

        if ($flag === null || $flag === '') {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-yellow text-black uppercase">warn</span> <span class="ml-1">No flag pattern found (scanned non-system tables, views, matviews). Use psql over the same SSH tunnel to inspect schemas, or run with <span>--fresh</span> after re-fetching credentials.</span></div>');

            return self::FAILURE;
        }

        render('<div class="mx-2 mb-1"><span class="px-1 bg-green text-white uppercase">flag</span> <span class="ml-1">'.\e($flag).'</span></div>');

        if (! $this->option('submit')) {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-gray text-white uppercase">hint</span> <span class="ml-1">Run <span>illuminate submit '.\e($flag).'</span> or pass <span>--submit</span>.</span></div>');
        }

        if ($this->option('submit')) {
            $token = $config->getToken();
            if (! $token) {
                render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">No token configured. Run: illuminate --token=&lt;your-token&gt;</span></div>');

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
