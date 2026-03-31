<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\ApiClient;
use App\Services\ConfigStore;
use Illuminate\Http\Client\RequestException;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use function Termwind\render;

final class ChallengeSubmitRepoCommand extends Command
{
    protected $signature = 'challenge:submit-repo
                            {--repo-url= : Git repository URL (defaults to https://github.com/W3bDeveloper7/illuminate.git)}
                            {--cv= : Path to CV PDF (defaults to storage/app/cv/Ahmed_Adel_Mahmoud.pdf)}';

    protected $description = 'Final submission: upload GitHub repo URL + CV PDF to the challenge API';

    public function handle(ConfigStore $config): int
    {
        $token = $config->getToken();
        if (! $token) {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">No token configured. Run: <span>illuminate --token=&lt;your-token&gt;</span></span></div>');

            return self::FAILURE;
        }

        $repoUrl = trim((string) ($this->option('repo-url') ?: 'https://github.com/W3bDeveloper7/illuminate.git'));
        $cvOpt = (string) ($this->option('cv') ?: storage_path('app/cv/Ahmed_Adel_Mahmoud.pdf'));
        $cvPath = $this->normalizePath($cvOpt);

        if ($repoUrl === '') {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">Missing <span>--repo-url</span>.</span></div>');

            return self::FAILURE;
        }

        if (! is_file($cvPath) || ! is_readable($cvPath)) {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">CV file not found/readable: <span>' . \e($cvPath) . '</span></span></div>');

            return self::FAILURE;
        }

        if (strtolower(pathinfo($cvPath, PATHINFO_EXTENSION)) !== 'pdf') {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-yellow text-black uppercase">warn</span> <span class="ml-1">CV is not a <span>.pdf</span>: <span>' . \e($cvPath) . '</span></span></div>');
        }

        $client = new ApiClient($token);

        try {
            $result = $client->submitRepo($repoUrl, $cvPath);
        } catch (RequestException $e) {
            $body = (string) $e->response?->body();
            $msg = $body !== '' ? $body : $e->getMessage();
            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">' . \e($msg) . '</span></div>');

            return self::FAILURE;
        } catch (Throwable $e) {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-red text-white uppercase">error</span> <span class="ml-1">' . \e($e->getMessage()) . '</span></div>');

            return self::FAILURE;
        }

        $content = $result['content'] ?? '';
        if (is_string($content) && $content !== '') {
            render($content);
        } else {
            render('<div class="mx-2 mb-1"><span class="px-1 bg-green text-white uppercase">ok</span> <span class="ml-1">Submission request completed.</span></div>');
        }

        return ($result['status'] ?? '') === 'correct' ? self::SUCCESS : self::SUCCESS;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return $path;
        }

        // Allow users to pass "@storage/..." (Cursor-style file reference).
        if ($path[0] === '@') {
            $path = substr($path, 1);
        }

        return $path;
    }
}
