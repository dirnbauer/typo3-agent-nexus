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
checked against :php:`ComponentRegistry` and anything unregistered — an unknown
component, an unknown property — is dropped before it reaches the page. The
catalogue is the security boundary, and it is small enough to read.

**Does not prove.** Anything about layout quality. The agent picks from twenty
components; it cannot introduce a new one, which is the point, but it also means
the surfaces all look alike. Nothing here exercises streaming: the surface
arrives in one response.

AG-UI — watch the run, then approve it
--------------------------------------

**Proves.** That an agent run can be shown as it happens and still be gated. The
endpoint streams typed events over SSE, reasoning and text arrive as deltas, and
before any write the run emits a confirm tool call and stops. Nothing is stored
without a human decision. This is the only demo where the approval gate is
load-bearing rather than illustrated.

**Does not prove.** Resumption across a page reload, or more than one concurrent
run per visitor. The lead it writes is a single flat record.

A2A — delegate a task to the site agent
---------------------------------------

**Proves.** Discovery and delegation as separate steps. The Agent Card is a real
public document, the JSON-RPC entry point accepts ``message/send`` and
``message/stream``, and the task walks a real lifecycle including
``input-required``, which resumes the same task rather than starting a new one.
Artifacts stream in chunks.

**Does not prove.** Authentication. The card advertises none, because the demo
has none; a production A2A agent would. Nor does it prove interoperability — the
only client that has ever called it is the concierge widget in this extension.
Interop is the one claim on this page that a second implementation would have to
settle.

UCP — let a shopping agent build the cart
------------------------------------------

**Proves.** That prices are never a model's opinion. The agent reads the
manifest, assembles the cart from the real catalogue, and the amounts come from
:php:`Merchant`; the stream halts at ``authorization.required`` and only an
explicit approval produces ``order.confirmed``.

**Does not prove.** Payment, inventory, tax, or shipping — there is no payment
network behind it and every order is simulated. The catalogue is four products.

AP2 — prove the purchase was authorized
----------------------------------------

**Proves.** The mandate chain itself, and it proves it by running it: two signed
mandates are minted, the Cart mandate references the Intent mandate, and both
signatures, the reference, the merchant and the spending cap are verified. The
figures shown by the Protocol info element come from an actual verification, not
from prose about one.

**Does not prove.** Anything about key management. The sandbox key lives with the
extension; a real deployment's hardest problem — where the user's key is, who
may sign with it, how a mandate is revoked — is entirely out of scope here.


What is missing
===============

**The wire is only visible in the backend.** The five backend consoles show the
actual frames going past; the frontend demos show the outcome. A visitor on
:file:`/a2a` sees a task complete but never the JSON-RPC envelope that did it,
which is the most interesting thing on the page for the audience this site has.
A shared disclosure under each widget — the request that went out, the frames
that came back — is the single highest-value thing left to build, and it wants
one module used in five places, not five implementations.

**No second implementation has ever called A2A or UCP.** Both are defined so that
somebody else's agent can drive them. Until one has, the interop claim is
untested. A CLI client in :file:`Tests/` that speaks to the endpoints over HTTP
would settle it and would cost little.

**The demos never fail on purpose.** Every error path — a refused approval, a
malformed surface, an expired mandate, a rate limit — exists in the code and is
unreachable from the frontend. A visitor cannot see what the guard rails do,
which is half of what these protocols are for.

**Translations stop at the backend.** The label files cover the modules and the
FlexForms; every string a visitor reads is English, written into templates and
into the seeder. Nothing is broken by this, but the frontend is not translatable
without editing templates.


What is dead weight
===================

**The Desiderio rendering set.** Deprecated in 3.1 and removed in 4.0. It used to
supply a Desiderio variant of every plugin template; those variants wrapped each
element in a section, a container, a card and a protocol badge, inside the
section, container and frame a content element already has — doubling the page
padding, nesting a max-width inside the same max-width and printing the badge
twice. The templates are gone. The integration that matters was never markup:
:file:`nexus-tokens.css` maps every ``--anx-*`` neutral onto the shadcn variables
a Desiderio site publishes, so the widgets follow the host theme without a
component wrapper. The set survives only so configured sites keep resolving.

**The ``Agentstack`` namespace and the ``agentstack_*`` module identifiers.**
Left over from the extension this one replaced. Everything else was renamed to
``agentnexus`` in 3.0. The namespace is internal and could be renamed at any
time; the module identifiers cannot, because backend group permissions
(``be_groups.groupMods``) reference them by name and a rename silently revokes
access. They stay until 4.0, where the migration can be documented.

**The legacy CTypes.** ``a2uiintegration_inquiry`` and its four siblings render
through aliases and are excluded from the element wizard. The upgrade wizard
rewrites them. They go in 4.0, together with the pre-3.0 icon identifiers kept
as aliases in :file:`Configuration/Icons.php`.

**Per-protocol ``SeedDemoCommand`` classes.** *Removed in 3.1.* Five commands
that predated ``agentnexus:seed-site``: they wrote a content element straight
into the database — no slug, no reference index, no history — below a page uid
that defaulted to ``671``, a page from the installation they were written on.
Nothing referenced them. ``agentnexus:seed-site`` does the job properly.


Rules this codebase keeps
=========================

**Derive, never restate.** Every figure an interface shows comes from the service
that owns it — :php:`ProtocolCatalog` reads the A2UI registry, the A2A skills,
the AG-UI event families, the merchant catalogue and a real mandate chain. A
number typed into a template is a bug waiting for the code to move.

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
