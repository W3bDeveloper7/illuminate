<?php

declare(strict_types=1);

use App\Services\ChallengeSshCredentials;

it('parses nested ssh and database blocks', function (): void {
    $key = <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
abc
-----END OPENSSH PRIVATE KEY-----
KEY;

    $c = ChallengeSshCredentials::fromApiPayload([
        'ssh' => [
            'user' => 'ubuntu',
            'host' => 'illuminate.bitech.com.sa',
            'port' => 22,
        ],
        'database' => [
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'challenge',
            'username' => 'app',
            'password' => 'secret',
        ],
        'private_key' => $key,
    ]);

    expect($c->sshUser)->toBe('ubuntu')
        ->and($c->sshHost)->toBe('illuminate.bitech.com.sa')
        ->and($c->pgDatabase)->toBe('challenge')
        ->and($c->pgUser)->toBe('app')
        ->and($c->pgPassword)->toBe('secret')
        ->and($c->remotePgHost)->toBe('127.0.0.1')
        ->and($c->remotePgPort)->toBe(5432);
});

it('defaults ssh host and postgres bind when omitted', function (): void {
    $c = ChallengeSshCredentials::fromApiPayload([
        'ssh_user' => 'deploy',
        'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nx\n-----END OPENSSH PRIVATE KEY-----\n",
        'database' => [
            'username' => 'u',
            'password' => 'p',
            'database' => 'db',
        ],
    ]);

    expect($c->sshHost)->toBe('illuminate.bitech.com.sa')
        ->and($c->remotePgHost)->toBe('127.0.0.1')
        ->and($c->remotePgPort)->toBe(5432);
});

it('unwraps a nested data envelope', function (): void {
    $key = "-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----\n";

    $c = ChallengeSshCredentials::fromApiPayload([
        'data' => [
            'private_key' => $key,
            'ssh_user' => 'ubuntu',
            'database' => [
                'username' => 'app',
                'password' => 'x',
                'database' => 'flags',
            ],
        ],
    ]);

    expect($c->sshUser)->toBe('ubuntu')
        ->and($c->pgDatabase)->toBe('flags');
});

it('finds a private key under an arbitrary nested field name', function (): void {
    $key = "-----BEGIN OPENSSH PRIVATE KEY-----\nxyz\n-----END OPENSSH PRIVATE KEY-----\n";

    $c = ChallengeSshCredentials::fromApiPayload([
        'meta' => ['request_id' => 'abc'],
        'bundle' => [
            'nested' => ['custom_ssh_material' => $key],
        ],
        'ssh_user' => 'deploy',
        'database' => [
            'username' => 'u',
            'password' => 'p',
            'database' => 'db',
        ],
    ]);

    expect($c->privateKey)->toContain('OPENSSH PRIVATE KEY')
        ->and($c->sshUser)->toBe('deploy');
});
