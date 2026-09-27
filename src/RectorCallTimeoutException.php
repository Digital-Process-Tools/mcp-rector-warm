<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm;

/**
 * Thrown by readExactly() when a --call-timeout deadline expires while waiting
 * on the persistent worker<->daemon socket (#58). Distinct from the plain
 * \RuntimeException a real EOF (the worker closing the connection) throws
 * through, so runForked() can tell "the worker is genuinely gone" apart from
 * "the worker has gone quiet past its deadline" and react to the second case
 * by forcing the worker down itself (SIGKILL + reap) rather than waiting on a
 * read that may never arrive.
 */
final class RectorCallTimeoutException extends \RuntimeException
{
}
