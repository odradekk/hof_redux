<?php

declare(strict_types=1);

namespace App\Domain\Content;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Immutable, versioned, non-executable game definitions. */
final class ContentCatalog
{
    private array $manifest;

    private array $catalogs = [];

    public function __construct(private readonly string $directory)
    {
        $this->manifest = $this->read('manifest');
        if (($this->manifest['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('Unsupported content schema');
        }
    }

    public function version(): string
    {
        return $this->manifest['content_version'];
    }

    public function kinds(): array
    {
        return array_keys($this->manifest['counts']);
    }

    public function all(string $kind): array
    {
        $this->load($kind);

        return array_map(static fn (array $row): array => $row['data'], $this->catalogs[$kind]);
    }

    public function playableSkills(): array
    {
        $this->load('skills');
        $rows = array_filter($this->catalogs['skills'], static fn (array $row): bool => ($row['availability'] ?? 'playable') !== 'catalog_only');

        return array_map(static fn (array $row): array => $row['data'], $rows);
    }

    public function playableMonsters(): array
    {
        $this->load('monsters');
        $rows = array_filter($this->catalogs['monsters'], static fn (array $row): bool => ($row['availability'] ?? 'playable') !== 'catalog_only' && ! ($row['data']['preview_only'] ?? false));

        return array_map(static fn (array $row): array => $row['data'], $rows);
    }

    public function selectableConditions(): array
    {
        return array_filter($this->all('conditions'), static fn (array $data): bool => ! ($data['css'] ?? false) && ($data['selectable'] ?? true));
    }

    public function has(string $kind, string|int $id): bool
    {
        $this->load($kind);

        return isset($this->catalogs[$kind][(string) $id]);
    }

    public function get(string $kind, string|int $id): array
    {
        return $this->record($kind, $id)['data'];
    }

    public function record(string $kind, string|int $id): array
    {
        $this->load($kind);

        return $this->catalogs[$kind][(string) $id]
            ?? throw new InvalidArgumentException("Unknown content: {$kind}/{$id}");
    }

    /** Fail if a content file changed without a corresponding immutable version. */
    public function verifyIntegrity(): void
    {
        $kinds = $this->kinds();
        sort($kinds, SORT_STRING);
        $context = hash_init('sha256');
        foreach ($kinds as $kind) {
            $this->load($kind);
            hash_update_file($context, $this->directory.'/'.$kind.'.json');
            if (count($this->catalogs[$kind]) !== $this->manifest['counts'][$kind]) {
                throw new RuntimeException("Content count mismatch: {$kind}");
            }
        }
        if (! hash_equals($this->version(), hash_final($context))) {
            throw new RuntimeException('Content version mismatch');
        }
    }

    /** Resolve population scaling; randomness belongs to the caller's explicit RNG. */
    public function monster(string|int $id, int $accountCount, bool $frontIfUnspecified): array
    {
        if ($accountCount < 0 || $accountCount > 1000000) {
            throw new InvalidArgumentException('Account count outside supported range');
        }
        $record = $this->record('monsters', $id);
        if (($record['availability'] ?? 'playable') === 'catalog_only') {
            throw new InvalidArgumentException('Catalog-only monster cannot enter combat');
        }
        $data = $record['data'];
        if ($data['preview_only'] ?? false) {
            throw new InvalidArgumentException('Preview-only monster cannot enter combat');
        }
        if (is_array($data['maxhp'])) {
            $formula = $data['maxhp'];
            if ($formula['formula'] !== 'population_hp') {
                throw new RuntimeException('Unknown monster HP formula');
            }
            $hp = $formula['base'] + $formula['per_account'] * $accountCount;
            $data['maxhp'] = $data['hp'] = $hp;
            foreach (['exphold', 'moneyhold'] as $field) {
                $value = $data[$field];
                if (! is_array($value) || $value['formula'] !== 'round_hp_divide' || $value['divisor'] <= 0) {
                    throw new RuntimeException('Unknown monster reward formula');
                }
                $data[$field] = (int) round($hp / $value['divisor'], 0, PHP_ROUND_HALF_UP);
            }
        }
        $data['position'] ??= $frontIfUnspecified ? 'front' : 'back';
        $data['quantity'] ??= array_fill(0, count($data['judge'] ?? []), 0);

        return $data;
    }

    public function bossCycle(string|int $id, DateTimeImmutable $now): int
    {
        $cycle = $this->get('monsters', $id)['cycle'] ?? throw new InvalidArgumentException('Not a recurring boss');
        if (is_int($cycle)) {
            return $cycle;
        }
        if (($cycle['kind'] ?? null) !== 'hour_match') {
            throw new RuntimeException('Unknown boss cycle rule');
        }
        $hour = (int) $now->setTimezone(new DateTimeZone($cycle['timezone']))->format('G');

        return $hour === $cycle['hour'] ? $cycle['matching_seconds'] : $cycle['otherwise_seconds'];
    }

    public function availableSkills(string|int $job, int $level, array $learned): array
    {
        $this->get('jobs', $job);
        $known = array_fill_keys(array_map('strval', $learned), true);
        $skills = [];
        foreach ($this->all('skill_tree') as $rule) {
            if (! isset($known[$rule['skill']]) && $this->matches($rule['when'], (string) $job, $level, $known)) {
                $skills[$rule['skill']] = $rule['skill'];
            }
        }
        sort($skills, SORT_NUMERIC);

        return array_values($skills);
    }

    public function canChangeJob(string|int $from, string|int $to, int $level): bool
    {
        if (! $this->has('class_changes', $to)) {
            return false;
        }
        $rule = $this->get('class_changes', $to);

        return $rule['from_job'] === (string) $from && $level >= $rule['minimum_level'];
    }

    public function availableAreas(array $inventory, DateTimeImmutable $now): array
    {
        return array_filter($this->all('areas'), static function (array $area) use ($inventory, $now): bool {
            $unlock = $area['unlock'];

            return match ($unlock['kind']) {
                'always' => true,
                'unavailable' => false,
                'item' => ($inventory[$unlock['item']] ?? 0) > 0,
                'daily_window' => ($time = $now->setTimezone(new DateTimeZone($unlock['timezone']))->format('H:i')) >= $unlock['from'] && $time < $unlock['until'],
                default => throw new RuntimeException('Unknown area unlock'),
            };
        });
    }

    private function matches(array $condition, string $job, int $level, array $learned): bool
    {
        $operation = array_key_first($condition);
        $value = $condition[$operation];

        return match ($operation) {
            'all' => ! in_array(false, array_map(fn (array $child): bool => $this->matches($child, $job, $level, $learned), $value), true),
            'any' => in_array(true, array_map(fn (array $child): bool => $this->matches($child, $job, $level, $learned), $value), true),
            'learned' => isset($learned[$value]),
            'not_learned' => ! isset($learned[$value]),
            'job' => $job === $value,
            'minimum_level' => $level >= $value,
            default => throw new RuntimeException('Unknown skill prerequisite'),
        };
    }

    private function load(string $kind): void
    {
        if (! array_key_exists($kind, $this->manifest['counts'])) {
            throw new InvalidArgumentException('Unknown content kind');
        }
        $this->catalogs[$kind] ??= $this->read($kind);
    }

    private function read(string $file): array
    {
        $path = $this->directory.'/'.$file.'.json';
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Content file unavailable: '.$file);
        }
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('Content file must contain an object');
        }

        return $data;
    }
}
