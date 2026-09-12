:navigation-title: Installation

..  include:: /Includes.rst.txt
..  _installation:

============
Installation
============

Requirements
============

*   TYPO3 v14.3.7 or newer
*   PHP 8.4 or newer
*   :composer:`typo3/cms-fluid-styled-content` (a requirement, not a
    suggestion: the frontend elements render through ``lib.contentElement``)

Install
=======

..  code-block:: bash

    composer require webconsulting/agent-nexus

Then apply the database schema — Agent Nexus adds one log or record table per
protocol plus a seed-key column on :sql:`pages` and :sql:`tt_content`:

..  code-block:: bash

    vendor/bin/typo3 extension:setup
    vendor/bin/typo3 cache:flush

Open :guilabel:`Agent Nexus > Overview` in the backend. The hub tells you what is
ready and what is not: whether the nine frontend endpoints are registered,
whether a storage folder exists, whether a language model is reachable, and what
each protocol has done in the last 24 hours.

Optional: a language model
==========================

..  code-block:: bash

    composer require netresearch/nr-llm

With nr-llm installed and a provider configured, the per-protocol toggles in the
extension configuration decide where a model is used. Without it, every protocol
silently runs its deterministic demo and says so in the plugin's provenance
line. See :ref:`configuration` for the toggles and the budget.

Upgrading from 2.x
==================

Three things changed that need action:

#.  **The rendering glue moved into the extension.** Site packages that carried
    their own ``tt_content.agentnexus_*`` TypoScript should drop it and add the
    ``webconsulting/agent-nexus`` site set instead — see :ref:`site-setup`.
#.  **The legacy content element types are deprecated.** Records created by the
    five per-protocol extensions Agent Nexus replaced still render, but the
    aliases are removed in 4.0. Run
    :guilabel:`Admin Tools > Upgrade > Upgrade Wizard >
    Agent Nexus: migrate legacy content element types`.
#.  **Icon identifiers were renamed.** ``a2ui-module`` and friends still
    resolve as aliases until 4.0; the new names are
    ``agentnexus-module-<protocol>`` and ``agentnexus-plugin-<name>``.
