<?php

declare(strict_types=1);

final class Sample
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    /**
     * #107 fixture: an unused private method -- dead_code's own
     * RemoveUnusedPrivateMethodRector removes this under THIS folder's
     * rector.php (withPreparedSets(deadCode: true)), and only here.
     */
    private function neverCalled(): int
    {
        return 0;
    }
}
