<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use JsonException;

class ChallengeCredentialStore
{
    public function __construct(private readonly string $basePath) {}

    public static function default(): self
    {
        $configDir = (new ConfigStore)->configDir();

        return new self($configDir.'/challenge');
    }

    public function directory(): string
    {
        return $this->basePath;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveApiPayload(array $payload, ChallengeSshCredentials $credentials): void
    {
        if (! is_dir($this->basePath)) {
            mkdir($this->basePath, 0700, true);
        }

        $identityPath = $this->identityPath();
        file_put_contents($identityPath, self::normalizeKeyMaterial($credentials->privateKey));
        chmod($identityPath, 0600);

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        file_put_contents($this->payloadPath(), $encoded);
    }

    public function loadCredentials(): ChallengeSshCredentials
    {
        if (! is_file($this->payloadPath())) {
            throw new InvalidArgumentException('No saved challenge payload. Run: illuminate challenge:ssh-fetch');
        }

        $json = file_get_contents($this->payloadPath());
        if ($json === false) {
            throw new InvalidArgumentException('Could not read saved challenge payload.');
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Invalid saved challenge payload JSON: '.$e->getMessage(), 0, $e);
        }

        $credentials = ChallengeSshCredentials::fromApiPayload($data);

        $identityPath = $this->identityPath();
        file_put_contents($identityPath, self::normalizeKeyMaterial($credentials->privateKey));
        chmod($identityPath, 0600);

        return $credentials;
    }

    public function identityFilePath(): string
    {
        return $this->identityPath();
    }

    private function payloadPath(): string
    {
        return $this->basePath.'/api_payload.json';
    }

    private function identityPath(): string
    {
        return $this->basePath.'/ssh_identity';
    }

    private static function normalizeKeyMaterial(string $key): string
    {
        $key = rtrim($key);

        return $key."\n";
    }
}
