<?php

declare(strict_types=1);

namespace App\Domain\Combat;

/** Local Park–Miller generator; stable across supported 64-bit PHP runtimes. */
final class SeededRandom implements RandomSource
{
    private int $seed;

    public function __construct(int $seed)
    {
        $this->seed = (($seed % 2147483646) + 2147483646) % 2147483646 + 1;
    }

    public static function fromState(array $state): self
    {
        if (($state['algorithm'] ?? null) !== 'park-miller-rejection-v1' || ! is_int($state['state'] ?? null) || $state['state'] < 1 || $state['state'] > 2147483646) {
            throw new \InvalidArgumentException('Invalid random state');
        }
        $random = new self(0);
        $random->seed = $state['state'];

        return $random;
    }

    public function integer(int $minimum, int $maximum): int
    {
        if ($maximum < $minimum || $maximum - $minimum >= 2147483646) {
            throw new \InvalidArgumentException('Invalid random range');
        }
        $range = $maximum - $minimum + 1;
        $limit = intdiv(2147483646, $range) * $range;
        do {
            $this->seed = (int) (($this->seed * 16807) % 2147483647);
            $value = $this->seed - 1;
        } while ($value >= $limit);

        return $minimum + $value % $range;
    }

    public function state(): array
    {
        return ['algorithm' => 'park-miller-rejection-v1', 'state' => $this->seed];
    }
}
