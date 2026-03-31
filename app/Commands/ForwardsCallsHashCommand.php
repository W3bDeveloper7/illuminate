<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\ApiClient;
use App\Services\ConfigStore;
use App\Services\LaravelForwardsCallsHasher;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use function Termwind\render;

class ForwardsCallsHashCommand extends Command
{
    protected $signature = 'forwards-calls-hash
                            {--tag=v10.14.0 : Laravel framework git tag (e.g. v10.14.0)}
                            {--submit : Submit the MD5 hash as the current stage answer}';

    protected $description = 'MD5 of Illuminate\\Support\\Traits\\ForwardsCalls for a framework tag (Stage 1)';

    public function handle(ConfigStore $config, LaravelForwardsCallsHasher $hasher): int
    {
        $tag = (string) $this->option('tag');

        try {
            $md5 = $hasher->md5ForTag($tag);
        } catch (Throwable $e) {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">'.\e($e->getMessage()).'</span></div>');

            return self::FAILURE;
        }

        render('<div class="mx-2 mb-1"><span class="px-1 bg-blue text-white uppercase">md5</span> <span class="ml-1">'.$md5.'</span></div>');

        if (! $this->option('submit')) {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-gray text-white uppercase">hint</span> <span class="ml-1">Run <span>illuminate forwards-calls-hash --submit</span> to submit this answer.</span></div>');
        }

        if ($this->option('submit')) {
            $token = $config->getToken();
            if (! $token) {
                render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">No token configured. Run: illuminate --token=&lt;your-token&gt;</span></div>');

                return self::FAILURE;
            }

            $client = new ApiClient($token);
            $result = $client->submitAnswer($md5);

            $content = $result['content'] ?? '';
            if (is_string($content) && $content !== '') {
                render($content);
            }

            return ($result['status'] ?? '') === 'correct' ? self::SUCCESS : self::FAILURE;
        }

        return self::SUCCESS;
    }
}
