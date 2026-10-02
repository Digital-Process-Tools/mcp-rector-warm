<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Support;

/**
 * Parses one call-request frame read off a warm worker's persistent loop
 * (#213, then the rest -- call-request slice). Three call sites in
 * RectorRunner (the fork-based worker's serveWorker(), the #185 session
 * child's runSessionBody(), and the no-pcntl standby worker's
 * serveProcessWorker()) each json_decode()d the same request envelope and
 * re-wrote an identical `\is_array($request) && ($request[key] ?? default)
 * === true`-shaped guard by hand, once per field. This class parses that
 * guard exactly once; each call site still reads its OWN subset of fields --
 * all three read argv and warm_boot, but only serveWorker() reads
 * dry_run/no_session and only runSessionBody() reads
 * path/buffer_copy/deadline_ns.
 *
 * Deliberately not itself a validating/throwing parse, matching
 * HandshakeFrame (#213, frames first): a malformed or missing frame here has
 * never been treated as an error by any of the three call sites -- a missing
 * field silently defaults (argv=[], warm_boot=false, dry_run defaults to
 * true specifically at serveWorker() per #72, ...), and that silent-default
 * behaviour on a malformed frame is exactly what this slice must not change.
 * The decode step only classifies the raw payload into typed fields; it
 * never decides what "malformed" means for a caller.
 */
final readonly class CallRequestFrame
{
    /** @param array<string, mixed> $fields empty when the payload did not decode to an array at all */
    private function __construct(
        private array $fields,
    ) {}

    public static function decode(?string $raw): self
    {
        $decoded = $raw === null ? null : \json_decode($raw, true);

        return new self(\is_array($decoded) ? $decoded : []);
    }

    /** @return list<string> */
    public function argv(): array
    {
        return \is_array($this->fields['argv'] ?? null)
            ? \array_values(\array_filter($this->fields['argv'], \is_string(...)))
            : [];
    }

    /**
     * Matches `($request[$key] ?? $default) === true` exactly, including its
     * one surprising case: a present-but-null value reads as $default too
     * (`??`, not `array_key_exists`), while any other present-but-not-true
     * value reads as false, never as $default.
     */
    public function bool(string $key, bool $default): bool
    {
        return ($this->fields[$key] ?? $default) === true;
    }

    public function string(string $key, string $default = ''): string
    {
        return isset($this->fields[$key]) ? (string) $this->fields[$key] : $default;
    }

    public function intOrNull(string $key): ?int
    {
        return \is_int($this->fields[$key] ?? null) ? $this->fields[$key] : null;
    }
}
