:navigation-title: UCP

..  include:: /Includes.rst.txt
..  _protocol-ucp:

=======================
UCP — agent to merchant
=======================

Agentic commerce needs a handshake before it needs a checkout. UCP gives a
merchant a machine-readable **manifest** — who the store is, which currency,
which capabilities, where to check out, what is in the catalogue — so a shopping
agent can discover it before it builds anything.

The checkout itself is a small state machine streamed to the client:

..  code-block:: text

    checkout.started → cart.updated → checkout.review →
      authorization.required → order.confirmed | order.declined

How a request flows
===================

#.  **Manifest.** The agent reads currency, capabilities, checkout endpoint and
    catalogue.
#.  **Cart.** It assembles a cart from the real catalogue. Prices are
    deterministic — a model never invents one.
#.  **Authorization.** The stream halts at ``authorization.required`` and shows
    the priced cart to the human.
#.  **Confirmation.** Only an explicit approval produces ``order.confirmed``.

Endpoints
=========

..  list-table::
    :header-rows: 1

    *   -   Method
        -   Path
        -   Purpose
    *   -   GET
        -   ``/index.php?eID=ucp_manifest``
        -   The merchant manifest a shopping agent reads first.
    *   -   POST
        -   ``/index.php?eID=ucp_checkout``
        -   Streams an agent-driven checkout up to the authorization gate.

In this installation
====================

The demo store sells four things in euros. Prices live in the merchant service
as integer minor units, so no float arithmetic can drift and no model can write
a number. A model may write the recommendation text; the cart and the total never
come from one.

Runs are logged to :sql:`tx_agentnexus_ucp_order_log`, confirmed carts to
:sql:`tx_agentnexus_ucp_order`.

..  warning::

    Every checkout is simulated. The manifest says so (``sandbox: true``), and
    with ``ucpReallyApply`` off — the default — nothing is ever placed with a
    real system.
