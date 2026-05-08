---
name: test-driven-development
description: Use when implementing any feature or bugfix, before writing implementation code
---

# Test-Driven Development

## The Iron Law

```
NO PRODUCTION CODE WITHOUT A FAILING TEST FIRST
```

**Violating the letter of this rule is violating the spirit of TDD.**

Write code before test? **Delete it. Start over.**

**No exceptions:**
- Not for "simple" functions
- Not for "I already know it works"
- Not for "I'll add tests after"
- Not for emergencies
- Not when under time pressure
- Delete means delete. Don't keep it as "reference."

## The Red-Green-Refactor Cycle

```
RED:    Write a failing test first
        ↓
        Verify it fails (watch it fail — if it passes immediately, the test is wrong)
        ↓
GREEN:  Write the MINIMAL code to make it pass
        ↓
        Verify all tests pass
        ↓
REFACTOR: Clean up while keeping tests green
        ↓
        Repeat
```

## Why Order Matters

**Tests written after code:**
- Pass immediately (prove nothing)
- Test what the code does, not what it should do
- Miss edge cases you didn't think about while coding

**Tests written first:**
- Fail first (prove the test is real)
- Define what the code SHOULD do
- Discover edge cases before coding
- Create a specification

"If you didn't watch the test fail, you don't know if it tests the right thing."

## Step by Step

### Step 1: Write Failing Test (RED)
```typescript
// Write the test you WISH would pass
test('adds two numbers', () => {
  expect(add(2, 3)).toBe(5);
});
```

### Step 2: Verify It Fails
Run the test. It must fail. If it passes → test is wrong, fix it.

Fail for the RIGHT reason:
- ✅ `add is not defined` → correct, function doesn't exist yet
- ❌ Test passes → test doesn't actually test anything

### Step 3: Write Minimal Code (GREEN)
Write the LEAST code that makes the test pass:
```typescript
function add(a: number, b: number): number {
  return a + b;
}
```

Not: everything you think you'll need.
Not: the "real" implementation with all features.
Just: what makes THIS test pass.

### Step 4: Verify All Tests Pass
Run full test suite. All tests must be green.

### Step 5: Refactor
Clean up code and tests while keeping everything green.
Then repeat from Step 1 for the next behavior.

## Verification Checklist

Work is complete only when:
- [ ] Every function has a test that failed before implementation
- [ ] You watched each test fail
- [ ] Minimal code was written (no extra features)
- [ ] All tests pass with clean output
- [ ] Real behavior is tested (not just mocks)
- [ ] Edge cases are covered

## Common Rationalizations

| Excuse | Reality |
|--------|---------|
| "Too simple to test" | Simple code still breaks. Test takes 30 seconds. |
| "I'll add tests after" | Tests after pass immediately. Prove nothing. |
| "I already manually tested it" | Manual testing is not repeatable. Not TDD. |
| "TDD slows me down" | TDD is faster than debugging. |
| "Sunk cost - don't delete" | Keeping untested code is technical debt. Delete it. |
| "Tests after achieve the same goals" | Tests after verify what code does. Tests first define what it should do. |
| "I'm following the spirit" | You're not. Violating the letter is violating the spirit. |

## Red Flags - STOP and Start Over

If you catch yourself:
- Writing code before a failing test exists
- "Adapting" existing code while writing tests
- Keeping code "as reference" while writing tests
- Saying "I already know it works"
- Writing tests that pass on first run
- Skipping the "watch it fail" step

**ALL of these mean: Delete code. Start over with a failing test.**

## When to Load Testing Anti-Patterns

After implementing tests, read `testing-anti-patterns.md` to verify you haven't:
- Tested mock behavior instead of real behavior
- Added test-only methods to production code
- Mocked without understanding dependencies
- Created incomplete mocks

## Integration

**Required for:**
- **superpowers:subagent-driven-development** - Subagents follow TDD for each task
- **superpowers:systematic-debugging** - Phase 4, Step 1: Create failing test case

**Related:**
- **superpowers:verification-before-completion** - Verify all tests pass before claiming done
