<?php

declare(strict_types=1);

final class Money
{
    public function __construct(private readonly int $amount)
    {
    }

    public function amount(): int
    {
        return $this->amount;
    }
}
