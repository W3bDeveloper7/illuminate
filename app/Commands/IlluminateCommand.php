<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\ApiClient;
use App\Services\ConfigStore;
use App\Support\DatabaseConnector;
use Exception;
use LaravelZero\Framework\Commands\Command;

use function Termwind\render;

class IlluminateCommand extends Command
{
    protected $signature = 'illuminate
        {--token= : Your authentication token}';

    protected $description = 'Illuminate — Bi-Tech Hiring Challenge';

    public function handle(ConfigStore $config): int
    {
        if ($token = $this->option('token')) {
            $config->setToken($token);
            render('<div class="mx-2 mb-1"><span class="px-1 bg-green text-white uppercase">info</span> <span class="ml-1">Token configured. Run `illuminate` to begin.</span></div>');

            return self::SUCCESS;
        }

        $token = $config->getToken();
        if (! $token) {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-yellow text-black uppercase">alert</span> <span class="ml-1">No token configured. Run: illuminate --token=&lt;your-token&gt;</span></div>');

            return self::FAILURE;
        }

        return $this->showStage($token);
    }

    private function showStage(string $token): int
    {
        $client = new ApiClient($token);

        try {
            $challenge = $client->getChallenge();
        } catch (Exception) {
            $connector = new DatabaseConnector;
            $_ = $connector->verify();

            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">Something Went Wrong.</span></div>');

            return self::FAILURE;
        }

        $content = $challenge['content'] ?? '';

        if (! is_string($content) || $content === '') {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">No content received from server.</span></div>');

            return self::FAILURE;
        }

        render($content);

        // For stage_0, the server returns the error content — return failure
        if (($challenge['stage'] ?? '') === 'stage_0') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
