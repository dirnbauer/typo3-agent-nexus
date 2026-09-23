:navigation-title: Testing

..  include:: /Includes.rst.txt
..  _developer-testing:

=======
Testing
=======

..  code-block:: bash

    composer install
    composer ci

That runs, in order: the coding standard, PHPStan, the unit and conformance
suites and the functional suite. Each is available on its own as ``ci:cgl``,
``ci:phpstan``, ``ci:tests:unit`` and ``ci:tests:functional``.

Static analysis
===============

PHPStan runs at **level 8 with no baseline and no ignored errors**, over
:file:`Classes`, :file:`Configuration`, :file:`Tests` and
:file:`ext_localconf.php`.

:composer:`netresearch/nr-llm` is a soft dependency, so CI has to be able to
analyse the bridge without installing it.
:file:`Build/phpstan/stubs/nr-llm.php` gives PHPStan the contracts through
``scanFiles``. Keep it in step whenever the bridge starts using something new.

The protocol services depend on two small interfaces for the model and its spend
ledger (``LanguageModel``, ``UsageLedger``), which is what lets the tests double
them without touching the ``final`` classes that implement them.

Unit tests
==========

..  code-block:: bash

    composer ci:tests:unit

Conformance tests
=================

:file:`Tests/Conformance` checks every payload the extension emits against the
official schema of its specification. The schemas are vendored under
:file:`Tests/Conformance/Schemas`, one directory per protocol, each with the
upstream licence and a :file:`SOURCE.txt` naming the repository, the commit and
the SHA-256 of every file:

..  list-table::
    :header-rows: 1

    *   -   Protocol
        -   Schema
        -   Licence
    *   -   A2A 1.0
        -   JSON Schema generated from :file:`a2a.proto` (the proto is vendored
            too; the generated schema carries no ``required`` lists, so the
            tests assert required fields from the proto separately)
        -   Apache-2.0
    *   -   AG-UI 1.0
        -   :file:`spec/1.0/schema.json`
        -   MIT
    *   -   A2UI v0.9.1 and v1.0
        -   the ``json/`` schemas and the basic catalogue of each version
        -   Apache-2.0
    *   -   UCP 2026-08-25
        -   the ``$ref`` closure of the profile, checkout, fulfillment and AP2
            mandate schemas
        -   Apache-2.0
    *   -   AP2 v0.2.0
        -   the mandate and receipt schemas
        -   Apache-2.0

``SchemaValidator`` registers every schema under its ``$id``, so references
resolve offline, and sends data through a JSON round trip first, exactly as it
would travel: a PHP ``[]`` meant to be ``{}`` fails here the way it would fail in
another implementation. ``HarnessTest`` proves the setup against the
specifications' own examples before any other conformance test means anything.

To update a specification, replace its directory from the new release, update
:file:`SOURCE.txt`, and let the conformance tests show what changed.

Functional tests
================

..  code-block:: bash

    composer ci:tests:functional

They run on SQLite by default. Point them at another database with the usual
testing-framework environment variables, for example:

..  code-block:: bash

    typo3DatabaseDriver=mysqli typo3DatabaseName=t3func typo3DatabaseUsername=root \
      typo3DatabasePassword=root typo3DatabaseHost=127.0.0.1 composer ci:tests:functional

What they cover: every protocol endpoint through the real frontend middleware
stack in deterministic mode, including version negotiation, error shapes, rate
limits, and the traffic rows and protocol objects each exchange leaves behind;
every backend screen rendered for a backend user; the seed command (including
that a second run changes nothing); and the upgrade wizards.

..  tip::

    Deterministic mode is not a testing convenience — it is what a fresh
    installation does. Asserting on it is how the demos stay working without an
    API key.
