:navigation-title: AP2

..  include:: /Includes.rst.txt
..  _protocol-ap2:

======================
AP2 — agent to payment
======================

An agent that can spend money raises one question before any other: *who said
it could?* AP2 answers with mandates: signed statements that a person approved
a purchase, or approved the limits within which an agent may make one alone.
A merchant, a credential provider and a payment processor can each check that
approval themselves instead of trusting the agent.

Agent Nexus 4.0 implements **AP2 v0.2.0** (28 April 2026). Mandates are
selectively disclosable JWTs (SD-JWT, RFC 9901) signed with ES256 on P-256 keys.
Every role runs inside this installation with sandbox keys, and nothing is ever
charged. See :ref:`spec-versions` for what changed since 3.1, whose HS256
tokens only looked like the v0.1 mandates.

..  warning::

    This is a sandbox for learning how AP2 works. The five role keys are
    generated per installation and kept in :sql:`sys_registry`, so anyone who
    can read the database can sign as any role. The payment credential is a
    stub and no money moves. Never act on these mandates.

Mandates
========

There are two kinds of mandate, and each has two forms.

*   A **checkout mandate** approves what is bought: which items, from which
    merchant.
*   A **payment mandate** approves how it is paid: how much, to whom, with
    which payment method.

The **open** form carries the person's constraints and the agent's public key
(``cnf.jwk``). The Trusted Surface signs it while the person is present. The
**closed** form names one concrete checkout. The agent signs it with the key
from ``cnf``, as a key-binding JWT that points back to the open mandate. When the
person approves a single purchase directly, the Trusted Surface signs a closed
mandate itself and no agent is involved.

..  list-table::
    :header-rows: 1
    :widths: 22 18 30 30

    *   -   ``vct``
        -   Signed by
        -   Carries
        -   Checked by
    *   -   ``mandate.checkout.open.1``
        -   Trusted Surface
        -   ``constraints`` (``checkout.line_items``, optionally
            ``checkout.allowed_merchants``), ``cnf``
        -   the merchant, through the closed mandate
    *   -   ``mandate.checkout.1``
        -   agent (or Trusted Surface)
        -   ``checkout_jwt``, ``checkout_hash``
        -   the merchant
    *   -   ``mandate.payment.open.1``
        -   Trusted Surface
        -   ``constraints`` (always ``payment.reference``), ``cnf``
        -   the credential provider, through the closed mandate
    *   -   ``mandate.payment.1``
        -   agent (or Trusted Surface)
        -   ``transaction_id``, ``payee``, ``payment_amount``,
            ``payment_instrument``
        -   the credential provider and the payment processor

Amounts are integers in minor units. The merchant signs the checkout as a plain
JWT (``checkout_jwt``, a UCP checkout object with ``iat``, ``exp`` and
``jti``). Its SHA-256 is the closed checkout mandate's ``checkout_hash`` and the
closed payment mandate's ``transaction_id``, so the payment can only pay for
that checkout. The open payment mandate's ``payment.reference`` constraint holds
the hash of the open checkout mandate, so the two approvals belong together.

The wire format
---------------

Each mandate sits in ``delegate_payload`` as one selectively disclosable array
element. The items, merchants, payees and payment methods inside it are
disclosable too, so a verifier sees only what the holder reveals. An open
mandate and the closed mandate that points to it travel as one *delegate chain*:

..  code-block:: text

    <open issuer JWT>~<disclosure>~…~~<closed KB-JWT>~<disclosure>~…~

The closed token's header is ``{"alg": "ES256", "typ": "kb+sd-jwt"}``. Its
payload carries ``delegate_payload``, ``iat``, ``aud`` (``merchant`` or
``credential-provider``), the verifier's ``nonce`` and ``sd_hash``: the SHA-256
of the open mandate exactly as presented, trailing ``~`` included. The open
token's ``typ`` is ``dc+sd-jwt``. Any type ending in ``sd-jwt`` is accepted,
because the specification's examples use ``example+sd-jwt``.

Constraints
-----------

An open mandate is only as useful as its constraints. The verifier evaluates
each one against the closed mandate and fails on any it does not know.

