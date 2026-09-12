# Changelog

All notable changes to Agent Nexus are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows [Semantic Versioning](https://semver.org/).

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
