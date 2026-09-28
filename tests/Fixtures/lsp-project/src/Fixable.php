<?php

declare(strict_types=1);

final class Fixable
{
    public function isEmpty(array $items): bool
    {
        if (count($items) === 0) {
            return true;
        }

        return false;
    }
}
