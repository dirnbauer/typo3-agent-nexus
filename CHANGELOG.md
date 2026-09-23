# Changelog

All notable changes to Agent Nexus are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows [Semantic Versioning](https://semver.org/).

## [4.0.3] — 2026-09-23

### Changed

*   The seeded AP2 page describes the AP2 v0.2 flow: you sign one open
    mandate with what the agent may buy and your spending cap, and the agent
    closes it with its own key. It still described the two v0.1 mandates
    (intent and cart).

## [4.0.2] — 2026-09-23

### Fixed

*   PHPStan 2.2.15 knows that `openssl_pkey_export()` and `openssl_sign()`
    write a string on success; the two redundant `is_string()` checks in
    `EcKey` failed the level 8 analysis of the 4.0.1 CI run. `phpstan/phpstan`
    is required at `^2.2.15`.

## [4.0.1] — 2026-09-23

### Fixed

*   **A2UI forms are no longer cut off.** Every protocol has an output budget
    of its own (`a2uiLlmMaxOutputTokens`, `aguiLlmMaxOutputTokens`,
    `a2aLlmMaxOutputTokens`, `ucpLlmMaxOutputTokens`,
    `ap2LlmMaxOutputTokens`). A2UI gets 1600 tokens: measured on real prompts
    (GPT-5.6 Terra, reasoning included) a generated form takes 532 to 897,
    more than the old shared ceiling of 700 allowed. The other defaults keep
    what each protocol asked for before (AG-UI 700, A2A 400, UCP and AP2 160).
*   **A cut-off answer is a clear fallback.** When a model stops at its budget
    (finish reason `length`), the half answer is thrown away: A2UI's built-in
    generator answers, the A2A artifact, the UCP rationale and the AP2
    explanation fall back to their scripts, and the provenance says why
    (`"reason": "the model answer was cut off at 1600 output tokens"`; for A2A
    `fallback` in the artifact metadata and `routingFallback` in the task
    metadata). The spent tokens still go into the usage ledger. A streamed
    AG-UI answer that stops mid-sentence is closed with a scripted sentence and
    marked in its provenance.
*   **The traffic log and the inspector lists page again.** A list longer than
    one page crashed on `paginator.totalAmountOfItems`, a protected method; the
    total is now counted and passed on. Functional tests render both lists with
    more than one page of rows.
*   **The overview speaks German in a German backend.** The protocol cards
    showed English descriptions and an English reason for the scripted mode;
    both come from the label files now.
*   Two German FlexForm labels translated an older English text; one FlexForm
    description still spoke of eID endpoints.

### Added

*   **MCP in the overview.** Agent Nexus does not implement MCP; when
    hn/typo3-mcp-server is installed, the specification table says "Provided
    by typo3-mcp-server 0.9.1", lists the protocol versions it declares
    (2025-11-25, 2026-07-28) and links to its backend module, otherwise "Not
    installed". The package stays optional: Agent Nexus reads its Composer
    version and its capability manifest and never references its classes.
*   **Known spec conflicts.** UCP 2026-08-25 constrains `ap2.checkout_mandate`
    with a pattern that rejects every AP2 v0.2.0 mandate, AP2's own examples
    included. A local schema overlay
    (`Resources/Private/Schemas/Overlays/ucp/2026-08-25/common/payment_ap2_mandate.json`)
    accepts both forms without touching the vendored official schema;
    `Documentation/Protocols/KnownSpecConflicts.rst` documents the patterns,
    an example token and how to use the overlay in your own validator, and
    conformance tests prove that upstream tokens and AP2 chains pass while
    malformed ones still fail.
*   Tests that every English label has a current German translation and that
    backend templates take their text from the label files.

### Changed

*   `llmMaxOutputTokens` is the fallback budget of a protocol whose own budget
    is `0`, no longer a ceiling above every protocol. An installation that
    lowered it to save cost sets the per-protocol budgets instead.
*   `LlmGuard::maxOutputTokens()` takes the protocol:
    `maxOutputTokens(Protocol $protocol, ?int $requested = null)`.
    `LlmGuard::allows()` also returns a `code` for screens that translate the
    reason.

## [4.0.0] — 2026-09-23

Every protocol now speaks the current published version of its specification,
and every payload it emits is tested against that specification's official JSON
Schema. The backend becomes a TYPO3 v14 module set with an inspector and a live
traffic log. This is a major release: the endpoints moved, the wire formats
changed and the 3.x tables are no longer used. Read the upgrade notes in
`Documentation/Installation.rst` before updating.

| Protocol | 3.1 | 4.0 | Latest published (checked 2026-09-23) |
| --- | --- | --- | --- |
| A2A | 0.3.0 (two methods) | 1.0, with the 0.3 dialect on request | 1.0.1 |
| AG-UI | pre-1.0 draft events | 1.0 | 1.0 |
| A2UI | early "v1.0" draft, own catalogue | v0.9.1, the v1.0 candidate on request | v0.9.1 |
| UCP | home-made "0.1" manifest | 2026-08-25 | 2026-08-25 |
| AP2 | Intent/Cart mandates, HS256 | v0.2.0, SD-JWT, ES256 | v0.2.0 |
| MCP | — | not implemented (referenced) | 2026-07-28 |

### Changed — breaking

*   **One API router instead of eIDs.** A frontend middleware in front of site
    resolution serves every endpoint below `/api/agent-nexus` (the new
    `apiBasePath` setting) and the discovery documents where the
    specifications pin them: `/.well-known/agent-card.json` and
    `/.well-known/ucp`. Paths such as `/a2a/rest/tasks/{id}:cancel` or
    `/ucp/checkout-sessions/{id}` could not be expressed as eIDs. Every eID is
    gone; `Documentation/Installation.rst` maps each old eID to its new path.
*   **A2A 1.0.** The eleven 1.0 methods (`SendMessage`,
    `SendStreamingMessage`, `GetTask`, `ListTasks`, `CancelTask`,
    `SubscribeToTask` …) over JSON-RPC at `/a2a/jsonrpc` and HTTP+JSON below
    `/a2a/rest`, ProtoJSON enum names, the Agent Card built around
    `supportedInterfaces`, errors with the section 5.4 codes and a
    `google.rpc.ErrorInfo`, and a real task store: a paused task resumes when a
    message names its `taskId`. A request without `A2A-Version` is answered in
    the 0.3 dialect through one translator; other versions get
    `VersionNotSupportedError`. Push notifications and the extended card are
    declared off and refused with the errors the specification names.
*   **AG-UI 1.0** at `POST /ag-ui`. Reasoning spans, `ACTIVITY_SNAPSHOT` for
    the plan comparison, and the approval as the run's `interrupt` outcome,
    answered by `resume` on the next run of the same thread. The endpoint
    refuses with 409 what it must never do — answer an interrupt it did not
    raise or that was already answered, reuse a run id, start a run while an
    interrupt waits — and with 400, 413 or 429 what is malformed, too large or
    too frequent. In Development and Testing context every stream passes the
    stream verifier while it is sent.
*   **A2UI v0.9.1** with the official basic catalogue: `createSurface`,
    `updateComponents`, `updateDataModel`, `deleteSurface` and the renderer's
    `action` message with the data model in `a2uiClientDataModel`. The v1.0
    release candidate is served when a client asks for it by version or
    capabilities. The home-made components (`Textarea`, `ButtonGroup` …) and
    removed fields (`surfaceProperties`, `wantResponse`) are gone; model output
    is forced onto the catalogue, older shapes repaired. Endpoints:
    `POST /a2ui/surfaces`, `POST /a2ui/actions`, `GET /a2ui/catalog`; surfaces
    are stored as protocol objects. `a2ui:generate` prints the messages for an
    intent on the command line and validates them.
*   **UCP 2026-08-25.** A sandbox business: the profile at `/.well-known/ucp`
    (cacheable, with an ETag), the checkout capability's REST binding (create,
    get, update, complete, cancel) with `UCP-Agent` parsed as an RFC 8941
    dictionary, `Request-Id`, `Idempotency-Key` replay and conflict, and the
    specification's error bodies. The shopping agent at `POST /ucp/agent`
    speaks AG-UI 1.0 to the visitor and UCP to the business, stops with an
    interrupt and sends `complete_checkout` only on approval, exactly as
    approved.
