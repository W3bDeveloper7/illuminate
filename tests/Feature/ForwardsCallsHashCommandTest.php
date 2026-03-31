<?php

declare(strict_types=1);

use App\Services\LaravelForwardsCallsHasher;
use Illuminate\Support\Facades\Http;

it('hashes forwards calls trait body from the remote response', function (): void {
    Http::fake([
        'raw.githubusercontent.com/*' => Http::response("<?php\n// ForwardsCalls\n", 200),
    ]);

    $hasher = new LaravelForwardsCallsHasher;

    expect($hasher->md5ForTag('v10.14.0'))->toBe(md5("<?php\n// ForwardsCalls\n"));
});
