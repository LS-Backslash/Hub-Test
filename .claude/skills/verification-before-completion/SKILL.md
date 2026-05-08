---
name: verification-before-completion
description: Use before claiming any task is complete, tests pass, bugs are fixed, or builds succeed - requires fresh verification evidence before any completion claim
---

# Verification Before Completion

## Overview

Never claim work is complete without running fresh verification commands and confirming the output.

**Core principle:** Evidence before claims, always.

**Honesty is a core value. If you lie, you'll be replaced.**

## The Iron Law

```
NO COMPLETION CLAIMS WITHOUT FRESH VERIFICATION EVIDENCE
```

## The Gate Function

**Before making ANY success claim:**

1. **Identify** what command proves the assertion
2. **Run** the full command (fresh execution, not cached result)
3. **Read** complete output and exit code
4. **Verify** that output confirms the claim
5. **Only then** make the claim with evidence

**Skip any step = lying, not verifying.**

## What Requires Verification

### Tests Pass
```
❌ INSUFFICIENT: "Tests should pass now"
❌ INSUFFICIENT: "Previous run passed"
❌ INSUFFICIENT: "I'm confident it works"

✅ REQUIRED: Run test command → Read output → "Tests pass: 47 passed, 0 failed"
```

### Build Succeeds
```
❌ INSUFFICIENT: "Build should work"
❌ INSUFFICIENT: "Linter passes" (linter ≠ build)

✅ REQUIRED: Run build command → Check exit code → Show success output
```

### Bug is Fixed
```
❌ INSUFFICIENT: "That should fix it"
❌ INSUFFICIENT: "The logic looks correct"

✅ REQUIRED: Reproduce the bug → Apply fix → Verify bug no longer occurs
```

### Feature is Complete
```
❌ INSUFFICIENT: "I implemented everything"
❌ INSUFFICIENT: "It looks done"

✅ REQUIRED: Run test suite → Verify all requirements tested → Show passing output
```

### Agent Delegations
```
❌ INSUFFICIENT: Accept agent's "DONE" report at face value

✅ REQUIRED: Independently verify using git/tests
  - git log to confirm commits exist
  - Run tests to confirm they pass
  - Check files to confirm changes were made
```

## Forbidden Language

**NEVER use these before verification:**

- "should work" / "should pass" / "should be fixed"
- "probably" / "likely" / "seems to"
- "I think it works" / "I believe it's fixed"
- "Great!" / "Done!" / "Complete!" (before running verification)
- "That fixes it" (before confirming)

**These phrases signal you're guessing, not verifying.**

## Verification Evidence Format

When reporting completion, include evidence:

```
✅ Tests pass:
  npm test output:
  47 passing (2.3s)
  0 failing

✅ Build succeeds:
  Exit code: 0
  Output: "Build complete: dist/bundle.js (125kb)"

✅ Bug fixed:
  Before: [reproduced the error]
  After: [ran same steps, no error]
```

## Common Shortcuts (All Forbidden)

| Shortcut | Why It Fails |
|----------|-------------|
| Running subset of tests | Other tests might fail |
| Checking linter only | Lint pass ≠ tests pass |
| Reading code instead of running | Looks right ≠ works right |
| Trusting previous run | State may have changed |
| Trusting agent's self-report | Agents can be wrong or incomplete |
| Skipping because "obvious fix" | Obvious fixes fail too |

## Why This Matters

Claiming completion without verification:
- Wastes time when the "complete" work fails later
- Erodes trust in your reports
- Creates cascading failures when others depend on your work
- Is a form of dishonesty

**Exhaustion ≠ excuse. Confidence ≠ evidence.**

## The Bottom Line

Run the command. Read the output. THEN claim the result.

This is non-negotiable.
