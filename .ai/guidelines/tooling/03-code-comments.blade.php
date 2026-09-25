# Code Comments — What Earns a Place in the Source

**Priority: HIGH.** A comment is not free. It competes with the code for the reader's
attention and for an agent's context window, and — unlike code — nothing verifies it.
An out-of-date comment is worse than no comment: a human discounts it, an agent obeys it.

The question is never "is this comment too long?". It is **"when the code next to it
changes, does this comment become a lie?"**

## The deletion test

Before writing — or when deciding whether to keep — a comment, ask: **if I delete this,
what breaks?**

| Answer | Verdict |
|--------|---------|
| "Someone reintroduces the bug it warns about" | **Keep** |
| "Someone can't find the other end of a contract that isn't on screen" | **Keep**, as a one-line anchor |
| "I lose the record of how we got here" | **Delete.** Git, the PR and the issue already hold it |

## What earns a place

- **Invariants** — the rule the code obeys, stated so a future edit that breaks it is
  visibly wrong. *"The dedupe key is (signature, source owner), never the signature
  alone: one transaction may carry legs from two distinct sources."*
- **Traps** — why the obvious thing is NOT done here. *"Re-dispatching is safe because
  the job re-reads the resource via GET."*
- **Anchors** — `{@see OtherClass::method()}`, `ADR-NNNN`, a `CONTEXT.md` term. A
  pointer, one line, no copied reasoning.
- **Remote contracts** — a coupling the reader cannot see from this file.

## What never earns a place

- **Changelog.** *"Two fields the old form carried are gone by data-model decision."*
  Nobody remembers the old form. Git does.
- **Accountability.** *"Guided-over-hard-block by triage decision."* The comment is not
  where you report that a ticket was honoured. The PR is.
- **Reasoning copied from an ADR.** Two sources of truth that drift apart. Point at the
  ADR instead.
- **Narration.** A sentence restating the line below it.

## Never reference an issue or PR number from source code

Banned in every comment and docblock under `app/` and `app-modules/*/src/`:
`issue #N`, `map #N`, `PR #N`, a bare `(#N)`.

An issue lives outside the repo: it closes, gets superseded, gets renumbered in the
reader's memory, and a year later `#166 addendum` is a dead number that costs a round
trip to GitHub to resolve — and usually resolves to a thread about something else. An
**ADR** is the opposite: versioned in the repo, immutable by convention, reviewable in
the same diff as the code it governs.

**If a decision matters enough to be a comment, it matters enough to be an ADR.** Write
it to `docs/adr/` (system-wide) or `app-modules/<module>/docs/adr/` (module-scoped) and
leave the pointer.

@verbatim
<code-snippet name="Issue reference → ADR pointer" lang="php">
// BAD — a dead number and a story:
// The quote shown here is INDICATIVE and never locked (economy ADR-0002): the
// variation notice is copy, with no acknowledgement and no data trace (issue #169).
// A bucket of Forex's own and the remaining fail-closed gates stay the map #165 /
// issue #173 work.

// GOOD — the rule, and where the decision lives:
// The quote is INDICATIVE, never locked (economy ADR-0002). The variation notice
// is copy: no acknowledgement, no data trace.
</code-snippet>
@endverbatim

Pending or future work is a tracker concern, not a source concern. Do not leave
`TODO (#173)` behind — open the issue and let the tracker hold it.

## Replanning rewrites the comment; it never stacks

When behaviour changes, the comment that described the old behaviour is **deleted** and
replaced by one that describes the new state as if it had always been that way. Never
append a new paragraph explaining why the paragraph above it is now outdated.

@verbatim
<code-snippet name="Stacked justification → single statement" lang="php">
// BAD — three rounds of planning, sedimented:
// Originally the gate was the monthly allowance. That turned out to block the page
// customers most need, so access became Operational Party only. The allowance now
// deliberately does NOT gate access — an exhausted ceiling still needs the page.

// GOOD — the current rule, stated once:
// Access is Operational Party only ({@see Party::canTransact()}). The monthly
// allowance deliberately does NOT gate it: an exhausted ceiling still needs this
// page, where the breakdown and the upgrade path live. The real gate is
// PlaceFxOrder refusing server-side.
</code-snippet>
@endverbatim

Deleting a comment that no longer holds is not destructive — leaving it is.

## An invariant belongs in a test before it belongs in prose

A rule you are tempted to spell out in thirty lines of docblock is a rule a test should
**assert by name**. A test that fails is documentation that cannot go stale; a paragraph
cannot fail. Write the test first, then keep the comment only for what the test name
cannot carry — usually the *why*, in one or two lines.

