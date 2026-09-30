<?php

namespace PhpParser;

// #192: a php-parser class that is NOT Rector's bundled copy. Every method throws, so a
// warm server that loads it (instead of Rector's own) reports this message as an error
// where cold `rector process` returns its diff.
abstract class NodeVisitorAbstract implements NodeVisitor
{
    public function beforeTraverse(array $nodes)
    {
        throw new \LogicException('foreign nikic/php-parser copy used');
    }

    public function enterNode(Node $node)
    {
        throw new \LogicException('foreign nikic/php-parser copy used');
    }

    public function leaveNode(Node $node)
    {
        throw new \LogicException('foreign nikic/php-parser copy used');
    }

    public function afterTraverse(array $nodes)
    {
        throw new \LogicException('foreign nikic/php-parser copy used');
    }
}
