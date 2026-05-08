# Condition-Based Waiting

## Overview

Flaky tests often rely on arbitrary delays, creating race conditions.

**Core principle:** Wait for the actual condition you care about, not a guess about how long it takes.

## When to Use

Use when:
- Tests have arbitrary delays (`setTimeout`, `sleep`, `time.sleep()`)
- Tests are flaky or timeout under parallel execution
- Waiting for async operations

Don't use for:
- Testing actual timing behavior
- Any arbitrary timeout you must use should be documented with an explanation

## Core Pattern

**Instead of guessing at timing:**
```javascript
// ❌ BAD: Arbitrary timeout
await new Promise(r => setTimeout(r, 300));
expect(result).toBeDefined();
```

**Wait for the actual condition:**
```javascript
// ✅ GOOD: Wait for condition
await waitFor(() => result !== undefined);
expect(result).toBeDefined();
```

## Implementation

```typescript
async function waitFor<T>(
  condition: () => T | null | undefined,
  timeoutMs = 5000
): Promise<T> {
  const startTime = Date.now();

  while (true) {
    const result = condition();
    if (result !== null && result !== undefined) {
      return result;
    }

    if (Date.now() - startTime > timeoutMs) {
      throw new Error(`Timeout waiting for condition after ${timeoutMs}ms`);
    }

    await new Promise(r => setTimeout(r, 10)); // Poll every 10ms
  }
}
```

## Common Mistakes

- **Polling too fast:** `setTimeout(check, 1)` - wastes CPU
- **No timeout:** Loop forever if condition never met
- **Stale data:** Cache state before loop, miss updates

## Real-World Results

From debugging session applying this pattern:
- Pass rate: 60% → 100%
- Execution time: 40% faster

See `condition-based-waiting-example.ts` for complete TypeScript implementation with event-based waiting utilities.
