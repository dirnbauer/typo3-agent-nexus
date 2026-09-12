:navigation-title: Testing

..  include:: /Includes.rst.txt
..  _developer-testing:

=======
Testing
=======

..  code-block:: bash

    composer install
    composer ci

That runs, in order: the coding standard, PHPStan, the unit suite and the
functional suite. Each is available on its own as ``ci:cgl``, ``ci:phpstan``,
``ci:tests:unit`` and ``ci:tests:functional``.

Static analysis
===============

PHPStan runs at **level 8 with no baseline**, over :file:`Classes`,
:file:`Configuration`, :file:`Tests` and :file:`ext_localconf.php`.

:composer:`netresearch/nr-llm` is a soft dependency, so CI has to be able to
analyse the bridge without installing it.
:file:`Build/phpstan/stubs/nr-llm.php` gives PHPStan the contracts through
``scanFiles``. Keep it in step whenever the bridge starts using something new.

Unit tests
==========

..  code-block:: bash

    composer ci:tests:unit

The services are ``final``, which PHPUnit cannot double, so
:file:`Build/phpunit/UnitTestsBootstrap.php` loads ``dg/bypass-finals`` for this
extension's own classes — in the test process only.

Functional tests
================

..  code-block:: bash

    composer ci:tests:functional

They run on SQLite by default. Point them at another database with the usual
testing-framework environment variables, for example:

..  code-block:: bash

    typo3DatabaseDriver=mysqli typo3DatabaseName=t3func typo3DatabaseUsername=root \
      typo3DatabasePassword=root typo3DatabaseHost=127.0.0.1 composer ci:tests:functional

What they cover: all nine eID endpoints in deterministic mode, the seed command
(including that a second run changes nothing), the legacy CType upgrade wizard,
and the hub's status DTOs built from real log rows.

..  tip::

    Deterministic mode is not a testing convenience — it is what a fresh
    installation does. Asserting on it is how the demos stay working without an
    API key.
