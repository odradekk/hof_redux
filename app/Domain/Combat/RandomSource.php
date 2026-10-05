<?php

declare(strict_types=1);

namespace App\Domain\Combat;

interface RandomSource
{
    public function integer(int $minimum, int $maximum): int;

    public function state(): array;
}
