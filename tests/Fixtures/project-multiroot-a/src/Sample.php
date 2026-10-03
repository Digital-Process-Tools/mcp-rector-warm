<?php

declare(strict_types=1);

final class Sample
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    /**
     * #107 fixture: the SAME unused private method as the other folder's
     * Sample.php -- proves this root's own rector.php (no dead-code rule)
     * leaves it alone, where the other root's dead-code rule removes it.
     */
    private function neverCalled(): int
    {
        return 0;
    }
}