@verbatim
<code-snippet name="Prose invariant → named test + short comment" lang="php">
// BEFORE — 20 lines of docblock nobody verifies:
/**
 * THREE IDEMPOTENCY RULES, ALL SILENT, NONE OF THEM THROWING:
 *  1. A leg already recorded is a no-op. Sealed on an Intake or already logged as
 *     unmatched — either way that arrival is in our books once. …
 *  2. A fact matching no OPEN Intake becomes an unmatched transfer. …
 *  3. A fact that loses the race becomes an unmatched transfer. …
 */

// AFTER — the rules live in tests/Feature/RecordIntakeOutcomeTest.php:
it('records a leg at most once per (signature, source owner)');
it('logs a fact matching no open Intake as an unmatched transfer');
it('logs the loser of a confirmation race as an unmatched transfer, silently');

// …and the docblock keeps only what the names cannot say:
/**
 * Idempotency is silent by design: a losing writer no-ops instead of throwing, so a
 * retry and a sweep can race without either becoming an error the operator must read.
 */
</code-snippet>
@endverbatim

## Where each kind of knowledge lives

@verbatim
<code-snippet name="Documentation layers" lang="text">
  ┌──────────────────────────────────────────────────────────────┐
  │  issue / PR      "why we decided this, on that day"          │  history
  ├──────────────────────────────────────────────────────────────┤
  │  docs/adr/       "the decision, alternatives, trade-off"     │  decision
  ├──────────────────────────────────────────────────────────────┤
  │  CONTEXT.md      "the module's vocabulary and boundaries"    │  domain
  ├──────────────────────────────────────────────────────────────┤
  │  comment         "the trap in THIS line / THIS class"        │  local
  ├──────────────────────────────────────────────────────────────┤
  │  code + test     "what actually happens"                     │  truth
  └──────────────────────────────────────────────────────────────┘

  comment ──points to──► ADR / CONTEXT.md      one line, a reference
  comment ──copies────► ADR / CONTEXT.md       two truths that will diverge
  comment ──narrates──► issue / PR             wrong layer entirely
</code-snippet>
@endverbatim

Writing an ADR inside a class docblock is the most common failure here: it reads as
thorough, and it puts a decision where no reviewer of the decision will ever look.

## Comment language: English

Comments are written in English. The team reviews the code — and its comments — in
English, and the guidelines agents consume are English. One language keeps the flow
the same for every dev and every agent.

Kept verbatim, never reworded, in any comment:

- **Identifiers** — class, method, property, column, config key, env var. Anything you
  would type into code stays exactly as it is typed.
- **Test names quoted as evidence.** `refuses a transfer of a different mint` is a
  pointer to a real test; changing a word breaks the reference.
- **ADR titles and `CONTEXT.md` terms.** The glossary owns the vocabulary — see the
  domain-docs guideline. Use the term exactly as it is defined.

@verbatim
<code-snippet name="Same invariant, canonical language" lang="php">
// BAD — pt_BR prose, no longer the canonical language:
// A chave de dedupe é (signature, source owner), nunca a signature sozinha: uma
// transação pode carregar legs de duas origens distintas.

// GOOD — English, with identifiers and domain vocabulary left as typed:
// The dedupe key is (signature, source owner), never the signature alone: one
// transaction may carry legs from two distinct sources.
</code-snippet>
@endverbatim

Comments written in another language are **not** a translation campaign. They convert to
English when the rule that governs them is rewritten anyway — the replanning rule above
already deletes and replaces, and what it writes is born in English.

## Comment style: short, one idea, active

Comment prose follows the principles of ASD-STE100 (Simplified Technical English) and
Zinsser's four rules — simplicity, brevity, clarity, humanity. Not the full ASD-STE100
controlled dictionary; the principles:

- **Short sentences.** One clause where one clause carries the idea.
- **One idea per sentence.** Split a sentence that carries two.
- **Active voice.** "The job re-reads the resource", not "the resource is re-read".
- **Consistent lexicon.** One name per concept — the glossary's name, no synonyms.

The same style governs the prose in these guidelines. It does **not** govern ADR or
`CONTEXT.md`: those are fixed reference contexts, meant to be thorough, and follow only
the language rule (English) — never this brevity. See the domain-docs guideline.

## PHPDoc tags are not comments

This guideline governs **prose**. Structural PHPDoc is mandatory and unaffected:
`@property` blocks on models (see the model-phpdoc-sync guideline), `@param` /
`@return` array shapes, `@var`, and generics. Keep them complete — PHPStan reads them.

## Verification

Before finishing a change, confirm:

1. No comment you added references `issue #N`, `map #N`, `PR #N`, or a bare `(#N)`.
2. Every comment you kept survives the deletion test.
3. Where behaviour changed, the old comment was **rewritten**, not appended to.
4. Any multi-paragraph rule you were about to write exists as a named test, an ADR, or
   a `CONTEXT.md` entry — with only a pointer left in the source.
5. Every comment you wrote is in English and follows the style — short sentences, one
   idea each, active voice — with identifiers, quoted test names and glossary terms left
   verbatim.
