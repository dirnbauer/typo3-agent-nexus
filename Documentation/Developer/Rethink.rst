:navigation-title: Rethink

..  include:: /Includes.rst.txt
..  _rethink:

==================================
What each demo proves, or does not
==================================

A standing, honest review of the five protocol demos: what each one actually
demonstrates on a real installation, what it stops short of, and which parts of
the extension are carried rather than used. Kept next to the code so it is
revised when the code is, not written once for a release.

Read it before adding a sixth protocol or a second way of doing something that
already has one.


What each demo proves
=====================

A2UI — the agent designs the form
---------------------------------

**Proves.** That a site can let an agent decide what a form looks like without
letting it decide what the site may render. The agent answers with a surface, a
flat list of components referencing their children by id; every component is
checked against the official basic catalogue of the requested A2UI version and
anything outside it — an unknown component, an unknown property — is dropped
before it reaches the page. The catalogue is the security boundary, and a test
keeps it identical to the vendored catalogue schema. Actions travel back as the
specification's ``action`` message with the data model in the transport
metadata, and the answer replaces the surface with a confirmation.

**Does not prove.** Anything about layout quality. The agent picks from the
eighteen basic components; it cannot introduce a new one, which is the point,
but it also means the surfaces all look alike. Nothing here exercises
incremental rendering: the messages arrive in one response.

AG-UI — watch the run, then approve it
--------------------------------------

**Proves.** That an agent run can be shown as it happens and still be gated. The
endpoint streams AG-UI 1.0 events over SSE, reasoning and text arrive as deltas,
and before any write the run finishes with an ``interrupt`` outcome. Only the
next run of the same thread, answering exactly that interrupt, applies the
change — and a replayed or foreign answer is refused. This is the demo where the
approval gate is load-bearing rather than illustrated.

**Does not prove.** Resumption across a page reload, or more than one concurrent
run per visitor. The lead it writes is a single flat record.

A2A — delegate a task to the site agent
---------------------------------------

**Proves.** Discovery and delegation as separate steps. The Agent Card is a real
public document at the well-known address, the JSON-RPC binding accepts
``SendMessage`` and ``SendStreamingMessage`` with ``A2A-Version`` negotiation,
and the task walks the 1.0 lifecycle including ``TASK_STATE_INPUT_REQUIRED``,
which resumes the same task rather than starting a new one. Artifacts stream in
chunks, ``GetTask`` and ``ListTasks`` read the stored task, and every payload is
checked against the schema generated from the specification's proto.

**Does not prove.** Authentication. The card advertises none, because the demo
has none; a production A2A agent would. Nor does it prove interoperability with
another implementation — the conformance tests show the payloads are what the
specification says, not that a second agent has ever called this one.

UCP — let a shopping agent check out
------------------------------------

**Proves.** That prices are never a model's opinion, and that completing a
purchase is a separate, approved act. The shopping agent reads the business
profile, creates a checkout session priced from the real catalogue
(:php:`Merchant`), and ends its AG-UI run with an interrupt. Only an explicit
approval makes it send ``complete_checkout`` — with exactly the approved
contents, which :php:`CompletionGuard` enforces. Idempotency keys, the
``UCP-Agent`` header and the error shapes follow UCP 2026-08-25.

**Does not prove.** Payment, inventory, tax, or shipping — the only payment
handler is a sandbox one and every order is simulated. The catalogue is four
products.

AP2 — prove the purchase was authorised
----------------------------------------

**Proves.** The AP2 v0.2.0 mandates themselves, by running them: a sandbox of
five roles — trusted surface, shopping agent, merchant, credential provider and
payment processor — mints the open and closed checkout and payment mandates as
SD-JWTs, and each verifier checks signatures, key binding, audience, lifetime,
the checkout hash and every constraint before anything is authorised. The
figures shown by the Protocol info element come from an actual verification,
not from prose about one.

**Does not prove.** Anything about key management. The installation generates
its own sandbox keys and keeps them in the TYPO3 registry; a real deployment's
hardest problem — where the user's key is, who may sign with it, how a mandate
is revoked — is entirely out of scope here.


