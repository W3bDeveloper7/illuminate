<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

class PostgresFlagScanner
{
    private const ROW_LIMIT = 5000;

    /**
     * Search user tables and views (all non-system schemas) for a challenge flag.
     */
    public function findFlag(PDO $pdo): ?string
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $relations = $this->listRelations($pdo);
        usort($relations, fn (array $a, array $b): int => $this->relationPriority($a) <=> $this->relationPriority($b));

        foreach ($relations as ['schema' => $schema, 'name' => $name]) {
            if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $schema)
                || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
                continue;
            }

            $quotedSchema = '"'.str_replace('"', '""', $schema).'"';
            $quotedName = '"'.str_replace('"', '""', $name).'"';
            $fqn = $quotedSchema.'.'.$quotedName;

            try {
                $stmt = $pdo->query('SELECT * FROM '.$fqn.' LIMIT '.self::ROW_LIMIT);
            } catch (\Throwable) {
                continue;
            }

            if ($stmt === false) {
                continue;
            }

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (! is_array($row)) {
                    continue;
                }
                foreach ($row as $value) {
                    $flag = $this->findFlagInCell($value);
                    if ($flag !== null) {
                        return $flag;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return list<array{schema: string, name: string}>
     */
    private function listRelations(PDO $pdo): array
    {
        $sql = <<<'SQL'
            SELECT schemaname AS schema, tablename AS name
            FROM pg_tables
            WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
            UNION
            SELECT schemaname AS schema, viewname AS name
            FROM pg_views
            WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
            UNION
            SELECT schemaname AS schema, matviewname AS name
            FROM pg_matviews
            WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
            ORDER BY schema, name
            SQL;

        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            throw new RuntimeException('Could not list PostgreSQL relations.');
        }

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (! is_array($row) || ! isset($row['schema'], $row['name'])
                || ! is_string($row['schema']) || ! is_string($row['name'])) {
                continue;
            }
            $out[] = ['schema' => $row['schema'], 'name' => $row['name']];
        }

        return $out;
    }

    /**
     * Lower sort key = scanned first (more likely to hold a flag).
     *
     * @param  array{schema: string, name: string}  $rel
     */
    private function relationPriority(array $rel): int
    {
        $hay = strtolower($rel['schema'].' '.$rel['name']);
        $p = 100;
        foreach (['flag', 'secret', 'challenge', 'ctf', 'stage', 'key', 'token'] as $needle) {
            if (str_contains($hay, $needle)) {
                $p -= 15;
            }
        }

        return $p;
    }

    private function findFlagInCell(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_resource($value)) {
            $value = stream_get_contents($value);
            if ($value === false) {
                return null;
            }
        }

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (is_string($value)) {
            $flag = $this->matchFlag($value);
            if ($flag !== null) {
                return $flag;
            }
            $trim = trim($value);
            if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
                $decoded = json_decode($trim, true);
                if (is_array($decoded)) {
                    return $this->findFlagInArray($decoded);
                }
            }

            return null;
        }

        if (is_array($value)) {
            return $this->findFlagInArray($value);
        }

        return null;
    }

    /**
     * @param  array<mixed>  $arr
     */
    private function findFlagInArray(array $arr): ?string
    {
        foreach ($arr as $v) {
            $found = $this->findFlagInCell($v);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function matchFlag(string $haystack): ?string
    {
        $patterns = [
            '/ILLUMINATE\{[^}]+\}/i',
            '/BITECH\{[^}]+\}/i',
            '/FLAG\{[^}]+\}/i',
            '/CTF\{[^}]+\}/i',
            '/HTB\{[^}]+\}/i',
            '/CHALLENGE\{[^}]+\}/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $haystack, $m)) {
                return $m[0];
            }
        }

        return null;
    }
}
