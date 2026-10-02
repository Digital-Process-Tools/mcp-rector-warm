<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Support;

/**
 * Parses one call-result frame read back from a warm worker/child or a cold
 * subprocess (#213, then the rest -- call-result slice). Four call sites in
 * RectorRunner (runForked(), forkAndExecute(), runCold(), callProcWorker())
 * each json_decode()d the same `{"exit_code": int, "output": string,
 * "warm_boot": bool}` envelope (optionally `{"error": "..."}` instead) and
 * re-wrote an identical `!is_array($decoded)` / `isset($decoded['error'])`
 * guard pair by hand. This class parses that guard exactly once; each call
 * site still builds its OWN failure message using context this class has no
 * access to (an exit status, a stderr tail, a cleanup step) and still casts
 * the returned fields to the same `array{exit_code: int, output: string,
 * warm_boot: bool}` shape via its own @var annotation, exactly as before.
 *
 * Deliberately not itself an exception-throwing or reshaping parse, matching
 * HandshakeFrame and CallRequestFrame (#213): a malformed frame here has
 * never been decided by the decode step itself, and the rule for this slice
 * is "no behaviour change on valid OR malformed frames" -- so fields() hands
 * back the decoded payload exactly as json_decode() produced it, never
 * reshaped or defaulted field-by-field, which is what lets every call site's
 * existing @var-cast-and-return stay byte-for-byte identical.
 */
final readonly class CallResultFrame
{
    /** @param array<mixed> $fields empty when the payload did not decode to an array at all */
    private function __construct(
        private bool $decodedToArray,
        private array $fields,
    ) {}

    public static function decode(?string $raw): self
    {
        $decoded = $raw === null ? null : \json_decode($raw, true);
        $isArray = \is_array($decoded);

        return new self($isArray, $isArray ? $decoded : []);
    }

    /**
     * Whether the raw payload decoded to a JSON object/array at all -- every
     * original call site's `!is_array($decoded)` guard, which each treats as
     * "the worker/subprocess is gone" or "produced no output" and reports
     * with its own worker-specific message.
     */
    public function decodedToArray(): bool
    {
        return $this->decodedToArray;
    }

    /**
     * Matches `isset($decoded['error']) ? (string) $decoded['error'] : null`
     * exactly: a present-but-non-string scalar error value is still cast to
     * string, never rejected (an array/object one now throws -- see Scalar,
     * #213 level 9), and a present-but-null value reads as absent (isset()
     * is false for null), same as every original call site.
     */
    public function error(): ?string
    {
        return isset($this->fields['error']) ? Scalar::toString($this->fields['error'], 'call result frame "error"') : null;
    }

    /**
     * The decoded payload exactly as json_decode() produced it -- every
     * original call site cast this straight to
     * array{exit_code: int, output: string, warm_boot: bool} via its own
     * inline var-type annotation and returned it unchanged, so this class
     * hands the same fields back unreshaped rather than rebuilding them key
     * by key.
     *
     * @return array<mixed>
     */
    public function fields(): array
    {
        return $this->fields;
    }
}