What is missing
===============

**The visitor sees the outcome, not the wire.** Since 4.0 the traffic log keeps
every exchange a widget makes — request, response and each streamed frame — and
the inspector shows the task, run, checkout or mandate it produced, so an editor
can open exactly what a visitor's widget did. The visitor still cannot: the
frontend shows the outcome and, in some widgets, a strip of event names, never
the envelope. A disclosure under each widget that reads the same recording is
the obvious next step, and it wants one module used in five places.

**No second implementation has ever called A2A or UCP.** Both are defined so that
somebody else's agent can drive them. The conformance tests prove the payloads
match the official schemas; they do not prove that another agent gets along with
this one. A CLI client in :file:`Tests/` that speaks to the endpoints over HTTP,
or a run against an official SDK, would settle it.

**The demos never fail on purpose.** Every error path — a refused approval, a
malformed surface, an expired mandate, a rate limit — is covered by the
functional tests and unreachable from the frontend widgets. A visitor cannot see
what the guard rails do, which is half of what these protocols are for.

**Translations stop at the backend.** The label files cover the modules and the
FlexForms; every string a visitor reads is English, written into templates and
into the seeder. Nothing is broken by this, but the frontend is not translatable
without editing templates.


What is dead weight
===================

**The Desiderio rendering set.** Deprecated in 3.1. It used to supply a Desiderio
variant of every plugin template; those variants wrapped each element in a
section, a container, a card and a protocol badge, inside the section, container
and frame a content element already has — doubling the page padding, nesting a
max-width inside the same max-width and printing the badge twice. The templates
went in 3.1. The integration that matters was never markup:
:file:`nexus-tokens.css` maps every ``--anx-*`` neutral onto the shadcn
variables a Desiderio site publishes, so the widgets follow the host theme
without a component wrapper. Since 4.0 the set only depends on
``webconsulting/agent-nexus`` and ``webconsulting/desiderio``, so configured
sites keep resolving — a site that lists a set TYPO3 cannot find does not load
at all. It goes in 5.0.

**The ``Agentstack`` namespace.** Left over from the extension this one replaced.
The module identifiers were renamed to ``agentnexus_*`` in 4.0 — the old ones
stay as aliases, and an upgrade wizard carries group and user permissions over,
because ``be_groups.groupMods`` references modules by name and a silent rename
revokes access. The PHP namespace is internal and can be renamed at any time.

**Removed in 4.0.** The legacy CTypes (``a2uiintegration_inquiry`` and its four
siblings) and the pre-3.0 icon identifiers; the ten per-protocol log tables,
replaced by the object store and the traffic log; the eID endpoints, replaced by
the API router.


Rules this codebase keeps
=========================

**Derive, never restate.** Every figure an interface shows comes from the service
that owns it — :php:`ProtocolCatalog` reads the A2UI catalogue, the A2A skills,
the AG-UI event catalogue, the merchant catalogue, a real mandate verification
and the route registry. A number typed into a template is a bug waiting for the
code to move.

**Converge, do not just create.** ``agentnexus:seed-site`` is idempotent in the
strong sense: a second run restores a renamed page's slug and a dragged element's
position, and changes nothing when there is nothing to change. Anything that only
produces the right state on a first run will be wrong on every installation that
already exists.

**Fluid reads properties, not methods.** ``{status.healthLabel}`` resolves
``getHealthLabel()``, ``isHealthLabel()``, ``hasHealthLabel()`` or a public
property — and nothing else. A derived value that a template needs is a computed
property on the DTO. A method renders as an empty string, silently, which once
took the whole overview module down with a null icon identifier.

**One reference, never a reference to a reference.** Each content type does
``=< lib.contentElement`` directly. An intermediate object reads better and
resolves to nothing, because ``=<`` is resolved at render time; a copy (``<``)
resolves but is taken before the sets that depend on this one have finished
configuring ``lib.contentElement``. See :file:`Configuration/Sets/AgentNexus/setup.typoscript`.
