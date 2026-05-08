---
name: writing-plans
description: Use when creating implementation plans before coding starts - produces complete, no-ambiguity roadmaps for skilled engineers entering unfamiliar territory
---

# Writing Plans

## Overview

Create comprehensive implementation plans for multi-step tasks before coding. Document everything needed: files to modify, code examples, testing approaches, and how to verify work. Break into bite-sized tasks completable in 2–5 minutes each. Follow DRY, YAGNI, TDD principles with frequent commits.

**Key assumption:** The engineer knows their craft but is unfamiliar with your codebase and problem domain.

## When to Announce

Begin with: "I'm using the writing-plans skill to create the implementation plan."

Save output to `docs/superpowers/plans/YYYY-MM-DD-<feature-name>.md` unless user preferences specify otherwise.

## Scope Validation

If the spec spans multiple independent systems, suggest breaking it into separate plans—one per subsystem. Each should produce independently testable software.

## File Structure First

Map files to be created or modified before defining tasks:

- Each file has one clear responsibility
- Smaller, focused files beat large ones that do too much
- Related files live together; split by responsibility, not technical layer
- In existing codebases, follow established patterns

This structure informs task decomposition.

## Task Granularity

Each step takes 2–5 minutes and represents one action:
- Write failing test
- Verify failure
- Implement minimal code
- Verify passing tests
- Commit

## Required Plan Header

Every plan must include:

```markdown
# [Feature Name] Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use 
> superpowers:subagent-driven-development or 
> superpowers:executing-plans.

**Goal:** [One sentence]

**Architecture:** [2–3 sentences on approach]

**Tech Stack:** [Key technologies]

---
```

## Task Template

Each task includes:
- Exact file paths (create/modify/test)
- Checkbox-formatted steps
- Complete code blocks—no pseudocode
- Exact commands with expected output
- Git commit messages

## Critical Rule: No Placeholders

Never use patterns like:
- "TBD", "TODO", "implement later"
- "Add appropriate error handling" without specifics
- "Similar to Task N" instead of repeating code
- Steps describing what to do without showing how

Every step must contain complete, actionable content.

## Self-Review Checklist

Before handoff:

1. **Spec coverage** – Does each requirement map to a task?
2. **Placeholder scan** – Search for red-flag language; fix inline
3. **Type consistency** – Do method names and signatures match across tasks?

## Dispatch Spec/Plan Reviewer (Optional)

For complex plans, dispatch plan-document-reviewer-prompt.md subagent for independent review.

## Execution Handoff

After saving, offer the engineer a choice:

**Subagent-Driven** (recommended) – Dispatch fresh subagent per task with review between iterations

**Inline Execution** – Execute tasks in current session with checkpoints

This skill ensures plans are complete, no-ambiguity roadmaps for skilled engineers entering unfamiliar territory.

## Integration

**Comes after:**
- **superpowers:brainstorming** - Creates the spec this plan implements

**Used by:**
- **superpowers:subagent-driven-development** - Executes this plan with subagents
- **superpowers:executing-plans** - Executes this plan inline