..  list-table::
    :header-rows: 1
    :widths: 35 65

    *   -   Constraint
        -   The closed mandate passes when
    *   -   ``checkout.line_items``
        -   every requirement is met by items from its ``acceptable_items``, in
            the required quantity, and the cart holds nothing else (a max-flow
            match)
    *   -   ``checkout.allowed_merchants``
        -   the checkout's merchant is one of ``allowed``
    *   -   ``payment.amount_range``
        -   ``payment_amount`` is in the currency and between ``min`` and
            ``max``
    *   -   ``payment.allowed_payees``
        -   ``payee`` is one of ``allowed``
    *   -   ``payment.allowed_payment_instruments``
        -   ``payment_instrument`` is one of ``allowed``
    *   -   ``payment.allowed_pisps``
        -   ``pisp`` is one of ``allowed``
    *   -   ``payment.budget``
        -   this payment and every earlier one under the mandate stay within
            ``max`` (major units)
    *   -   ``payment.agent_recurrence``
        -   the mandate may be used again at this ``frequency``, at most
            ``max_occurrences`` times
    *   -   ``payment.execution_date``
        -   the payment date is between ``not_before`` and ``not_after``
    *   -   ``payment.reference``
        -   ``conditional_transaction_id`` is the hash of the open checkout
            mandate presented with the payment

*Agent Nexus > AP2 > Mandate reference* lists every field of every mandate,
constraint, shared type and receipt, straight from the catalogue the verifiers
use. A conformance test compares that catalogue with the published JSON
Schemas, field by field.

The five roles
==============

..  list-table::
    :header-rows: 1
    :widths: 22 78

    *   -   Role
        -   In this sandbox
    *   -   Trusted Surface
        -   Shows the person what they approve and signs the open mandates
            (``trusted-surface-…`` key).
    *   -   Shopping agent
        -   Chooses a cart within the constraints and closes both mandates with
            the key from ``cnf``.
    *   -   Merchant
        -   Signs the checkout (``checkout_jwt``), checks the closed checkout
            mandate and signs a checkout receipt.
    *   -   Credential provider
        -   Checks the closed payment mandate and releases a payment credential,
            or refuses and signs an error receipt.
    *   -   Payment processor
        -   Checks the payment mandate again, settles (simulated) and signs a
            payment receipt.

How a purchase flows
====================

The Trusted Surface widget and ``POST /api/agent-nexus/ap2/authorize`` run the
same flow.

#.  **Approval.** The person approves a shopping list and a spending cap once.
    The Trusted Surface signs an open checkout mandate (the list, the merchant,
    the agent's key) and an open payment mandate (the cap, the payee, the
    payment method, a reference to the open checkout mandate).
#.  **Checkout.** The agent shops alone. It picks one option per line; the
    merchant builds the checkout and signs it.
#.  **Closing.** Each verifier issues a challenge. The agent closes both
    mandates with its own key, for that verifier's audience and nonce.
#.  **Payment mandate.** The credential provider verifies the payment chain. If
    a constraint fails, it refuses with ``unresolved_constraint`` and signs an
    error receipt. The agent then has to bring the exact order back to the
    Trusted Surface, where the person approves it directly (*human present*).
#.  **Checkout mandate.** The merchant verifies the checkout chain against the
    checkout it signed and signs a checkout receipt.
#.  **Settlement.** The payment processor checks the payment mandate once more,
    settles (simulated) and signs a payment receipt.

A verified closed mandate cannot be presented again, and an open mandate is
used once unless a ``payment.agent_recurrence`` constraint allows more. The
sequence diagram of this flow is :file:`Build/Diagrams/ap2.mmd`.

..  code-block:: text

    Trusted Surface   signs  mandate.checkout.open.1 + mandate.payment.open.1
    Merchant          signs  checkout_jwt                 (UCP checkout, €447.00)
    Shopping agent    signs  mandate.checkout.1 + mandate.payment.1 (kb+sd-jwt)
    Credential prov.  checks the payment chain      → payment credential
    Merchant          checks the checkout chain     → checkout_receipt (Success)
    Payment processor settles                       → payment_receipt  (Success)

The checks
==========

