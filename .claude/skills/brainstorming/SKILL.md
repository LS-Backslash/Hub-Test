---
name: brainstorming
description: Use when starting a new feature, system, or product design before any implementation - guides structured exploration of ideas into a written spec document
---

# Brainstorming

## Overview

Turn ideas into designs before writing code.

**Core principle:** Do NOT invoke any implementation skill, write any code, scaffold any project, or take any implementation action until you have presented a design and the user has approved it.

**Announce at start:** "I'm using the brainstorming skill to design this before we build it."

**The terminal state is invoking writing-plans. Do NOT invoke frontend-design, mcp-builder, or any other implementation skill.**

## The Process

### Step 1: Explore Project Context
- Read CLAUDE.md, README, existing architecture docs
- Understand tech stack, constraints, existing patterns

### Step 2: Ask Clarifying Questions
- Ask one question at a time
- Wait for answers before asking the next
- Focus on: What problem? Who uses it? What constraints?

### Step 3: Propose 2-3 Alternatives
- Present distinct approaches with trade-offs
- Don't implement yet — just describe approaches
- Get user's preferred direction

### Step 4: Design by Sections
- Present design one section at a time
- Seek approval after each section before continuing
- Sections: Overview, Data Model, API/Interface, User Flow, Error Handling

### Step 5: Write the Spec Document
- Save to `docs/superpowers/specs/YYYY-MM-DD-<topic>-design.md`
- Include: Goals, Non-Goals, Data Model, Interface/API, User Flows, Error Cases, Open Questions

### Step 6: Spec Self-Review
Run self-review before showing user:
- Any TODOs, TBDs, or placeholders?
- Any contradictions or conflicting requirements?
- Scope too large for a single plan?
- Anything ambiguous enough to cause wrong implementation?

Fix issues inline, then show user.

### Step 7: Dispatch Spec Reviewer (Optional)
If spec is complex, dispatch spec-document-reviewer-prompt.md subagent for independent review.

### Step 8: User Reviews Spec
Present spec, ask for approval. Do not proceed until user approves.

### Step 9: Invoke Writing Plans
**REQUIRED SUB-SKILL:** Use superpowers:writing-plans

## Visual Companion (Optional)
For UI/layout/architecture decisions where seeing beats reading, offer the browser-based visual companion (see visual-companion.md). Offer it as a standalone message, not mixed with other content.

## Red Flags
- "This is simple, let's just build it" → Still needs design
- "I already know what to build" → Document it so we can review it
- Moving to implementation before spec approval → STOP

## Supporting Files
- `spec-document-reviewer-prompt.md` — Template for spec reviewer subagent
- `visual-companion.md` — Browser-based mockup/diagram guide
