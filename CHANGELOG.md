# Changelog

All notable changes to Agent Nexus are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows [Semantic Versioning](https://semver.org/).

## [3.1.0] — 2026-09-19

The release that makes the demo site worth landing on, and fixes three things
that were quietly broken.

### Added

*   **A "Protocol hub" content element** (`agentnexus_hub`): one card per
    protocol with its edge, what it is for, the countable fact the catalogue
    already derives, the endpoints this installation exposes, live health and a
    link to the running demo. Nothing on a card is written down twice — it comes
    from `ProtocolCatalog` and `ProtocolStatusService` — so a card cannot
    describe something this installation does not have. The seed puts one on the
    site root, and one with health and endpoints switched off on `/docs`, which
    turns that page into an index of the five specifications.
*   **The playground actually carries all five demos.** Its intro promised "all
    five demos side by side" over an empty page.
*   **`Documentation/Developer/Rethink.rst`**: what each demo proves, what it
    stops short of, what is missing and what is carried rather than used.

### Fixed

*   **The reading order was wrong on every protocol page** and re-seeding did
    not repair it — protocol info above the demo above the intro. Records are no
    longer positioned on create; one mechanism settles each sibling list
    afterwards, so the order is *converged on* rather than merely produced once.
    Siblings already in order produce no command, so a second run stays a true
    no-op.
*   **The whole backend section was invisible in a draft workspace.** Every
    module was declared live-only, so a user whose backend session sat in a
    workspace got "No module access" for the read-only hub as well. Nothing here
    edits versioned content, so the restriction bought nothing.
*   **The overview module crashed on every load.** Fluid resolves
    `{protocol.healthIcon}` to a getter or a property and never to a method, so
    the icon identifier was null and `IconViewHelper` fataled. The derived values
    on `ProtocolStatus` are computed properties now.
*   **Every element was framed twice.** 100–300px of empty page around each demo,
    a max-width inside the same max-width, a card inside a card and the protocol
    badge printed twice — see *Removed*.
*   **Seeded demos looked broken**: DataHandler does not apply FlexForm defaults
    (the backend form does, on an editor's first save), so every seeded widget
    had an empty input and no intro. The seed reads them from the data structure
    TCA already points at.
*   **The page scrolled sideways on a phone.** A grid item's min-width defaults
    to min-content, so the sequence diagram and the endpoint table — both of
    which scroll inside their own box — stretched the element past the viewport
    instead.
*   The AP2 widget was capped at 40rem while the other four filled their column,
    and the overview's "Playground" button had no variant class, so it rendered
    as plain text beside a real button.

### Removed

*   **The Desiderio plugin templates.** They wrapped each element in a section, a
    container, a card and a badge, inside the section, container and frame a
    content element already has. The `webconsulting/agent-nexus-desiderio` set
    still resolves so configured sites keep working, but it is deprecated, does
    nothing, and goes in 4.0 — drop it from `dependencies`. The integration that
    matters was never markup: `nexus-tokens.css` maps every `--anx-*` neutral
    onto the shadcn variables a Desiderio site publishes.
*   **`a2ui:seed:demo`, `agui:seed:demo`, `a2a:seed:demo`, `ucp:seed:demo` and
    `ap2:seed:demo`.** Five commands that predated `agentnexus:seed-site`: they
    wrote a content element straight into the database — no slug, no reference
    index, no history — below a page uid that defaulted to `671`, a page from
    the installation they were written on. Use `agentnexus:seed-site`.

### Changed

*   PHPUnit is now `^12.4 || ^13.0`; the unit suite's stubs are declared as
    stubs, which is what PHPUnit 13 asks for.

## [3.0.0] — 2026-09-13

The release that makes Agent Nexus installable anywhere, testable, and honest
about what it is doing.

### Breaking

*   **The frontend rendering glue moved into the extension.** Until now the
    TypoScript that maps `tt_content.agentnexus_*` onto `lib.contentElement`
    lived in a site package, so the extension could not render a single plugin
    on its own. Two site sets replace it: `webconsulting/agent-nexus` (plain,
    needs only fluid_styled_content) and `webconsulting/agent-nexus-desiderio`
    (the same markup framed by Desiderio's component collection). **Remove any
    equivalent TypoScript from your site package and add the set instead.**
*   **`typo3/cms-fluid-styled-content` is now required**, not assumed: a site
    set whose dependency cannot be resolved never activates.
*   **The legacy content element types are deprecated.** Records created by the
    five per-protocol extensions Agent Nexus replaced still render through
    aliases, but those go away in 4.0. Run the new *Agent Nexus: migrate legacy
    content element types* upgrade wizard. The legacy types no longer appear in
    the new content element wizard and are labelled as deprecated in the CType
    list.
*   **Icon identifiers were renamed** to `agentnexus-module[-<protocol>]`,
    `agentnexus-plugin-<name>` and `agentnexus-status-<level>`. Every pre-3.0
    identifier stays registered as an alias until 4.0.
*   **`agent-nexus:seed:frontend` is gone**, replaced by
    `agentnexus:seed-site`. The old command wrote rows straight into the
    database below a hard-coded page uid; the new one builds a complete site
    through DataHandler.
*   **GSAP was removed.** `nexus-motion.js` is now a dependency-free Web
    Animations helper. If you imported `withGsap`, `killAll` or `ensureFinished`
    from it, use `reveal`, `stagger` or `countUp` instead.
*   PHP 8.4 and TYPO3 v14.3.7 are now the minimum.

### Added

*   **`agentnexus:seed-site`** builds the whole demo site — siteroot, seven
    pages, a storage folder, demo content and the site configuration — and is
    idempotent through a new `tx_agentnexus_seed_key` column: a second run
    updates the same records even after an editor renamed or moved them.
    `--root`, `--purge-legacy`, `--purge-placements`, `--hidden` and `--dry-run`
    cover the awkward cases.
*   **A "Protocol info" content element** (`agentnexus_protocolinfo`) that
    explains one protocol next to its demo: the sequence diagram, the endpoints
    this installation exposes, how a request flows, and live figures derived
    from the services that implement it.
*   **`LegacyCTypeUpgradeWizard`** rewrites the five legacy CTypes in bulk,
    including deleted records so the recycler stays usable.
*   **A test suite**: 60 unit tests and 45 functional tests, covering all nine
    eID endpoints in deterministic mode, the seed command, the upgrade wizard
    and the hub's status objects.
*   **A quality baseline**: PHPStan level 8 with no baseline,
    typo3/coding-standards, and a CI workflow that also asserts the committed
    sequence diagrams are current.

### Changed

*   **The overview module is a hub, not a field guide.** It now answers the
    question an operator actually opens the backend with: one card per protocol
    with its health (endpoints registered, storage folder present, model on or
    off), when it last ran and how often in the last 24 hours, links that
    resolve rather than guess, the ten most recent events across all protocols,
    and a setup panel naming what is missing. The protocol explanations moved to
    the frontend element and the documentation.
*   **SSE endpoints return a response instead of exiting the request.** The
    body is a self-emitting stream, so production still flushes frame by frame
    while the request ends normally — which also means the loggers now run in a
    `finally` block in request scope rather than in a shutdown handler.
*   **One icon family.** All sixteen icons are redrawn in `currentColor` with a
    single accent, each on the grid its neighbours use: module icons as filled
    64-grid art so they sit correctly beside Core's own in the module tree,
    plugin and status icons as 16-grid line art for the wizard and the list
    module. `Extension.svg` is the same drawing as the module icon.
*   **One widget shell.** The A2UI inquiry widget joined the shared,
    token-driven shell the other four already used, so the five plugins finally
    look like one extension. Every control gained a visible focus ring, and the
    chip groups and frame panels gained accessible names.
*   `nexus-backend.css` is the hub's stylesheet only; the playground modules
    load just the token and UI layers.
*   Sequence diagrams are build artifacts under `Resources/Public/Diagrams`,
    each carrying its own light and dark palette, instead of Fluid partials.
*   Documentation rewritten as a full RST set: installation, site setup,
    configuration, a page per protocol, security boundaries and developer notes.

### Fixed

*   The seed command no longer leaves two site configurations claiming the same
    root page: TYPO3 auto-writes an `autogenerated-<uid>` site whenever
    DataHandler creates a root page, and the resulting ambiguity made the hub's
    "Live page" links point into a site nobody had configured.
*   Site settings written by the seed command are picked up immediately instead
    of on the next cache flush.
*   The seed command works when driven outside the console application (a
    `CommandTester`, another command), where nothing has created the CLI
    backend user yet.
*   78 static-analysis findings in the pre-3.0 code base — array shapes on the
    frame factories, component child and cost types, two `json_encode` false
    paths and five unnecessary nullsafe accesses — are fixed rather than
    baselined.

### Removed

*   The vendored GSAP build (~70 KB).
*   `SeedFrontendDemoCommand` (see `agentnexus:seed-site`).
*   The hand-written Mermaid Fluid partials and the old overview's protocol map,
    theory cards, comparison table, decision helper and glossary.

## [2.0.2] and earlier

See the Git history.
