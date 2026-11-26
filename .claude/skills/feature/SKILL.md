---
name: feature
description: Build a new feature or change in steps - scout research, a plan the user approves, implementation, tests and docs by subagents.
argument-hint: <what you want to build or change>
disable-model-invocation: true
---

# /feature

The request: $ARGUMENTS

Follow these steps in order. Keep the main context small: the scout explores, you implement.

## 1. Scout

Launch the `scout` subagent with the request word for word, plus anything the user said earlier in this conversation that narrows it. Do not search or read code yourself before the brief comes back.

## 2. Plan, then wait

From the brief, write a short plan for the user (at most 15 lines):
- the files you'll change and what changes in each
- the tests the `test-writer` will add
- the docs that will change
- open questions from the brief, as direct questions

Then stop and wait for the user's OK. If they change the plan, adjust it and continue only once they approve.

## 3. Implement

- Read only the files and line ranges the brief names, plus whatever an edit needs. If the brief missed something, do one targeted search (`trail skeleton`, `trail callers`, `grep -n`); don't start a broad exploration.
- Follow the rules the brief lists and the core rules in CLAUDE.md (translations, theme tokens, access-model consistency).
- Run `vendor/bin/pint --dirty --format agent`.

## 4. Tests

Launch the `test-writer` subagent with: what changed, the files touched, the behaviours and failure modes from the plan. If it reports an application bug, fix the code and send it back to rerun the same tests.

## 5. Docs

If the change is visible to users, admins or developers, launch the `docs-writer` subagent with a short description of the change and the doc pages from the brief. Skip it for internal changes and say why.

## 6. Summary

Finish with at most 10 lines: what changed (files), the test result, the docs-writer report (pages, screenshot placeholders, stale screenshots), anything left open. End by suggesting `/clear` before the next task.
