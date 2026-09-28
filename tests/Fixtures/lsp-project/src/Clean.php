<?php

declare(strict_types=1);

final class Clean
{
    public function isEmpty(array $items): bool
    {
        return count($items) === 0;
    }
}
