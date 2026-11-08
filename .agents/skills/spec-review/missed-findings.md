# Learning from findings the spec review missed

Rule (ported from ecom-buddy, 2026-11-08): every finding that a later reviewer makes and the
manager accepts is also a lesson for the spec review. Find out why our reviewers missed it, and
change the instructions so that they catch that kind of defect next time.

**When.** After a `pi-pr-review` or `external-pr-review` round (or a human review) produces an
**accepted** finding: verified as real and fixed, or real and deferred. A rejected or
false-positive finding is not a lesson for the spec review (CodeRabbit and Greptile ones go to
`.greptile/`). Do it in the same session and commit the changes with that round's records.

## For each accepted finding

1. **Owner.** Which spec-review reviewer should have caught it? Use the selection and focus tables
   in `SKILL.md`. If no reviewer's focus covers the concern, that is a coverage gap.
2. **Check the round.** In `tasks/<slug>/reviews/<ts>/`: Was the owner selected? Was the file in
   its range? Did its report mention the defect, perhaps under another name? Did the manager triage
   it away in `actions.md`? When the task had no spec review, the cause is (a) or "no spec review
   ran"; record that.
3. **Cause.** Choose one:
   - (a) the owner was not selected;
   - (b) the code was outside the range or scope given in the prompt;
   - (c) the rule is not written anywhere (root or nested `AGENTS.md`, agent file);
   - (d) the rule is written, but the reviewer's checklist or focus does not point at it;
   - (e) a reviewer found it, but the manager's triage rejected or deferred it wrongly;
   - (f) no reviewer owns this kind of concern.
4. **Fix the instruction at the cause.** Use the smallest sufficient change:
   - (a) the selection table in `SKILL.md`;
   - (b) the prompt template (range and scope wording) in `SKILL.md`;
   - (c) write the rule where code rules live (the nearest nested `AGENTS.md`), and add a checklist
     item to the owner's agent file;
   - (d) a checklist item in `.pi/agents/<reviewer>.md` that names the defect class and gives this
     finding as one short example;
   - (e) the triage or acceptance rules in `SKILL.md`;
   - (f) first try to extend an existing reviewer. Create a new reviewer only when the concern is
     high-risk or recurs and no existing reviewer can own it without losing focus. A new reviewer
     gets `.pi/agents/<name>.md` (role, focus, checklist, report layout, one ID prefix), its
     `.claude/agents/` copy, a row in the selection and focus tables, and an ID prefix in the
     report contract.
5. **Write the class, not the instance.** "Every public reader of annual rows filters by the active
   method" catches the next case; "filter `CompanyStatisticsService::latest()`" does not.
   **Extend before you add:** first look for an item in the owner's file that guards the same
   invariant. If one exists, widen its wording or add the new case as an example.
6. **Keep the copies in sync.** Every edited or new `.pi/agents/<name>.md` is copied to
   `.claude/agents/<name>.md` in the same change. The copy differs only by its `model: opus` line.
   Never edit only the copy.
7. **Record.** Add a table to the round's `actions.md` under `## Codification` (or to the report to
   the user when there is no task folder): finding → owner reviewer → cause (a–f) → instruction
   changed (path) or "none, because …". Mention it in the task's `decisions.md`.

## Consolidation

Lessons arrive one finding at a time, so items stay narrow and a reviewer file grows into a list of
special cases. Consolidate them into general rules with examples.

**When.** At the end of each `external-pr-review` (after the last clean round), for every reviewer
file that got three or more new items in that PR; and whenever the user asks.

**How:**
1. Group the file's items by the invariant they protect (what must stay true), not by the case that
   taught them. Already-general items stay as they are.
2. Write one rule per group: the invariant first, then what to look for in a diff (the code signs:
   which writes, reads, cache keys, dates, loops), then at most two short examples.
3. Remove an item only when a rule covers it. Put a mapping table (old item → new rule) in the
   round's `actions.md` `## Codification`, so nothing is lost.
4. Do not make a rule so abstract that it names no code sign: a reviewer must be able to search for
   it.
5. One commit per changed file pair (`.pi/agents/` + `.claude/agents/` copy), no product code.

Label instruction changes made while the user is away as agent decisions under delegated authority.
This procedure authorizes edits to the review instructions above, not to product code.