A verifier reports every check, not just the first failure. The checks come in
this order: well-formed mandate, signed by the Trusted Surface, closed with the
agent's key, bound to the approved mandate, meant for this verifier, one
mandate per token, not expired (300 seconds of clock skew), expected mandate
type, complete content, approved values unchanged, then the checkout or
payment checks and one check per constraint, and finally *not used before*.

The error follows the most serious failure:

..  list-table::
    :header-rows: 1
    :widths: 30 70

    *   -   Error
        -   When
    *   -   ``invalid_credential``
        -   A signature, the binding, the audience, the nonce, the lifetime or
            the content is wrong. Terminal.
    *   -   ``invalid_mandate``
        -   The mandate is genuine but does not approve this action: another
            type, another checkout, changed values, or already used. Terminal.
    *   -   ``unresolved_constraint``
        -   A constraint the person set is not met. The person can still approve
            the purchase directly.
    *   -   ``mandates_not_supported``
        -   The verifier does not accept mandates for this action.

Receipts
========

The merchant signs a ``checkout_receipt`` and the payment processor (or, when
it refuses, the credential provider) a ``payment_receipt``: plain ES256 JWTs
with ``status`` (``Success`` or ``Error``), ``iss``, ``iat`` and ``reference``,
the SHA-256 of the closed mandate's issuer JWT. A successful checkout receipt
adds ``order_id``; a successful payment receipt ``payment_id``,
``psp_confirmation_id`` and ``network_confirmation_id``. An error receipt adds
``error`` and ``error_description``.

Endpoints
=========

..  list-table::
    :header-rows: 1
    :widths: 10 35 55

    *   -   Method
        -   Path
        -   Purpose
    *   -   GET
        -   ``/api/agent-nexus/ap2/jwks.json``
        -   The public keys of all five roles as a JWK Set (``kid``, ``use``
            ``sig``, ``alg`` ``ES256``). Cached for five minutes.
    *   -   POST
        -   ``/api/agent-nexus/ap2/authorize``
        -   Runs the whole flow and answers with every token, every check and
            both receipts.

The API base path is the ``apiBasePath`` extension setting
(``/api/agent-nexus`` by default). Request of ``POST /ap2/authorize``:

..  code-block:: json

    {
      "capCents": 50000,
      "intent": "within",
      "merchantId": "desiderio-store",
      "agentNexus": {"ce": 73236, "page": 1406, "url": "https://…"}
    }

Only ``capCents`` is required: a whole number of cents from 1 to 10,000,000,
as a number or a string of digits. ``intent`` is ``within`` (the agent picks the
dearest cart that fits the cap) or ``over`` (the cheapest cart above it, to test
the cap). ``merchantId`` is the merchant the person allows, ``desiderio-store`` or
``other-shop``; the checkout always comes from Desiderio Store, so
``other-shop`` shows a refused payee. ``agentNexus`` is sent by the widget.

The answer:

..  code-block:: text

    simulated      always true
    chainId        the reference of the open checkout mandate
    authorised     true or false; outcome "authorised" or "refused"
    error          null or the AP2 error of the refusal
    summary, note  what happened, in one or two sentences
    fallback       {"mode": "human_present", …} after unresolved_constraint
    cap, cart      the cap and the cart the agent chose, with withinCap
    steps          six steps, each "done", "passed", "failed" or "skipped"
    artefacts      the five tokens: header, claims, disclosures, reference
    verifications  per verifier: valid, error, errorDescription, checks
    receipts       checkout and payment, or null where none was signed
    explanation    a model's explanation for the widget, when switched on

Refusals
--------

..  list-table::
    :header-rows: 1
    :widths: 10 90

    *   -   Status
        -   When
    *   -   400
        -   The body is not a JSON object, or it is larger than 16 KB.
    *   -   422
        -   ``capCents``, ``intent`` or ``merchantId`` is invalid.
    *   -   429
        -   More than 30 requests from one address in 10 minutes
            (``Retry-After: 600``).

A refusal is ``{"error": {"code": <status>, "message": "…"}, "simulated": true}``.
A refused payment is not a refusal: it is a 200 with ``authorised: false``.

AP2 in UCP
==========

