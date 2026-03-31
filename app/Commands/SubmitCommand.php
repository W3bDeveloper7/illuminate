<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\ApiClient;
use App\Services\ConfigStore;
use LaravelZero\Framework\Commands\Command;

use function Termwind\render;

class SubmitCommand extends Command
{
    protected $signature = 'submit {answer : Your answer for the current stage}';

    protected $description = 'Submit an answer for the current challenge stage';

    public function handle(ConfigStore $config): int
    {
        $token = $config->getToken();
        if (! $token) {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">No token configured. Run: illuminate --token=&lt;your-token&gt;</span></div>');

            return self::FAILURE;
        }

        $client = new ApiClient($token);
        $result = $client->submitAnswer($this->argument('answer'));

        $content = $result['content'] ?? '';

        if (is_string($content) && $content !== '') {
            render($content);
        }

        return ($result['status'] ?? '') === 'correct' ? self::SUCCESS : self::FAILURE;
    }
}
