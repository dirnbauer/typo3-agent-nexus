:navigation-title: Installation

..  include:: /Includes.rst.txt
..  _installation:

============
Installation
============

Requirements
============

*   TYPO3 v14.3.7 or newer
*   PHP 8.4 or newer, with ``ext-openssl`` (AP2 signs its mandates with ES256)
*   :composer:`typo3/cms-fluid-styled-content` (a requirement, not a
    suggestion: the frontend elements render through ``lib.contentElement``)

Install
=======

..  code-block:: bash

    composer require webconsulting/agent-nexus

Then apply the database schema. Agent Nexus adds three tables — the protocol
objects (:sql:`tx_agentnexus_object`), the traffic log
(:sql:`tx_agentnexus_traffic`) and the model spend ledger
(:sql:`tx_agentnexus_llm_usage`) — plus a seed-key column on :sql:`pages` and
:sql:`tt_content`:

..  code-block:: bash

    vendor/bin/typo3 extension:setup
    vendor/bin/typo3 cache:flush

Open :guilabel:`Agent Nexus > Overview` in the backend. It tells you what is
ready and what is not — whether every protocol's endpoints are routed, whether a
storage folder exists, whether a language model is reachable — which
specification version each protocol implements, and what each did in the last
24 hours.

Schedule ``vendor/bin/typo3 agentnexus:cleanup`` to run daily, so the traffic
log and the protocol objects are kept only as long as the retention settings say
(see :ref:`configuration-extension`).

Optional: a language model
==========================

..  code-block:: bash

    composer require netresearch/nr-llm

With nr-llm installed and a provider configured, the per-protocol toggles in the
extension configuration decide where a model is used. Without it, every protocol
silently runs its deterministic demo and says so in the plugin's provenance
line. See :ref:`configuration` for the toggles and the budget.

Upgrading from 3.x
==================

4.0 moves every protocol to its current specification, so the wire formats,
the endpoints and the stored data all change.

#.  **Apply the schema and run the wizards.** ``extension:setup`` creates the
    object store and the traffic log. Then run
    :guilabel:`Admin Tools > Upgrade > Upgrade Wizard >
    Agent Nexus: migrate module permissions`: the backend modules are now
    ``agentnexus_*`` sections with third-level screens, and the wizard gives
    every group or user that had an ``agentstack_*`` module the matching
    section and all of its screens. The new inspector and traffic modules are
    not granted automatically, because they show what visitors sent.
#.  **The ten 3.x tables are no longer used.** The per-protocol activity logs
    (``tx_agentnexus_*_log``) and the widget capture tables
    (``tx_agentnexus_a2ui_inquiry``, ``tx_agentnexus_agui_lead``,
    ``tx_agentnexus_a2a_request``, ``tx_agentnexus_ucp_order``,
    ``tx_agentnexus_ap2_authorization``) are not migrated: 4.0 stores protocol
    objects in their specifications' own shapes, which the old rows do not
    have. Export what you want to keep, then let
    :guilabel:`Admin Tools > Maintenance > Analyze Database Structure` remove
    them.
#.  **The eID endpoints are gone.** Every endpoint is served below
    ``/api/agent-nexus`` (the ``apiBasePath`` setting), and the discovery
    documents at the addresses their specifications reserve:

    ..  list-table::
        :header-rows: 1

        *   -   3.x
            -   4.0
        *   -   ``?eID=a2a_card``
            -   ``/.well-known/agent-card.json``
        *   -   ``?eID=a2a_rpc``
            -   ``/api/agent-nexus/a2a/jsonrpc`` (and the HTTP+JSON binding
                below ``/api/agent-nexus/a2a/rest``)
        *   -   ``?eID=a2a_concierge``
            -   the same JSON-RPC endpoint; the widget is an A2A client
        *   -   ``?eID=agui_assistant``
            -   ``/api/agent-nexus/ag-ui``
        *   -   ``?eID=a2ui_generate``, ``?eID=a2ui_submit``
            -   ``/api/agent-nexus/a2ui/surfaces``, ``/api/agent-nexus/a2ui/actions``
        *   -   ``?eID=ucp_manifest``
            -   ``/.well-known/ucp``
        *   -   ``?eID=ucp_checkout``
            -   the UCP REST binding below ``/api/agent-nexus/ucp``, driven by
                the shopping agent at ``/api/agent-nexus/ucp/agent``
        *   -   ``?eID=ap2_authorize``
            -   ``/api/agent-nexus/ap2/authorize``

#.  **Clients must speak the new versions.** An A2A client sends
    ``A2A-Version: 1.0`` (without it the endpoint answers in A2A 0.3); AG-UI
    events and inputs follow AG-UI 1.0; A2UI surfaces are v0.9.1 messages; UCP
    is the 2026-08-25 checkout over REST; AP2 mandates are v0.2 SD-JWTs. See
    :ref:`spec-versions`.
#.  **Removed after their deprecation in 3.x:** the legacy CTypes of the five
    per-protocol packages (run the legacy wizard below first if you still have
    such records), the pre-3.0 icon aliases, and the five caches named after
    the protocols — rate limits live in one ``agentnexus`` cache now. The
    ``webconsulting/agent-nexus-desiderio`` site set still resolves so that
    sites listing it keep building; it adds nothing beyond its two
    dependencies, and its removal moves to 5.0.

Upgrading from 2.x
==================

Upgrade to 3.1 first, or run
:guilabel:`Admin Tools > Upgrade > Upgrade Wizard >
Agent Nexus: migrate legacy content element types` before you upgrade to 4.0:
the records created by the five per-protocol extensions Agent Nexus replaced no
longer render in 4.0, and the wizard rewrites their CType. Site packages that
carried their own ``tt_content.agentnexus_*`` TypoScript should drop it and add
the ``webconsulting/agent-nexus`` site set instead — see :ref:`site-setup`.
