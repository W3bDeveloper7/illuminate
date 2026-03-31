<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final readonly class ChallengeSshCredentials
{
    public function __construct(
        public string $privateKey,
        public string $sshUser,
        public string $sshHost,
        public int $sshPort,
        public string $remotePgHost,
        public int $remotePgPort,
        public string $pgDatabase,
        public string $pgUser,
        public string $pgPassword,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromApiPayload(array $data): self
    {
        $data = self::unwrapCredentialEnvelope($data);

        $key = self::extractPrivateKey($data);
        if ($key === null || $key === '') {
            throw new InvalidArgumentException(
                'API payload is missing a recognizable private key (named fields and PEM/OpenSSH deep scan).'
            );
        }

        $db = self::databaseSection($data);

        $pgUser = self::stringFrom($db, ['username', 'user', 'pgsql_user', 'db_user']) ?? self::stringFrom($data, ['pg_user', 'db_username']);
        $pgPassword = self::stringFrom($db, ['password', 'pgsql_password', 'db_password']) ?? self::stringFrom($data, ['pg_password', 'db_password']);
        $pgDatabase = self::stringFrom($db, ['database', 'dbname', 'name', 'db']) ?? self::stringFrom($data, ['pg_database', 'database']);

        if ($pgUser === null || $pgUser === '') {
            throw new InvalidArgumentException('API payload is missing PostgreSQL username.');
        }
        if ($pgPassword === null) {
            $pgPassword = '';
        }
        if ($pgDatabase === null || $pgDatabase === '') {
            throw new InvalidArgumentException('API payload is missing PostgreSQL database name.');
        }

        $sshBlock = self::arrayFrom($data['ssh'] ?? null);

        $sshUser = self::stringFrom($sshBlock, ['user', 'username', 'ssh_user', 'ssh_username'])
            ?? self::stringFrom($data, ['ssh_user', 'ssh_username', 'user', 'username']);
        if ($sshUser === null || $sshUser === '') {
            throw new InvalidArgumentException('API payload is missing SSH username.');
        }

        $sshHost = self::stringFrom($sshBlock, ['host', 'hostname'])
            ?? self::stringFrom($data, ['ssh_host', 'hostname', 'host'])
            ?? 'illuminate.bitech.com.sa';

        $sshPort = self::intFrom($sshBlock, ['port', 'ssh_port'])
            ?? self::intFrom($data, ['ssh_port', 'port'])
            ?? 22;

        $remotePgHost = self::stringFrom($db, ['host', 'hostname', 'pgsql_host']) ?? '127.0.0.1';
        $remotePgPort = self::intFrom($db, ['port', 'pgsql_port']) ?? 5432;

        return new self(
            privateKey: $key,
            sshUser: $sshUser,
            sshHost: $sshHost,
            sshPort: $sshPort,
            remotePgHost: $remotePgHost,
            remotePgPort: $remotePgPort,
            pgDatabase: $pgDatabase,
            pgUser: $pgUser,
            pgPassword: $pgPassword,
        );
    }

    /**
     * Unwrap common API shapes: { "data": { ... } }, { "payload": { ... } }.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function unwrapCredentialEnvelope(array $data): array
    {
        foreach (['data', 'payload', 'attributes', 'result', 'credentials', 'ssh_key_bundle'] as $wrap) {
            if (! isset($data[$wrap]) || ! is_array($data[$wrap])) {
                continue;
            }
            /** @var array<string, mixed> $inner */
            $inner = $data[$wrap];
            if (self::innerLooksLikeSshPayload($inner)) {
                return $inner;
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $inner
     */
    private static function innerLooksLikeSshPayload(array $inner): bool
    {
        if (isset($inner['private_key']) || isset($inner['ssh_private_key']) || isset($inner['key'])
            || isset($inner['identity']) || isset($inner['database']) || isset($inner['postgres'])
            || isset($inner['pgsql']) || isset($inner['db']) || isset($inner['ssh'])) {
            return true;
        }

        return self::findPrivateKeyMaterialInTree($inner) !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function extractPrivateKey(array $data): ?string
    {
        $namedKeys = [
            'private_key', 'ssh_private_key', 'privateKey', 'key', 'identity', 'ssh_key', 'pem',
            'private', 'secret', 'material', 'key_material', 'ssh_private', 'ed25519_key', 'rsa_key',
        ];

        $candidates = [
            self::stringFrom($data, $namedKeys),
            isset($data['data']) && is_array($data['data']) ? self::stringFrom($data['data'], ['private_key', 'key', 'ssh_key', 'pem']) : null,
        ];

        foreach ($candidates as $raw) {
            if ($raw === null || $raw === '') {
                continue;
            }
            $decoded = self::maybeBase64Decode($raw);
            if (self::stringLooksLikePrivateKeyPem($decoded)) {
                return $decoded;
            }
        }

        $fromTree = self::findPrivateKeyMaterialInTree($data);

        return $fromTree !== null ? self::maybeBase64Decode($fromTree) : null;
    }

    /**
     * Depth-first search for any string that looks like an SSH/PEM private key.
     *
     * @param  array<string, mixed>  $data
     */
    private static function findPrivateKeyMaterialInTree(array $data): ?string
    {
        foreach ($data as $value) {
            $found = self::findPrivateKeyMaterialInValue($value);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private static function findPrivateKeyMaterialInValue(mixed $value): ?string
    {
        if (is_string($value)) {
            $decoded = self::maybeBase64Decode($value);

            return self::stringLooksLikePrivateKeyPem($decoded) ? $decoded : null;
        }

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return self::findPrivateKeyMaterialInTree($value);
    }

    private static function stringLooksLikePrivateKeyPem(string $s): bool
    {
        $t = trim($s);
        if ($t === '') {
            return false;
        }

        return (str_contains($t, 'BEGIN') && str_contains($t, 'PRIVATE KEY'))
            || str_contains($t, 'BEGIN RSA PRIVATE KEY')
            || str_contains($t, 'BEGIN EC PRIVATE KEY')
            || str_contains($t, 'BEGIN OPENSSH PRIVATE KEY');
    }

    private static function maybeBase64Decode(string $raw): string
    {
        $trim = trim($raw);
        if (str_contains($trim, 'BEGIN') && str_contains($trim, 'KEY')) {
            return $raw;
        }
        $bin = base64_decode($trim, true);
        if ($bin !== false && str_contains($bin, 'BEGIN') && str_contains($bin, 'KEY')) {
            return $bin;
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function databaseSection(array $data): array
    {
        foreach (['database', 'postgres', 'pgsql', 'db', 'postgresql'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                /** @var array<string, mixed> */
                return $data[$k];
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private static function stringFrom(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $v = $data[$key];
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private static function intFrom(array $data, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $v = $data[$key];
            if (is_int($v)) {
                return $v;
            }
            if (is_string($v) && ctype_digit($v)) {
                return (int) $v;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function arrayFrom(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
