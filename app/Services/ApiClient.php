<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ApiClient
{
    private string $baseUrl;

    public function __construct(private readonly string $token)
    {
        /** @var string $baseUrl */
        $baseUrl = config('illuminate.api_base_url');
        $this->baseUrl = $baseUrl;
    }

    /** @return array<string, mixed> */
    public function getChallenge(): array
    {
        $response = Http::withToken($this->token)
            ->get("{$this->baseUrl}/challenge");

        $response->throw();

        /** @var array<string, mixed> */
        return $response->json();
    }

    /** @return array<string, mixed> */
    public function submitAnswer(string $answer): array
    {
        $response = Http::withToken($this->token)
            ->post("{$this->baseUrl}/challenge/submit", [
                'answer' => $answer,
            ]);

        $response->throw();

        /** @var array<string, mixed> */
        return $response->json();
    }

    /**
     * Stage 2: SSH key + database credentials for the tunnel challenge.
     *
     * @return array<string, mixed>
     */
    public function getChallengeSshKey(): array
    {
        $response = Http::withToken($this->token)
            ->get("{$this->baseUrl}/challenge/ssh-key");

        $response->throw();

        /** @var array<string, mixed> */
        $json = $response->json();

        return $json;
    }

    /**
     * Final submission: repo URL + CV PDF.
     *
     * @return array<string, mixed>
     */
    public function submitRepo(string $repoUrl, string $cvPdfPath): array
    {
        $response = Http::withToken($this->token)
            ->attach(
                name: 'cv',
                contents: file_get_contents($cvPdfPath),
                filename: basename($cvPdfPath),
            )
            ->post("{$this->baseUrl}/challenge/submit-repo", [
                'repo_url' => $repoUrl,
            ]);

        $response->throw();

        /** @var array<string, mixed> */
        return $response->json();
    }
}
