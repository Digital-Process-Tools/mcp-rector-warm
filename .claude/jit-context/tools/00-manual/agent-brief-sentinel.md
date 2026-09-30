---
title: "Brief oss:auditor (and any spawn review_return.py classifies) with the FINDINGS:n/NO FINDINGS sentinel explicitly"
tool: Agent
match: ~oss:auditor
mode: remind
---

`scripts/review_return.py` classifies a review spawn's final message by looking for the literal
sentinel `FINDINGS: <n>` or `NO FINDINGS` at the top. It does not infer this from a structured,
substantively-complete report: a message stating one finding in full still scored
`referred-not-stated` because a trailing "Summary: ... 1 finding" line looked like a back-reference,
and a clean "0 findings across every class" report scored `could-not-classify` with no header at
all (#156).

`oss:auditor`'s own agent definition asking for "one verdict per checklist class" is not enough --
the classifier only recognises the one sentinel shape, regardless of which agent produced the
report. State the sentinel requirement explicitly in this brief, the same way
`agents/developer/review.md` already states it for the `Explore` review spawn -- do not assume the
agent's own output shape satisfies the classifier.
