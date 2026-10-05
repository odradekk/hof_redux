<?php

declare(strict_types=1);

namespace App\Domain\Combat;

/** Mutable state owned by exactly one simulation, never returned by reference. */
final class Combatant
{
    public array $v;

    public float $progress = 0;

    public ?int $casting = null;

    public int $actions = 0;

    public array $counts = [];

    public bool $rewarded = false;

    public array $base;

    public function __construct(public readonly string $id, public readonly int $team, array $data)
    {
        $data = self::copyValues($data);
        $this->v = $data + ['name' => $id, 'maxhp' => 1, 'maxsp' => 0, 'str' => 0, 'int' => 0, 'dex' => 0, 'spd' => 0, 'luk' => 0, 'level' => 1, 'position' => 'front', 'guard' => 'always', 'monster' => false, 'summon' => false, 'state' => 0, 'SPECIAL' => [], 'atk' => [0, 0], 'def' => [0, 0, 0, 0], 'tactics' => []];
        foreach (['maxhp', 'maxsp', 'str', 'int', 'dex', 'spd', 'luk', 'level'] as $key) {
            $value = $this->v[$key];
            if (! is_numeric($value) || $value < 0 || $value > 1000000000) {
                throw new \InvalidArgumentException("Invalid $key for $id");
            }
            $this->v[$key] = (int) $value;
        }
        if ($this->v['maxhp'] < 1) {
            throw new \InvalidArgumentException('Maximum HP must be positive');
        }
        foreach (['hp', 'sp'] as $resource) {
            $this->v[$resource] = max(0, min($this->v['max'.$resource], (int) ($data[$resource] ?? $this->v['max'.$resource])));
        }
        $this->v['state'] = $this->v['hp'] === 0 ? 1 : (int) $this->v['state'];
        if (! in_array($this->v['state'], [0, 1, 2], true) || ! in_array($this->v['position'], ['front', 'back'], true)) {
            throw new \InvalidArgumentException('Invalid status or position');
        }
        if (! in_array($this->v['guard'], ['always', 'never', 'life25', 'life50', 'life75', 'prob25', 'prob50', 'prob75'], true)) {
            throw new \InvalidArgumentException('Invalid guard policy');
        }
        foreach (['atk' => 2, 'def' => 4] as $field => $length) {
            if (! is_array($this->v[$field]) || count($this->v[$field]) !== $length) {
                throw new \InvalidArgumentException("Invalid $field shape");
            }
            foreach ($this->v[$field] as $value) {
                if (! is_numeric($value) || $value < 0 || $value > 1000000000) {
                    throw new \InvalidArgumentException("Invalid $field value");
                }
            }
        }
        if (! is_array($this->v['SPECIAL']) || ! is_array($this->v['tactics'])) {
            throw new \InvalidArgumentException('Invalid specials or tactics');
        }
        if (isset($this->v['experienceThresholds'])) {
            if (! is_array($this->v['experienceThresholds'])) {
                throw new \InvalidArgumentException('Invalid experience thresholds');
            }
            for ($level = 1; $level < 50; $level++) {
                if (! isset($this->v['experienceThresholds'][$level]) || ! is_int($this->v['experienceThresholds'][$level]) || $this->v['experienceThresholds'][$level] < 1) {
                    throw new \InvalidArgumentException('Missing positive experience threshold');
                }
            }
            if (! is_int($this->v['exp'] ?? 0) || ($this->v['exp'] ?? 0) < 0) {
                throw new \InvalidArgumentException('Invalid experience');
            }
        }
        $this->base = $this->v;
        if (! isset($data['tactics']) && isset($data['judge'])) {
            foreach ($data['judge'] as $i => $judge) {
                $this->v['tactics'][] = ['condition' => (int) $judge, 'quantity' => (int) ($data['quantity'][$i] ?? 0), 'skill' => (int) ($data['action'][$i] ?? 0)];
            }
        }
    }

    private static function copyValues(array $data): array
    {
        $copy = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $copy[$key] = self::copyValues($value);
            } elseif (is_scalar($value) || $value === null) {
                if (is_float($value) && ! is_finite($value)) {
                    throw new \InvalidArgumentException('Nonfinite snapshot value');
                } $copy[$key] = $value;
            } else {
                throw new \InvalidArgumentException('Snapshots contain values, not objects or resources');
            }
        }

        return $copy;
    }

    public function alive(): bool
    {
        return $this->v['state'] !== 1;
    }

    public function rate(): float
    {
        return sqrt($this->v['spd']) + 5;
    }

    public function percent(string $resource): float
    {
        return $this->v['max'.$resource] ? $this->v[$resource] / $this->v['max'.$resource] * 100 : 0;
    }

    public function export(): array
    {
        return ['id' => $this->id, 'team' => $this->team, 'progress' => $this->progress, 'casting' => $this->casting, 'actions' => $this->actions, 'conditionCounts' => $this->counts] + $this->v;
    }
}