*   **AP2 v0.2.0.** SD-JWT checkout and payment mandates, open and closed,
    signed with ES256 by a sandbox of five roles (trusted surface, shopping
    agent, merchant, credential provider, payment processor) whose keys are
    created on first use and kept in the TYPO3 registry. The chain verifier
    follows the reference SDK's `verify_chain`; constraints, line items,
    receipts and single use are checked. The Trusted Surface element approves
    a shopping list and a spending cap once and shows the signed chain; a
    purchase over the cap ends in `unresolved_constraint`, AP2's signal to ask
    the person again. Endpoints: `GET /ap2/jwks.json` and
    `POST /ap2/authorize`. The HS256 Intent and Cart mandates are gone.
*   **The backend is a v14 module set.** A main module with an overview, one
    section per protocol whose screens are third-level modules switched in the
    DocHeader module menu, an inspector and a traffic log. The module
    identifiers are now `agentnexus_*`; the 3.x `agentstack_*` identifiers stay
    as aliases. Run the upgrade wizard *Agent Nexus: migrate module
    permissions* to carry group and user permissions over. Backend AJAX routes
    are renamed to `agentnexus_*` and inherit the access of the screen they
    serve.
*   **New storage.** `tx_agentnexus_object` keeps A2A tasks, AG-UI runs, UCP
    checkout sessions, AP2 mandates and A2UI surfaces in their specification's
    own JSON shape with a state history; `tx_agentnexus_traffic` keeps the
    traffic log. The ten per-protocol log and capture tables of 3.x are not
    migrated — export what you need, then remove them with *Analyze Database
    Structure*.
