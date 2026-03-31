<?php

declare(strict_types=1);

namespace App\Services;

class ConfigStore
{
    private string $configDir;

    private string $configFile;

    public function __construct()
    {
        $home = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? '';
        $this->configDir = (is_string($home) ? $home : '').'/.illuminate';
        $this->configFile = $this->configDir.'/config.json';
    }

    public function getToken(): ?string
    {
        $value = $this->get('token');

        return is_string($value) ? $value : null;
    }

    public function setToken(string $token): void
    {
        $this->set('token', $token);
    }

    public function get(string $key): mixed
    {
        $config = $this->load();

        return $config[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $config = $this->load();
        $config[$key] = $value;
        $this->save($config);
    }

    public function configDir(): string
    {
        return $this->configDir;
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        if (! file_exists($this->configFile)) {
            return [];
        }

        $content = file_get_contents($this->configFile);
        if ($content === false) {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $config */
    private function save(array $config): void
    {
        if (! is_dir($this->configDir)) {
            mkdir($this->configDir, 0700, true);
        }

        file_put_contents($this->configFile, json_encode($config, JSON_PRETTY_PRINT));
    }
}
