<?php

declare(strict_types=1);

class TwoUnusedProperties
{
    private int $unusedA = 1;

    public function firstMethod(): int
    {
        return 1;
    }

    public function secondMethod(): int
    {
        return 2;
    }

    public function thirdMethod(): int
    {
        return 3;
    }

    public function fourthMethod(): int
    {
        return 4;
    }

    public function fifthMethod(): int
    {
        return 5;
    }

    private int $unusedB = 2;

    public function sixthMethod(): int
    {
        return 6;
    }
}
