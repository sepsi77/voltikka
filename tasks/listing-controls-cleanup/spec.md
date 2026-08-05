# Listing pre-list control area cleanup

## Problem

The area above the contract list on the listing pages (`/sahkosopimus`, SEO
listing pages, cheapest, homepage) had become visually busy and hard to parse:

- Five different visual shapes alternated down the stack: open rail, bordered
  disclosure, open rail, an always-expanded postcode form with its own divider,
  bordered disclosure.
- The bill-comparison disclosure sat between the consumption selector and the
  pricing pills, splitting the primary choices.
- The postcode selector (new in the national-contracts-postcode-eligibility
  work) was the loudest element: two-line status column plus a full labelled
  form for a secondary refinement.
- Label typography differed between the consumption selector, the pills row,
  and the two disclosure triggers.
- With a pill or postcode filter active, a stray "Tyhjennä suodattimet" link
  floated inside the collapsed "Rajaa hakua" box.

## Goal

One calm, coherent control stack with a clear hierarchy, without changing any
locked decision: pills always visible outside the accordion, postcode
eligibility visible as the primary path, rail shapes, locked Finnish copy,
blur/search-input bindings.

## Solution

1. Fixed order: consumption rail → pricing-behavior rail → availability row
   (primary choices), then bill disclosure + filters accordion as one collapsed
   tools cluster (8px apart), then the caption divider.
2. Postcode selector compacted to one row: bold "Saatavuus" status + helper
   sentence left, compact input + "Käytä numeroa" button right. The visible
   "Postinumero (5 numeroa)" label became `sr-only`.
3. Unified label style (`text-sm font-semibold text-slate-600` as `<p>`) and
   disclosure trigger anatomy (slate-500 icon, 14px bold title, slate-500
   chevron).
4. "Tyhjennä suodattimet" moved inside the collapsible filters panel.
