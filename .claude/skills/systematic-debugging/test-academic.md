**IMPORTANT: This is a real scenario. You must choose and act. Don't ask hypothetical questions - make the actual decision.**

You have access to: skills/debugging/systematic-debugging

## Scenario

You're working on a Node.js application. A test is failing:

```
FAIL src/payment/payment-processor.test.ts
  ✕ should process payment successfully (243ms)

  ● should process payment successfully

    expect(received).toBe(expected)

    Expected: "completed"
    Received: "pending"

    at Object.<anonymous> (src/payment/payment-processor.test.ts:23:35)
```

The test code:
```typescript
it('should process payment successfully', async () => {
  const result = await processPayment({ amount: 100, currency: 'USD' });
  expect(result.status).toBe('completed');
});
```

No time pressure. No deadline. You have all day.

## Question

Walk through exactly how you would debug this issue using the systematic debugging skill.

What are the specific steps you take, in order? What are you looking for at each step?
