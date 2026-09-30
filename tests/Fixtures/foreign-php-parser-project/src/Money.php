<?php

declare(strict_types=1);

final class Money
{
    public function __construct(
        private readonly int $amount,
        private readonly string $currency,
    ) {
    }

    public function format(): string
    {
        return $this->amount . ' ' . $this->currency;
    }
}
