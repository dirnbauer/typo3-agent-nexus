:navigation-title: Site setup

..  include:: /Includes.rst.txt
..  _site-setup:

==========
Site setup
==========

There are two ways to get the frontend demos onto a page: let the extension
build a complete demo site, or add its site set to a site you already have.

Build the demo site
===================

..  code-block:: bash

    vendor/bin/typo3 agentnexus:seed-site --base=https://example.org/

That creates, through DataHandler — so slugs, sorting, history and the reference
index behave exactly as they would in the backend:

..  code-block:: text

    Agent Nexus          /              siteroot, intro + protocol hub
      A2UI               /a2ui          intro + Inquiry demo + protocol info
      AG-UI              /ag-ui         intro + Assistant demo + protocol info
      A2A                /a2a           intro + Concierge demo + protocol info
      UCP                /ucp           intro + Checkout demo + protocol info
      AP2                /ap2           intro + Trusted surface demo + protocol info
      Playground         /playground    intro + all five demos
      Docs               /docs          intro + a hub as the specification index
      data               (sysfolder)    storage pid for inquiries, leads, orders

It also writes the site configuration, adds the ``webconsulting/agent-nexus``
set to it, and records the storage folder as the ``agentNexus.storagePid`` site
setting.

Each element is created with the FlexForm defaults an editor would have got:
DataHandler does not apply them — the backend form writes them on an editor's
first save — so a programmatically created widget would otherwise have an empty
input and no intro.

**The command converges, it does not just create.** Every record it writes
carries a logical key in :sql:`tx_agentnexus_seed_key`, so a second run updates
exactly the same rows — even after an editor renamed, moved or reordered them —
and never duplicates anything. A renamed page gets its slug back and a dragged
element its position; when nothing has drifted, the run writes nothing at all.
Records without such a key were not seeded and are never touched.

Options
-------

..  confval:: --site-identifier
    :name: seed-site-identifier
    :type: string
    :Default: agent-nexus

    Identifier of the site configuration to create or update.

..  confval:: --base
    :name: seed-base
    :type: string (repeatable)

    The site base. Repeat it to add base variants: the first is the default
    base, every further one becomes a variant conditioned on the Production
    application context.

..  confval:: --root
    :name: seed-root
    :type: int

    Seed below an existing page instead of creating a new site root. The site
    that owns that page is updated — it gains the set and the storage pid —
    rather than being replaced.

..  confval:: --purge-legacy
    :name: seed-purge-legacy
    :type: int

    Soft-delete this page and everything below it before seeding. Use it to
    retire an older demo section.

..  confval:: --purge-placements
    :name: seed-purge-placements
    :type: string

    Comma-separated page uids to remove every ``agentnexus_*`` content element
    from before seeding. Other content on those pages is untouched.

..  confval:: --hidden
    :name: seed-hidden
    :type: bool

    Create the pages hidden, so the site can be reviewed before it goes live.

..  confval:: --dry-run
    :name: seed-dry-run
    :type: bool

    Report what would change and write nothing.

Add the plugins to an existing site
===================================

Add one site set in your site configuration:

..  code-block:: yaml
    :caption: config/sites/<identifier>/config.yaml

    dependencies:
      - webconsulting/agent-nexus

That set maps every ``agentnexus_*`` content element onto ``lib.contentElement``
with the ``AgentNexusPlugin`` template and registers the plugin view paths and
the storage pid.

..  _site-setup-desiderio:

With the Desiderio design system
--------------------------------

Nothing extra is needed. The widgets take their neutrals from the host theme:
:file:`nexus-tokens.css` maps every ``--anx-*`` token onto the shadcn variables
a Desiderio site publishes (``--card``, ``--border``, ``--muted-foreground`` and
the rest), so they follow its palette, spacing scale and dark mode on their own.

..  deprecated:: 3.1
    ``webconsulting/agent-nexus-desiderio`` still resolves, so a site that lists
    it keeps building — TYPO3 refuses to build a site whose dependencies name an
    unknown set. It adds nothing beyond depending on ``webconsulting/agent-nexus``
    and ``webconsulting/desiderio``, and it is removed in 5.0. List those two
    sets instead.

    It used to add a higher-priority template root whose variants wrapped each
    element in a section, a container, a card and a protocol badge — inside the
    section, container and frame a content element already has. That doubled the
    page padding around every demo, nested a max-width inside the same
    max-width, drew a card inside a card and printed the badge twice.