*   **One `agentnexus` cache** in no flush group replaces the five caches named
    `a2ui`, `agui`, `a2a`, `ucp` and `ap2`, so clearing caches during a demo
    does not reset a visitor's rate limit.
*   **Requirements:** PHP 8.4+ with `ext-openssl` (for the AP2 signatures).

### Added

*   **Inspector** — one screen per protocol object, filterable by state, caller
    and text, paginated. The detail view reads each object in its
    specification's shape (messages and artifacts, interrupts and results, line
    items and totals, claims and checks), its state history, the traffic that
    touched it and the objects of the same context.
*   **Traffic log** — every exchange of the protocol endpoints and the backend
    consoles: request, response and every streamed frame with its offset.
    Filterable by protocol, caller, outcome, period and text; "Follow live"
    polls for new entries and announces them through an aria-live region.
    Headers are allow-listed, personal data is masked (also inside strings that
    hold JSON, which is how AG-UI carries tool arguments) and bodies are capped
    before anything is stored; client IP addresses are never recorded.
*   **`agentnexus:cleanup`** applies `trafficRetentionDays` (14) and
    `objectRetentionDays` (90); `--dry-run` shows what it would delete. It runs
    from cron or the scheduler's *Execute console commands* task.
*   **Settings:** `apiBasePath`, `publishWellKnown`, `trafficEnabled`,
    `trafficRetentionDays`, `trafficCaptureBodies`, `trafficRedactPersonalData`,
    `objectRetentionDays`, `ucpTermsOfServiceUrl`, `ucpPrivacyPolicyUrl`.
*   **An overview that states the specification versions**: one card per
    protocol (health, implemented version, routes, mode, activity in the last
    24 hours), the table of implemented against latest published versions, the
    discovery documents this host publishes, recent objects and a setup list.
*   **Native backend screens per protocol**: the A2UI playground and catalogue,
    the AG-UI run console and event reference, the A2A task console and Agent
    Card screen, the UCP checkout console and business profile, the AP2 mandate
    studio and mandate reference. The consoles call the public endpoints the
    way another client would, and every reference screen is printed from the
    code that serves the protocol.
