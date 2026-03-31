<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\ApiClient;
use App\Services\ChallengeCredentialStore;
use App\Services\ChallengeSshCredentials;
use App\Services\ConfigStore;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use function Termwind\render;

class FetchSshCredentialsCommand extends Command
{
    protected $signature = 'challenge:ssh-fetch';

    protected $description = 'Stage 2: fetch SSH private key and PostgreSQL credentials from the API';

    public function handle(ConfigStore $config): int
    {
        $token = $config->getToken();
        if (! $token) {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">No token configured. Run: illuminate --token=&lt;your-token&gt;</span></div>');

            return self::FAILURE;
        }

        try {
            $client = new ApiClient($token);
            $payload = $client->getChallengeSshKey();
            $credentials = ChallengeSshCredentials::fromApiPayload($payload);
            $store = ChallengeCredentialStore::default();
            $store->saveApiPayload($payload, $credentials);
        } catch (Throwable $e) {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">'.\e($e->getMessage()).'</span></div>');

            return self::FAILURE;
        }

        render('<div class="mx-2 mb-1"><span class="px-1 bg-green text-white uppercase">ok</span> <span class="ml-1">Saved under <span>'.\e($store->directory()).'</span></span></div>');
        render('<div class="mx-2 mb-1"><span class="ml-1">SSH: <span>'.\e($credentials->sshUser.'@'.$credentials->sshHost.':'.$credentials->sshPort).'</span></span></div>');
        render('<div class="mx-2 mb-1"><span class="ml-1">PostgreSQL (on server): <span>'.\e($credentials->remotePgHost.':'.$credentials->remotePgPort).' / '.\e($credentials->pgDatabase).' / user '.\e($credentials->pgUser).'</span></span></div>');
        render('<div class="mx-2 mb-1"><span class="px-1 bg-gray text-white uppercase">next</span> <span class="ml-1">Run <span>illuminate challenge:postgres-flag</span> (requires OpenSSH client + PHP pdo_pgsql).</span></div>');

        return self::SUCCESS;
    }
}