UCP's AP2 mandates extension carries these mandates in a checkout.
:php:`Webconsulting\AgentNexus\Ap2\Service\UcpMandateBridge` is the API the UCP
business uses:

*   ``merchantJwk()``: the business key for ``keys`` in ``/.well-known/ucp``.
*   ``withAuthorization($checkout)``: adds ``ap2.merchant_authorization``, a
    detached ES256 JWS over the JCS (RFC 8785) of the checkout without ``ap2``.
*   ``checkoutJwt($checkout)``: that signature with its payload put back, the
    ``checkout_jwt`` a checkout mandate binds to.
*   ``verifyMandates($checkoutMandate, $sessionCheckout, $paymentMandate)``:
    checks ``ap2.checkout_mandate`` and the payment mandate against the session
    and answers with a UCP error code.

..  list-table::
    :header-rows: 1
    :widths: 35 65

    *   -   UCP code
        -   When
    *   -   ``mandate_required``
        -   The request has no ``ap2.checkout_mandate``.
    *   -   ``merchant_authorization_missing``, ``merchant_authorization_invalid``
        -   The session's checkout is not signed by this business.
    *   -   ``agent_missing_key``
        -   The mandate is signed with a key this business does not know.
    *   -   ``mandate_invalid_signature``
        -   A signature, the binding, the format or the content fails.
    *   -   ``mandate_expired``
        -   The mandate has expired.
    *   -   ``mandate_scope_mismatch``
        -   The mandate is genuine but not for this checkout, or a constraint
            fails.

The backend
===========

*Agent Nexus > AP2 > Mandate studio* signs any of the four mandate types from a
form, verifies whatever you paste (up to four mandates, chains, receipts or
signed checkouts, as the verifying role would, without using anything up) and
runs the whole flow with a cap you choose. It lists the five role keys and the
latest mandates.

Every token is a protocol object of kind *Mandate*. The object id is its
reference (the SHA-256 of its issuer JWT) and the context is the chain
(the reference of the open checkout mandate), so one purchase groups together.
The state is ``issued``, then ``verified`` or ``rejected``. *Agent Nexus >
Inspector > Mandates* shows each one decoded, with its disclosures and its
checks. The traffic log shows the HTTP request under the chain id and every
exchange between the roles (``CreateCheckout``, ``PresentPaymentMandate``,
``PresentCheckoutMandate``, ``SettlePayment``) under the closed mandate it
carried.

The Trusted Surface widget
==========================

The "AP2: Signed quote approval" content element shows the shopping list and the
merchant, a spending-cap field and two buttons: *Approve this purchase* runs the
flow within the cap, *Test your cap* makes the agent pick a cart above it. The
answer shows each step with its checks and the receipts. *Show the signed
mandates* adds every token with its claims and disclosures. The optional
plain-language explanation comes from a model. It is only asked for when the
element switches it on, the model guard allows AP2 and the address has used
fewer than ten explanations in 10 minutes. The mandates and the checks never
depend on a model.

Where this follows the specification, not the SDK
=================================================

*   A requirement whose ``acceptable_items`` the holder did not disclose matches
    nothing. The SDK treats it as "any item".
*   ``checkout.line_items`` also requires that the cart holds only matched
    items: the maximum flow must equal both the total required quantity and the
    cart's total quantity. The SDK checks only the cart side.
*   Single use is enforced by the verifier's own record: a verified closed
    mandate cannot be presented again, and open mandates count their uses.

What is simulated
=================

*   All five roles run in the same request, with keys stored in the
    installation.
*   The payment credential is a random token, and the payment processor
    confirms without a payment network.
*   Items and prices come from the UCP demo catalogue; the currency is EUR.

Try it with curl
================

..  code-block:: bash

    curl -s https://example.org/api/agent-nexus/ap2/jwks.json

    curl -s https://example.org/api/agent-nexus/ap2/authorize \
      -H 'Content-Type: application/json' \
      -d '{"capCents": 50000, "intent": "within"}'

Test the cap, and see the credential provider refuse:

..  code-block:: bash

    curl -s https://example.org/api/agent-nexus/ap2/authorize \
      -H 'Content-Type: application/json' \
      -d '{"capCents": 50000, "intent": "over"}'
