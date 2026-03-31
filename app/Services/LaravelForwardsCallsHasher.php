<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class LaravelForwardsCallsHasher
{
    private const TRAIT_PATH = 'src/Illuminate/Support/Traits/ForwardsCalls.php';

    public function __construct(private readonly int $timeoutSeconds = 30) {}

    public function md5ForTag(string $tag = 'v10.14.0'): string
    {
        $url = sprintf(
            'https://raw.githubusercontent.com/laravel/framework/%s/%s',
            rawurlencode($tag),
            self::TRAIT_PATH
        );

        $response = Http::timeout($this->timeoutSeconds)->get($url);

        if (! $response->successful()) {
            throw new RuntimeException(
                sprintf('Failed to fetch trait source (HTTP %d).', $response->status())
            );
        }

        return md5($response->body());
    }
}