*   **Conformance tests.** The official JSON Schemas of A2A 1.0 (generated from
    the proto, plus the proto's required fields), AG-UI 1.0, A2UI v0.9.1 and
    v1.0, UCP 2026-08-25 and AP2 v0.2.0 are vendored under
    `Tests/Conformance/Schemas`, each with its licence and a `SOURCE.txt`
    naming the commit and the SHA-256 of every file. Every emitted payload is
    validated against them with a draft-compliant validator (`default` is an
    annotation, never inserted into the data).
*   **Functional tests** for every endpoint through the frontend middleware
    stack and every backend screen, on SQLite and MariaDB; CI runs PHP 8.4 and
    8.5 and checks XLIFF parity.
*   The Protocol info and Protocol hub elements list the routes the router
    actually serves, with method, path and binding, and show the implemented
    specification version.
*   `Documentation/Protocols/SpecVersions.rst`: the specification delta, per
    protocol. The GPL-2.0 licence text.

### Changed

*   The frontend widgets are clients of the public endpoints — the same ones
    another agent would call — and send their element, page and URL inside each
    protocol's own extension point, so the element's model settings are read
    server side.
*   The frontend stylesheet is split into a shell and one file per widget.
    Protocol accents get a text colour that meets 4.5:1 in light and dark mode,
    and every control of every widget shares one focus ring drawn in it.
*   Services that hold nothing but their collaborators are readonly; the
    protocols depend on `LanguageModel` and `UsageLedger` interfaces instead of
    the final classes.
*   The rate limiter fails open on every cache error, not only a missing cache.
*   Demo content (from the content work merged into this release, commits
    `bd19fea` and `7f3cb9b`): plain English and British spelling for the
    seeded pages, catalogues and demo defaults, and for the visitor-facing
    labels in the templates and scripts ("Approve this purchase", "Live model"
    / "Scripted demo", "Same approved merchant"). Titles, slugs, seed keys,
    event names and the words the demo agents route on are unchanged.
*   Development dependencies: `webconsulting/coding-standards` 0.9,
    `friendsofphp/php-cs-fixer` 3.95.27, `phpstan/phpstan` 2.2.14,
    `phpunit/phpunit` 13.3, `typo3/testing-framework` 9.7,
    `@mermaid-js/mermaid-cli` 11.17; `opis/json-schema` added for the
    conformance suite; `dg/bypass-finals` dropped.

### Deprecated

*   The `webconsulting/agent-nexus-desiderio` site set. It carries no
    templates and only depends on `webconsulting/agent-nexus` and
    `webconsulting/desiderio`; list those two directly. It is removed in 5.0.

### Removed

*   The legacy CTypes of the five per-protocol packages
    (`a2uiintegration_inquiry` and its siblings) and their TypoScript aliases.
    The wizard *Agent Nexus: migrate legacy content element types* stays; run
    it before updating if such records remain.
*   The pre-3.0 icon identifiers kept as aliases.
*   Every eID endpoint, the Extbase backend controllers, the per-protocol
    loggers and stores, the HS256 JWT helper, the unused motion script and the
    label file of the retired A2UI dashboard.

### Fixed

*   An earlier commit of this release (`4438d26`) removed the
    `webconsulting/agent-nexus-desiderio` set on the assumption that TYPO3
    ignores an unknown set in a site's dependencies. It does not: such a site
    fails to build. The set is back as a compatibility set with its original
    dependencies (see *Deprecated*); no released version was affected.
*   A2A: a new task is locked for the request that creates it, so a
    subscriber following it from another request cannot race its first write.
*   AG-UI: hidden message parts stay hidden, runs stay tied to their thread,
    and the widget says when the live model did not answer.
*   Event strips stay inside their widget instead of widening the column.
*   A rate limit window ends a fixed time after a client's first request.
    Every counted request used to re-arm it, so the lockout lasted a full
    window after the last counted request.

### Security

*   Every write still sits behind a human gate, now in the specifications' own
    terms: the AG-UI interrupt, the UCP agent's approval before
    `complete_checkout`, the AP2 mandate verification.
*   The UCP `UCP-Agent` header is validated but never fetched, so the endpoint
    cannot be used to make the server call arbitrary addresses.
*   The inspector and the traffic log show what visitors sent; the permission
    wizard deliberately does not grant them.

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
