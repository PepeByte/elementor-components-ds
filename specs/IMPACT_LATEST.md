# Impact Analysis: Generic Component Instance Widget Overrides

## Target

Replace the fixed hero-property override layer with generic widget Content and
Style overrides while preserving the parent component's container tree.

Primary targets:

- Component override engine
- Component Instance widget controls and rendering
- Elementor editor integration
- Component save/document lifecycle

## Dependents (6)

- Plugin bootstrap initializes the override subsystem.
- Component Instance registers controls and applies render-time data changes.
- Editor integration opens parent components and will enforce instance locks.
- Save handling creates the canonical parent element tree.
- Component documents provide parent identity and editor context.
- The standalone override regression check exercises the current merge layer.

## Affected Stories

No release plan or epic capsule exists. The current QA registry tracks the
fixed override limitations and related editor compatibility issues.

## Test Coverage

- The standalone PHP check covers fixed schema discovery, value merging,
  parent immutability, scoped button colors, and global color lookup.
- Gap: no browser test covers Elementor editor selection or control panels.
- Gap: no test covers command interception for move/delete/duplicate actions.
- Gap: no test covers arbitrary third-party widget controls or generated CSS.
- Gap: no test covers parent-tree reconciliation after widgets are added,
  removed, or replaced.

## Risk: High

The change replaces the current instance-control contract and depends on
Elementor editor internals without browser-level regression coverage.

## Recommended action

Run prior-art research and interface design before implementation. Prototype
native control reuse and structure locking against Elementor 4.1.5, then add an
editor integration test before replacing the fixed override engine.
