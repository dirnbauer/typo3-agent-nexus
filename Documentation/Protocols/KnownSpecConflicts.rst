:navigation-title: Known spec conflicts

..  include:: /Includes.rst.txt
..  _known-spec-conflicts:

====================
Known spec conflicts
====================

Two specifications Agent Nexus implements sometimes disagree. Where they do,
the official files stay exactly as published (vendored under
:file:`Tests/Conformance/Schemas`, checksums in each :file:`SOURCE.txt`), and
Agent Nexus resolves the conflict on its own side, in a separate file that
says what it changes and why.

UCP ``checkout_mandate`` rejects AP2 mandates
=============================================

Affects
    UCP 2026-08-25, ``dev.ucp.common.payment.ap2_mandate``
    (:file:`schemas/common/payment_ap2_mandate.json`), together with AP2
    v0.2.0.

The conflict
    UCP carries an AP2 checkout mandate in ``ap2.checkout_mandate`` of the
    ``complete`` request and describes it as an "SD-JWT+kb credential". Its
    schema constrains the string with this pattern:

    ..  code-block:: text

        ^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*\.[A-Za-z0-9_-]+(~[A-Za-z0-9_-]+)*$

    That admits a JWS followed by tilde-separated segments that contain no
    dots and are never empty. AP2 v0.2.0 serialises mandates differently:

    *   every mandate ends with a tilde (``<JWT>~<disclosure>~…~``), because
        AP2 appends no separate key-binding JWT;
    *   a delegated mandate is a chain: the open mandate, then the closing
        KB-SD-JWT signed with the key the open mandate names, joined by the
        empty component that gives ``~~``. The KB-SD-JWT is a JWS, so that
        segment contains dots.

    The official schema therefore rejects every example of AP2's own
    documentation (:file:`docs/ap2/checkout_mandate.md` and
    :file:`docs/ap2/payment_mandate.md`, v0.2.0) and every mandate this
    installation signs. It would also reject an RFC 9901 SD-JWT+KB with its
    key-binding JWT, which contains dots too; AP2 never sends that form.

The resolution
    A local overlay schema accepts either form where UCP carries an AP2
    mandate and leaves every other rule to the upstream schema:

    File
        :file:`Resources/Private/Schemas/Overlays/ucp/2026-08-25/common/payment_ap2_mandate.json`
        (shipped with the extension)

    ``$id``
        ``https://raw.githubusercontent.com/dirnbauer/typo3-agent-nexus/main/Resources/Private/Schemas/Overlays/ucp/2026-08-25/common/payment_ap2_mandate.json``

    Definitions
        ``#/$defs/ap2_mandate_chain`` (the AP2 form),
        ``#/$defs/checkout_mandate`` (``anyOf`` the upstream definition and
        the AP2 form), ``#/$defs/ap2_with_checkout_mandate`` and
        ``#/$defs/dev.ucp.shopping.checkout`` (the upstream composition with
        the overlay's ``checkout_mandate``).

    The AP2 form, one token or a chain of them:

    ..  code-block:: text

        ^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*\.[A-Za-z0-9_-]+(~[A-Za-z0-9_-]+)*~(~[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*\.[A-Za-z0-9_-]+(~[A-Za-z0-9_-]+)*~)*$

Example
    An open checkout mandate chained with its closing KB-SD-JWT, shortened:
    the headers are the real ones (``{"alg":"ES256"}`` and
    ``{"alg":"ES256","typ":"kb+sd-jwt"}``), payloads, disclosures and
    signatures are placeholders. It fails the upstream pattern and passes the
    overlay:

    ..  code-block:: text

        eyJhbGciOiJFUzI1NiJ9.eyJfc2QiOlsiLi4uIl19.c2lnbmF0dXJlLTE~WyJzYWx0LTEiLCJtZXJjaGFudCIsIkV4YW1wbGUgU2hvcCJd~~eyJhbGciOiJFUzI1NiIsInR5cCI6ImtiK3NkLWp3dCJ9.eyJzZF9oYXNoIjoiLi4uIn0.c2lnbmF0dXJlLTI~WyJzYWx0LTIiLCJjaGVja291dF9oYXNoIiwiLi4uIl0~

    The full, signed example is the "open plus closed" checkout mandate in the
    AP2 v0.2.0 documentation, vendored as
    :file:`Tests/Conformance/Ap2/Fixtures/open_plus_closed_checkout_mandate_chain.sdjwt.txt`.

Proof
    :file:`Tests/Conformance/Ucp/Ap2MandateOverlayTest.php` checks that the
    vendored UCP file is unchanged (its SHA-256 against :file:`SOURCE.txt`),
    that the upstream pattern rejects all six official AP2 examples and a
    chain this installation's sandbox signs, that the overlay accepts those
    and a token of the upstream form, that malformed strings fail both, and
    that a whole checkout with an AP2 chain conforms to the overlay's
    composition while a broken ``merchant_authorization`` still fails it.

At runtime
    Nothing in Agent Nexus validates requests against these schemas at
    runtime; :php:`Webconsulting\AgentNexus\Ap2\Service\UcpMandateBridge`
    parses the mandate as an AP2 delegation chain and verifies it. The overlay
    is for anyone who validates UCP payloads against the official schemas.

When UCP changes
    The test ``theOfficialSchemaRejectsAp2sOwnExamples`` fails as soon as a
    vendored UCP release accepts AP2's format. Then drop the overlay and this
    section.

Using an overlay in your own validator
======================================

An overlay is a JSON Schema of its own. It refers to the official schema by
its ``$id`` and never replaces it, so validating against the official ``$id``
still gives the official verdict. Register the vendored official schemas and
the overlay under their ``$id`` s, then validate against the overlay's
definition instead of the official one. With `opis/json-schema`:

..  code-block:: php

    $validator = new \Opis\JsonSchema\CompliantValidator();
    $resolver = $validator->resolver();
    // The official files first (every file under its own $id), then the overlay.
    $resolver->registerFile(
        'https://ucp.dev/schemas/common/payment_ap2_mandate.json',
        $ucpSchemas . '/common/payment_ap2_mandate.json',
    );
    $resolver->registerFile(
        'https://raw.githubusercontent.com/dirnbauer/typo3-agent-nexus/main/Resources/Private/Schemas/Overlays/ucp/2026-08-25/common/payment_ap2_mandate.json',
        'vendor/webconsulting/agent-nexus/Resources/Private/Schemas/Overlays/ucp/2026-08-25/common/payment_ap2_mandate.json',
    );

    $result = $validator->validate(
        json_decode($completeRequestBody),
        'https://raw.githubusercontent.com/dirnbauer/typo3-agent-nexus/main/Resources/Private/Schemas/Overlays/ucp/2026-08-25/common/payment_ap2_mandate.json#/$defs/ap2_with_checkout_mandate',
    );

The conformance harness (:php:`Webconsulting\AgentNexus\Tests\Conformance\SchemaValidator`)
registers every JSON file below :file:`Resources/Private/Schemas/Overlays`
this way. A new conflict gets its own overlay file there, with its own
``$id``, a section on this page and a test that fails once upstream resolves
it.
