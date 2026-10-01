The README now says to keep cold `vendor/bin/rector process --dry-run` in CI. Warm output is built to match cold and is checked against it, but it is editor feedback, and CI stays the gate.
