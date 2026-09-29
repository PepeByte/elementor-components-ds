# Elementor Components

Synced reusable components for Elementor. Save any widget, container, or
section once, reuse it anywhere, and edit the parent to update every instance.

## Install

Copy this folder to `wp-content/plugins/elementor-components` and activate.
Requires Elementor **3.8+** (the nested-elements API floor); developed and
tested against Elementor **4.1.5**.

## Use

1. **Save** — Right-click any widget, container, or section in Elementor,
   choose **Save as Component**, and name it.
2. **Manage** — Open Elementor → Templates → Saved Templates → **Component**.
3. **Expose properties** — Edit the parent and open **Component Properties**
   on supported Heading, Text Editor, and Button widgets. Choose which values
   instances may override.
4. **Reuse** — Add the **Component Instance** widget and pick your component.
5. **Override** — Open **Instance Overrides** and set only the values that
   should differ. Empty fields inherit from the parent.
6. **Edit layout** — Use **Edit Parent Component**. Parent layout/default
   changes update every instance while local overrides remain.

## How it works

- Components are `elementor_library` documents with type `component`.
- An instance stores `component_id`, sparse override values, and a locked
  *shadow* copy of the parent tree (so Elementor renders it natively).
- Each shadow records the `parent_version` it was built from. A shadow is stale
  when that no longer matches the published parent.
- Empty overrides inherit the latest parent value; local overrides survive a
  rebuild.
- Recursive component references are blocked server-side.

## Parent edits reach every page — no re-publishing

Updating a parent propagates to consumer pages on three layers:

1. **Fan-out on save.** Saving the parent finds every consumer and rebuilds
   only the stale shadows. The first 50 pages are synced inside the save
   request; the rest continue in WP-Cron batches of 50 (nothing is dropped).
2. **Complete consumer discovery.** Consumers are found through the
   `_ec_component_ref` index *and* by scanning instance data, on **any** post
   type — including Theme Builder headers/footers/popups. Pages that predate the
   index (imports, duplicates, REST/MCP-created pages) are found and back-filled.
3. **Self-healing frontend.** If a page is still stale when it is viewed (cron
   disabled, parent restored from a revision, ...), the shadow is rebuilt before
   Elementor renders it, persisted once, and the page's caches are dropped.

Every write the plugin makes outside `Document::save()` also clears what an
editor Update would: the page CSS file, Elementor's rendered-HTML cache
(`_elementor_element_cache`, 24h by default), and the WordPress post cache
(`clean_post_cache`). WP Rocket, W3 Total Cache, WP Super Cache and LiteSpeed
Cache are purged for the page explicitly (an editor Update does this through
`save_post`, a background sync does not). Other cache layers can hook
`ec_consumer_synced` (`$post_id`).

If WP-Cron is disabled (`DISABLE_WP_CRON`), make sure a real cron hits
`wp-cron.php`; otherwise pages beyond the first 50 update on their next view.

## Performance

- **Control policies are cached.** Which controls an instance may override is
  derived from each widget's control stack (hundreds of controls). It is
  computed once per widget type and stored in the `ec_control_policy_cache`
  option, keyed by a fingerprint of Elementor/plugin versions, active plugins
  and breakpoints, with a 24h ceiling and a flush on plugin/theme updates.
- **One editor request per page, not per instance.** On load the editor sends a
  single batched request (`ec_component_shadow_batch`, 50 instances per chunk)
  no matter how many load events fire. Instances whose children already match
  the parent keep their live views: the server returns their state without
  `elements`, so nothing is deleted and recreated.
- **Parent versions are cached on the component** (`_ec_component_version`),
  keyed by a hash of the parent data and the plugin version. Rendering a page or
  checking a batch compares two strings instead of decoding, normalizing and
  hashing the parent tree. Edits made by any path (including revision restores)
  change the key and recompute automatically.
- **The picker list is cached** (`ec_components_list` transient) and flushed
  whenever a component is created, renamed, published, trashed or deleted. It is
  read whenever the instance widget's controls are built, including on frontend
  renders, so it no longer queries the database each time.
- **CSS is pre-warmed in the background.** A rebuilt page loses its CSS file.
  Cron batches regenerate it (10 pages per run, 10 s budget, published pages
  only), never inside the request that saved the parent, so a big fan-out does
  not become a stampede of cold renders on first view. Disable with
  `add_filter( 'ec_prewarm_css', '__return_false' )`.

## Compact storage (experimental, off by default)

By default every instance stores a full copy of its component. With
**Templates → Component Sync → Compact storage** enabled, saves keep only
`component_id`, the sparse overrides and a version stamp. On a site with a
300-element component on 100 pages that removes roughly 30,000 duplicated
element records from the database, revisions, exports and the editor payload.

- The children are rebuilt from the parent when the page renders (only on a
  render-cache miss) and when its CSS is generated, through Elementor's public
  `add_child()` and the `elementor/element/before_parse_css` hook.
- Rendering does not depend on the setting: any instance without stored children
  is expanded, so pages created through an API also render. The setting only
  decides whether saves strip the children.
- Existing pages keep their copies until they are saved or force-synced
  (**Sync all (rebuild everything)** / `wp ec sync --all --force`).
- Trade-off: those pages depend on this plugin. Turn the setting off and force a
  full sync before uninstalling, otherwise the component content is gone.
- Also available as a filter: `add_filter( 'ec_compact_storage', '__return_true' )`.

## Maintenance: Component Sync screen and WP-CLI

**Templates → Component Sync** lists every component in use with the number of
pages using it and how many are out of date, and can sync one component, all
out-of-date pages, or everything (forced). Components that were deleted or
unpublished are flagged.

    wp ec status                     # usage and out-of-date counts
    wp ec sync 123                   # sync one component, synchronously
    wp ec sync --all --force         # rebuild every page (applies the storage mode)
    wp ec sync 123 --no-warm         # skip CSS regeneration

WP-CLI runs without WP-Cron or time limits, which suits cron-less hosts and
migrations.

## Development

    php tests/component-*.php     # PHP suites (standalone, WordPress and Elementor stubbed)
    node tests/editor-sync-batch-test.js
    node --check assets/editor.js

## MVP property types

- Heading and subheading text
- Description text
- Button label and link
- Button background and text colors

Other Elementor controls, repeaters, dynamic tags, and responsive values are
not automatically exposed.

## Security model

- **Sanitization**: Components are stored as raw Elementor layout JSON, exactly
  like Elementor's own templates. Users without the `unfiltered_html`
  capability get their content kses-filtered on every write path
  (`ec_save_component` and the instance sync fan-out; the sync filter applies
  to the rebuilt component subtrees only, never to the rest of the page), mirroring Elementor's
  own `Document::save()` gate — so a low-privileged editor cannot plant raw
  `<script>` content into the site-wide shared library.
- **Authorization**: `ec_save_component` requires `edit_posts` (plus a
  per-user nonce); `ec_component_shadow` additionally requires `edit_post` on
  both the consumer post and the component. Both endpoints are
  authenticated-only (`wp_ajax_`, no `nopriv`).
- **Limits**: at most 200 components per user, 20 levels of nesting and 2,000
  elements per saved tree, and batched fan-out (50 pages per
  request/cron run) so a large consumer set can never stall the save request.
- **Sync scope**: consumer sync skips trashed, auto-draft and revision posts,
  and never rewrites other component documents (nested components are not
  cascaded).
- **Uninstall**: deleting the plugin removes all component library posts and
  the `_ec_component_ref` index; consumer pages are left intact (their
  `_elementor_data` is not rewritten).
