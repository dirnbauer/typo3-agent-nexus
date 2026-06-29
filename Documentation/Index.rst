..  _start:

============
Agent Nexus
============

:Extension key:
    agent_nexus

:Package:
    webconsulting/agent-nexus

:Version:
    1.0.0

Agent Nexus combines the TYPO3 agent protocol demos for A2UI, AG-UI, A2A, UCP
and AP2 into one installable extension.

Features
========

Backend modules
---------------

The extension adds an ``Agent Nexus`` backend hub with these submodules:

* Overview
* A2UI Playground
* AG-UI Playground
* A2A Console
* UCP Console
* AP2 Mandate Studio

Frontend plugins
----------------

Editors can place these plugin content elements:

* A2UI: Smart Project Inquiry
* AG-UI: AI Site Assistant
* A2A: Expert Router
* UCP: Package & Quote Builder
* AP2: Signed Quote Authorization

The frontend plugins use semantic theme tokens and inherit light/dark mode from
the site theme. The backend modules use TYPO3 backend and Bootstrap variables so
they follow the active backend color scheme.

Installation
============

..  code-block:: bash

    composer require webconsulting/agent-nexus

Safety
======

UCP and AP2 are demos. They do not initiate real payments. AP2 mandates are
sandbox-signed and UCP orders are simulated unless a project deliberately wires a
real payment integration behind the documented extension settings.
