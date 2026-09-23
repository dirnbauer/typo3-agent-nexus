:navigation-title: Security

..  include:: /Includes.rst.txt
..  _security:

========
Security
========

Agent Nexus opens public endpoints, records traffic and signs tokens. Each
deserves a clear statement of what it is — and is not.

..  warning::

    Everything money-shaped in this extension is simulated. AP2 mandates are
    signed with sandbox keys the installation generates for itself: they prove
    that a mandate was not changed after signing and which sandbox role signed
    it, nothing about a real person or a real card. No payment network, no real
    authorisation, no order. Never wire a mandate minted here into anything that
    moves money.

The public endpoints
====================

Every endpoint is served by one middleware below ``/api/agent-nexus`` (the
``apiBasePath`` setting), plus the discovery documents at
:file:`/.well-known/agent-card.json` and :file:`/.well-known/ucp`. They are
reachable without authentication — that is the point, since an external agent
has to be able to discover and call them — and they allow any origin (CORS),
because they carry no credentials. What they can do is bounded instead:

*   **Rate limiting per client.** Every endpoint counts requests per IP address
    in a fixed window, with a tighter budget for requests that reach a real
    model. The limiter fails open when its cache is unavailable: these are demo
    endpoints, and a broken cache must not take the page down with them.
*   **Input caps and validation.** Messages and intents are truncated, malformed
    input is refused before any work starts, and every request body is treated
    as untrusted.
*   **No arbitrary rendering.** An A2UI surface names components; the renderer
    draws only the components of the basic catalogue, with only the properties
    each declares. Anything else is dropped before it reaches the page.
*   **A human gate before every write.** AG-UI raises an interrupt and applies
    a change only when the next run answers it; UCP completes a checkout only
    after the visitor approved it; AP2 authorises only a mandate chain that
    verifies. ``aguiReallyApply`` and ``ucpReallyApply`` keep the results
    simulated.
*   **No server-side requests to URLs a caller names.** UCP's ``UCP-Agent``
    header names a platform profile that a production business would fetch; the
    sandbox validates the header and does not fetch it, so the endpoint cannot
    be used to make this server call arbitrary addresses.

What ends up in the database
============================

**Protocol objects** (:sql:`tx_agentnexus_object`): tasks, runs, checkout
sessions, mandates and surfaces, including what visitors typed into the widgets
— questions, form data, the email address a lead or a checkout asked for. That
is personal data. Point :ref:`agentNexus.storagePid <configuration-site>` at a
folder, grant the inspector only to people who may read it, and let
``objectRetentionDays`` delete what is no longer needed.

**The traffic log** (:sql:`tx_agentnexus_traffic`): one row per exchange.
Before a row is written, headers are reduced to an allow-list — cookies and
authorisation headers are never recorded — and, with
``trafficRedactPersonalData`` on (the default), names, email addresses, phone
numbers and postal addresses in JSON bodies are replaced. Client IP addresses
are not recorded at all. ``trafficRetentionDays`` and the ``agentnexus:cleanup``
command decide how long rows stay.

**Sandbox keys**: the AP2 signing keys are generated on first use and kept in
the TYPO3 registry. Their public halves are published as a JSON Web Key Set so
anyone can verify a mandate. The private halves never leave the installation
and are never part of the repository.

Model calls
===========

When a model is enabled, prompts include what the visitor typed and — for the
A2UI generator — the business context configured on the element. Nothing else
from the installation is sent. Prompt-shaping settings are read server side from
the content element and cannot be posted by a client. The spend of every call is
written to :sql:`tx_agentnexus_llm_usage` and shown in the overview, and
:ref:`llmDailyBudget <configuration-extension>` stops the calls once the day's
cap is reached.

Before a public installation
============================

*   Keep ``aguiReallyApply`` and ``ucpReallyApply`` off.
*   Set a storage folder, and decide who may open the inspector and the
    traffic log.
*   Schedule ``agentnexus:cleanup`` and choose retention periods you can
    defend.
*   Set a daily budget if a model is enabled.
*   Remember that the Agent Card and the UCP profile advertise this site to any
    agent that looks. That is intended — make sure the skills and the catalogue
    they advertise are ones you are happy to publish. Turn off
    ``publishWellKnown`` if they must not be found at the well-known addresses.
