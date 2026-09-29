# Spike: Design B locked shadow tree

## Question

Can Elementor 4.1.5 host real, selectable shadow children under a custom
Component Instance while locking structure and reusing native Content, Style,
rendering, save, and CSS behavior?

Timebox: 60 minutes.

## Result

**Partially answered — conditional GO.**

The nested host, recursive model lifecycle, server rendering, sparse intent
storage, and consumer-page CSS generation work. Elementor's `isLocked` field
protects common UI actions but does not reject direct structural commands.
Production work therefore needs an editor data-dependency guard and server-side
save validation before Design B satisfies the locking requirement.

## Findings

### 1. Nested host: proven at runtime

`Elementor\Modules\NestedElements\Base\Widget_Nested_Base` is the required
base class. It adds `support_nesting`, resolves each child's actual `elType`,
serializes descendants recursively, and renders children through
`print_child()`.

The existing `EC_Component_Instance_Widget` extends `Widget_Base`, whose child
resolver defaults to legacy sections. The production class must change base.

### 2. Recursive save and rendering: proven at runtime

A throwaway widget containing a locked container, Heading, and Button was
loaded through the real WordPress and Elementor 4.1.5 runtime. Elementor
materialized the complete hierarchy, recursively serialized it, retained a
hidden sparse override value, and rendered native Heading and Button HTML.

### 3. Native Style CSS: proven at runtime

A temporary page contained two shadow instances with distinct Heading and
Button colors. `Elementor\Core\Files\CSS\Post` generated separate selectors for
each runtime element ID and included both colors. The temporary post and CSS
were deleted after the check.

Design B does not need the plugin's current hand-built inline color CSS. Real
shadow models can use the consuming page's normal Elementor CSS file.

### 4. Locking: UI-supported, command layer incomplete

`isLocked` is preserved in saved element data. Elementor checks it before
showing duplicate, copy, delete, and drag actions. Locked elements remain
selectable, so settings panels can still open.

However, the implementations of these data commands do not check `isLocked`:

- `document/elements/create`
- `document/elements/delete`
- `document/elements/duplicate`
- `document/elements/move`

Keyboard or direct `$e.run()` calls can therefore bypass the native UI lock.
Elementor 4.1.5 exposes data dependency hooks that can stop a command by
returning false and triggering `HookBreak`. Production work must register one
small guard for structural commands targeting descendants of a Component
Instance. PHP reconciliation must also discard forbidden structures on save.

### 5. Stable-ID reconciliation: algorithm proven in isolation

A throwaway presence-based merge used parent source IDs and a source-to-runtime
ID map. It preserved a local Heading title while inheriting a changed parent
color, parent order, a newly added widget, and removal of an absent widget.

This proves the data rule, not editor integration or parent-save fan-out.

## Evidence

### Local source

- Nested host contract:
  `modules/nested-elements/base/widget-nested-base.php:14-129`
- Child creation and recursive save:
  `includes/base/element-base.php:322-410,640-684,1563-1586`
- Ordinary widget child limitation:
  `includes/base/widget-base.php:755-783,835-837`
- Nested model and locked defaults:
  `assets/js/3d410a099008411bb011.bundle.js:784-809`
- Native selection and UI lock checks:
  `assets/js/editor.js:31548-31579,32560-32702,33606-33617`
- Structural commands without lock guards:
  `assets/js/editor.js:20492-20786,20875-20961,21285-21369`
- Recursive consumer-page CSS:
  `core/files/css/post.php:164-178,216-228,283-310`

### Runtime output

```text
nested host: ok
recursive save: ok
locked flags: ok
native render: ok
page css isolation: ok
cleanup: ok
stable-ID reconciliation: ok
override survives parent change: ok
parent add/move/delete inheritance: ok
```

The runtime harness lived at `/tmp/ec-shadow-runtime-spike.php` and was deleted
after this report, as required for a throwaway spike.

## Approved-criterion status

1. Child host and PHP models: **Proven**. Browser selection: **Source-supported**.
2. Container lock flags: **Proven**. Direct-command lock: **Requires guard**.
3. Heading Content per instance: **Runtime data/render proven**; browser pending.
4. Independent Button Style CSS: **Proven**; browser control interaction pending.
5. Structural commands blocked: **Not provided by `isLocked` alone**.
6. Recursive save and sparse intent retention: **Proven**; undo/reload UI pending.
7. Parent reconciliation semantics: **Proven in isolation**; fan-out pending.

## Implications for the plan

- Change the Component Instance to `Widget_Nested_Base`.
- Persist both a derived shadow tree and sparse authoritative overrides.
- Give every shadow node a unique runtime ID and retain its parent source ID.
- Use one data-dependency hook for structural command blocking.
- Revalidate and replace shadow structure from the parent on every save.
- Use normal consumer-page Elementor CSS generation.
- Remove the fixed hero controls and hand-built color CSS after migration.
- Require browser tests for selection, Content/Style panels, Advanced filtering,
  keyboard shortcuts, direct commands, undo/redo, and save/reload.

## What was NOT explored

- Actual editor clicks and Navigator behavior in Chromium.
- History behavior when reconciliation updates models without user history.
- Advanced-tab filtering and server-side control allowlisting.
- Global values, dynamic tags, responsive controls, repeaters, and third-party
  widget editor scripts.
- Parent-save consumer indexing, retries, conflicts, revisions, and imports.
- Nested carousel behavior in the editor.

## Recommendation

Proceed with Design B planning and TDD, but treat structural command guards and
server reconciliation as release blockers. Do not rely on `isLocked` as the
complete lock boundary, and do not claim editor compatibility until the browser
matrix passes on Elementor 4.1.5.
