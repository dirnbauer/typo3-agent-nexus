:navigation-title: AP2

..  include:: /Includes.rst.txt
..  _protocol-ap2:

======================
AP2 — agent to payment
======================

An agent that can spend money raises one question before any other: *who said it
could?* AP2 answers with mandates — signed tokens that make the authority
verifiable rather than assumed.

There are two, and they chain:

**Intent Mandate**
    The human authorizes an agent to spend within limits: a cap, allowed
    merchants, an expiry. Typically created while the human is present, and used
    later when they are not.

**Cart Mandate**
    For one specific, fully priced cart. It references the Intent Mandate and
    proves that *this exact purchase* is covered.

Verification walks the chain rather than checking a single signature: both
signatures valid and unexpired, the cart referencing the intent, the merchant
inside the intent's scope, and the total within the cap. Only when all five hold
is a (simulated) payment authorized.

How a request flows
===================

#.  **Intent Mandate.** The human authorizes the agent within limits.
#.  **Cart Mandate.** A second mandate pins one priced cart to that intent.
#.  **Verification.** The five checks run as a chain.
#.  **Receipt.** The verified chain *is* the receipt.

Endpoint
========

..  list-table::
    :header-rows: 1

    *   -   Method
        -   Path
        -   Purpose
    *   -   POST
        -   ``/index.php?eID=ap2_authorize``
        -   Mints both mandates and returns the verified chain.

In this installation
====================

Mandates are compact JWS tokens (``HS256``) with real mechanics: base64url,
an HMAC signature, constant-time comparison. The backend Mandate Studio includes
a tamper box — edit one byte of a signed token and watch verification fail,
which is the entire point of a signed mandate.

Activity is logged to :sql:`tx_agentnexus_ap2_mandate_log`, frontend
authorizations to :sql:`tx_agentnexus_ap2_authorization`.

..  warning::

    Tokens are signed with a fixed demo secret that ships in the source. They
    prove integrity, not identity: anyone with the extension can mint one. This
    is a sandbox for learning the shape of AP2, never an authorization anything
    should act on.
