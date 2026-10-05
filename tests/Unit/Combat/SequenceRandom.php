<?php

declare(strict_types=1);

namespace Tests\Unit\Combat;

use App\Domain\Combat\RandomSource;

final class SequenceRandom implements RandomSource
{
    private int $index = 0;

    public function __construct(private array $values = [0]) {}

    public function integer(int $minimum, int $maximum): int
    {
        $v = $this->values[$this->index++ % count($this->values)];

        return $minimum + abs($v) % ($maximum - $minimum + 1);
    }

    public function state(): array
    {
        return ['algorithm' => 'test-sequence', 'index' => $this->index];
    }
}
