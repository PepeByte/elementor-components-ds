# Design B Browser Test Matrix

Verification checklist for the Elementor editor integration on a real
WordPress + Elementor 4.1.5 runtime (Local by Flywheel or similar). Referenced
by `specs/IMPLEMENTATION_PLAN_DESIGN_B.md` success criterion 7.

## Setup

- Site with Elementor 4.1.5 and this plugin active.
- A parent "Hero" component containing: a root container, a nested container
  (slide-like), a Heading, a Text Editor, and a Button.
- Two pages, each with a Component Instance pointing at Hero.

## Selection and panels

| # | Case | Steps | Expected | Status |
|---|------|-------|----------|--------|
| B1 | Shadow widget selection | Click the Heading inside an instance | Native Heading panel opens in the instance context | pending |
| B2 | Shadow container selection | Click the root/nested container | Container panel opens, Style tab only | pending |
| B3 | Content editing | Change the Heading title | Only this instance changes; parent untouched | pending |
| B4 | Style editing | Change Heading/Button color | Per-instance CSS selector generated; no leakage to the other instance | pending |
| B5 | Advanced lock | Open the widget's Advanced tab | Advanced controls hidden/disabled | pending |
| B6 | Navigator | Open Navigator | Locked containers listed, widgets listed | pending |

## Structural locking

| # | Case | Steps | Expected | Status |
|---|------|-------|----------|--------|
| B7 | Create guard | Drag a new widget into the instance | Blocked with toast | pending |
| B8 | Delete guard | Select a shadow widget, press Delete / right-click delete | Blocked | pending |
| B9 | Duplicate guard | Duplicate a shadow widget | Blocked | pending |
| B10 | Move guard | Drag a shadow widget to reorder | Blocked | pending |
| B11 | Paste guard | Copy an element, paste into the instance | Blocked (routes through create) | pending |
| B12 | Convert guard | Change a shadow widget's type (Convert) | Blocked or reverted on save | pending |
| B13 | Keyboard | Ctrl/Cmd+A select-all then Delete inside instance | No structural change | pending |

## Overrides and reset

| # | Case | Steps | Expected | Status |
|---|------|-------|----------|--------|
| B14 | Sparse capture | Change one allowed control, save, reload | Only that value stored in `ec_overrides`; rest inherits | pending |
| B15 | Empty override | Clear an overridden field to empty | Presence preserved (empty = override) | pending |
| B16 | Reset | Click "Reset to parent" on an overridden control | Value reverts to parent; override removed | pending |
| B17 | Two instances | Override different colors on both instances | Both persist independently; no CSS leakage | pending |
| B18 | Global/dynamic | Apply a global color / dynamic tag to an allowed control | Stored in globals/dynamic buckets; survives reload | pending |

## Parent sync

| # | Case | Steps | Expected | Status |
|---|------|-------|----------|--------|
| B19 | Parent value change | Edit parent title/color, save | Instances inherit new value unless overridden | pending |
| B20 | Parent add widget | Add a widget to parent, save | New widget appears in every instance | pending |
| B21 | Parent remove overridden widget | Remove a parent widget that had an instance override, save | Widget + its override disappear from instances | pending |
| B22 | Parent reorder | Reorder parent children, save | Order follows parent in all instances | pending |
| B23 | Consumer fan-out | Save parent with two consumers | Both consumers' `_elementor_data` reconciled and CSS regenerated | pending |
| B24 | Stale version | Edit an instance without syncing, then save parent | Instance rebuilds from parent; uncaptured live edits dropped (documented) | pending |

## History and reload

| # | Case | Steps | Expected | Status |
|---|------|-------|----------|--------|
| B25 | Undo/redo of an override | Change a value, undo, redo | Overrides update correctly; shadow not corrupted | pending |
| B26 | Save/reload | Save page with overrides, reload editor | Overrides and shadow intact | pending |
| B27 | Two tabs | Edit parent in tab A, instance in tab B, save both | Deterministic result, no data loss beyond documented stale-version drop | pending |

## Carousel descendants

| # | Case | Steps | Expected | Status |
|---|------|-------|----------|--------|
| B28 | Slide lock | Add/remove/reorder carousel slides in an instance | Blocked | pending |
| B29 | Widgets in slides | Edit a widget inside an existing slide | Editable; override captured | pending |

## Out of scope (not required to pass)

- Elementor versions other than 4.1.5.
- Third-party widget editor scripts and undocumented controls.
- Container setting changes from an instance.
