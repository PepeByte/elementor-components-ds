# Design Options: Generic Component Instance Overrides

## Confirmed requirements

- The parent owns the complete element tree and ordering.
- Root and nested containers are locked in instances.
- Inner widgets expose native Content and Style controls.
- Advanced controls and structural commands are locked.
- Existing carousel slide containers remain locked.
- Widgets inside existing slides remain editable.
- Sparse instance overrides survive parent value changes.
- Reset removes an override and resumes parent inheritance.

## Prior art

Elementor 4.1.5 includes native Components with structural synchronization and
controlled instance properties. However, native Components are Atomic-only and
expose eligible General properties rather than arbitrary legacy widget Style
controls. Global Widgets cover only one widget, while Saved Templates create
copies rather than synchronized instances.

The confirmed legacy-widget Content-and-Style requirement therefore needs a
custom implementation.

## Design A: Reference proxy

The consuming page keeps one Component Instance plus sparse overrides. Clicking
a rendered child creates a detached editor proxy for that parent widget.
Containers never become real children of the page.

### Strengths

- Parent remains the only stored element tree.
- Page data stays small.
- Parent updates are immediately available at render time.
- Structural locking is natural because descendants are not document models.

### Weaknesses

- Elementor controls expect a real model, view, document, and command history.
- Generic Style support needs memory-only CSS compilation and safe selector
  scoping for every instance.
- Private editor APIs create high compatibility risk.
- Third-party control behavior cannot be guaranteed.

## Design B: Locked shadow tree

Each instance materializes real Elementor child models with unique runtime IDs.
The parent IDs remain source identities. The real children use Elementor's
native panels and CSS pipeline, while command guards prevent any structural or
container edit. Sparse overrides remain authoritative; the materialized tree is
a synchronized cache.

### Strengths

- Best native Elementor editing experience.
- Reuses Content, Style, responsive, global, dynamic, history, and CSS behavior.
- Offers the strongest compatibility with ordinary and third-party widgets.
- Locked containers can still appear in the Navigator for orientation.

### Weaknesses

- Consumer pages contain a derived shadow tree.
- Parent saves require reconciliation and consumer CSS regeneration.
- Runtime IDs must differ from parent IDs and stay mapped to source IDs.
- Parent/editor concurrency, duplication, import, revisions, and failed syncs
  need explicit handling.
- Requires a verified nested-element host and command guards in Elementor 4.1.5.

## Design C: Custom proxy panel

The instance remains one real widget, but a custom panel displays a locked
parent tree and hosts native-like control views for the selected widget. It
stores only sparse overrides and rerenders the server-side instance preview.

### Strengths

- No copied element tree in consumer documents.
- Clear separation between locked hierarchy and editable widgets.
- The plugin controls reset and inheritance presentation.

### Weaknesses

- Reimplements substantial parts of Elementor's panel runtime.
- Custom and third-party controls may assume a real widget view.
- Generic Style CSS has the same compiler/scoping blocker as Design A.
- Highest long-term maintenance cost.

## Comparison

Design A has the deepest storage model but depends on unsupported detached
control and CSS seams. Design C provides a controlled UX but recreates too much
of Elementor's editor. Design B duplicates derived tree data and requires sync,
but it is the only option that naturally reuses native widget controls and the
native Style/CSS pipeline.

## Recommendation

Choose **Design B: Locked shadow tree**, conditional on a disposable spike
against Elementor 4.1.5. It best matches the user's expectation that selecting
an inner widget feels like editing a normal Elementor widget.

Do not begin the full implementation unless the spike proves:

1. A Component Instance can host selectable real child widget models.
2. Root and nested containers can be made read-only.
3. Content and Style changes can be captured as sparse overrides.
4. Add, delete, duplicate, move, paste, and reorder commands can be blocked.
5. Two instances can use distinct widget styles without CSS leakage.
6. Save, reload, undo, and redo retain only valid instance intent.
7. Parent add, move, change, and delete operations reconcile deterministically.

If the nested host or command guards cannot be proven, stop and reconsider
Design A with a reduced supported Style-control scope rather than shipping a
partially locked editor.

## Decision

Design B was approved. The compatibility spike returned a conditional GO:
native nested models, recursive save/render, and per-page CSS passed, but
`isLocked` requires a data-command guard and PHP save validation. See
`specs/archive/spikes/SPIKE-design-b-shadow-tree.md`.
