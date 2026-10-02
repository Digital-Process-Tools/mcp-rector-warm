<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Support;

/**
 * Parses one worker/session-child handshake frame (#213, frames first). Three
 * call sites in RectorRunner (the fork-based worker's boot(), the session
 * child's spawnSession(), and the no-pcntl standby worker's
 * awaitProcWorkerReady()) each json_decode()d the same `{"ok": true, ...}` /
 * `{"ok": false, "error": "..."}` shape and re-wrote an identical
 * `!is_array($decoded) || ($decoded['ok'] ?? false) !== true` guard by hand.
 * This class parses that guard exactly once; each call site still builds its
 * OWN failure message and reads its OWN subset of success fields (the three
 * protocols are the same success/failure envelope, not the same payload --
 * the fork handshake carries bootstrap_files, the session handshake carries
 * tracked/directories, the standby handshake carries config_file and friends).
 *
 * Deliberately not itself an exception-throwing parse: every one of the three
 * call sites builds its own RuntimeException/disableSession() message using
 * worker-local context (an exit status, a stderr tail) that this class has no
 * access to, and the rule for this slice is "no behaviour change on valid OR
 * malformed frames" -- so the decode step only classifies, it never decides
 * what a caller does about a failure.
 */
final readonly class HandshakeFrame
{
    /** @param array<mixed> $fields empty when the payload did not decode to an array at all */
    private function __construct(
        public bool $ok,
        public ?string $error,
        private bool $decodedToArray,
        private array $fields,
    ) {}

    public static function decode(?string $raw): self
    {
        $decoded = $raw === null ? null : \json_decode($raw, true);
        $isArray = \is_array($decoded);
        $ok = $isArray && ($decoded['ok'] ?? false) === true;
        $error = $isArray && isset($decoded['error']) ? Scalar::toString($decoded['error'], 'handshake frame "error"') : null;

        return new self($ok, $error, $isArray, $isArray ? $decoded : []);
    }

    /**
     * Whether the raw payload decoded to a JSON object/array at all -- distinct
     * from $ok, since a well-formed-but-failed frame (`{"ok": false}`, no
     * "error" key) and a payload that never decoded to an array both leave
     * $error null, but some callers' own default message differs between the
     * two (RectorRunner::spawnSession()'s 'unknown error' vs 'no handshake').
     */
    public function decodedToArray(): bool
    {
        return $this->decodedToArray;
    }

    public function string(string $key): ?string
    {
        return \is_string($this->fields[$key] ?? null) ? $this->fields[$key] : null;
    }

    /** @return array<mixed> */
    public function array(string $key): array
    {
        return \is_array($this->fields[$key] ?? null) ? $this->fields[$key] : [];
    }

    /**
     * A path => hash map (the fork and standby handshakes' "bootstrap_files",
     * #33): each value is a content hash, or null for a file that could not be
     * read. Absent or non-array reads as [] exactly like array(); an entry of
     * any other type throws, since storing it would poison every later
     * staleness check that compares hashes.
     *
     * @return array<string, string|null>
     * @throws \UnexpectedValueException
     */
    public function stringOrNullMap(string $key): array
    {
        $map = [];
        foreach ($this->array($key) as $path => $hash) {
            if ($hash !== null && !\is_string($hash)) {
                throw new \UnexpectedValueException(\sprintf('handshake frame "%s": entry "%s" is %s, expected string or null', $key, $path, \get_debug_type($hash)));
            }
            // A no-op at runtime (PHP turns a numeric-string key back into an
            // int), here so the declared array<string, ...> holds statically.
            $map[(string) $path] = $hash;
        }

        return $map;
    }

    public function int(string $key): int
    {
        return Scalar::toInt($this->fields[$key] ?? 0, \sprintf('handshake frame "%s"', $key));
    }
}
