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

    Agent Nexus          /              siteroot, hero intro
      A2UI               /a2ui          intro + Inquiry demo + protocol info
      AG-UI              /ag-ui         intro + Assistant demo + protocol info
      A2A                /a2a           intro + Concierge demo + protocol info
      UCP                /ucp           intro + Checkout demo + protocol info
      AP2                /ap2           intro + Trusted surface demo + protocol info
      Playground         /playground    room for every demo on one page
      Docs               /docs          where to read more
      data               (sysfolder)    storage pid for inquiries, leads, orders

It also writes the site configuration, adds the ``webconsulting/agent-nexus``
set to it, and records the storage folder as the ``agentNexus.storagePid`` site
setting.

**The command is idempotent.** Every record it creates carries a logical key in
:sql:`tx_agentnexus_seed_key`, so a second run updates exactly the same rows —
even after an editor renamed or moved them — and never duplicates anything.
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
with the ``AgentNexusPlugin`` template, registers the plugin view paths and the
storage pid, and keeps the deprecated CType aliases rendering.

..  _site-setup-desiderio:

With the Desiderio design system
--------------------------------

If the site uses :composer:`webconsulting/desiderio` (^4.1), use its set
instead — it depends on the plain one and only adds a higher-priority template
root, so each plugin resolves a variant composed from Desiderio's component
collection:

..  code-block:: yaml
    :caption: config/sites/<identifier>/config.yaml

    dependencies:
      - webconsulting/agent-nexus-desiderio

Both variants render the same markup and behave identically; only the frame
around them differs.
