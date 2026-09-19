:navigation-title: Agent Nexus

..  include:: /Includes.rst.txt
..  _start:

===========
Agent Nexus
===========

:Extension key:
    agent_nexus

:Package name:
    webconsulting/agent-nexus

:Version:
    3.1.0

:Language:
    en

:Author:
    webconsulting GmbH

:License:
    This document is published under the
    `Creative Commons BY 4.0 <https://creativecommons.org/licenses/by/4.0/>`__
    license.

Five agent protocols, running against your own TYPO3 — not slides about them.

A2UI lets an agent describe an interface. AG-UI streams an agent run as typed
events. A2A lets one agent delegate work to another. UCP lets a shopping agent
check out with a merchant. AP2 proves a human authorized the purchase. Agent
Nexus implements all five against this installation's own content, catalogue and
endpoints, with a backend playground per protocol and a frontend plugin an
editor can place on a page.

Every demo runs deterministically by default. A language model is optional,
per protocol, and budgeted.

----

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :class: pb-4
    :card-height: 100

    ..  card:: Introduction

        What the extension is, who it is for, and what it deliberately is not.

        ..  card-footer:: :ref:`Read the introduction <introduction>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: Installation

        Install it, and see the backend modules.

        ..  card-footer:: :ref:`Install Agent Nexus <installation>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: Site setup

        Build the demo site with one command, or add the plugins to your own.

        ..  card-footer:: :ref:`Set up a site <site-setup>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: Configuration

        Extension settings, site settings and the per-element FlexForms.

        ..  card-footer:: :ref:`Configure it <configuration>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: The protocols

        One page per protocol: what it is, what it exposes here, how a request
        flows through it.

        ..  card-footer:: :ref:`Read about the protocols <protocols>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: Security

        What the public endpoints expose, what is simulated, and what must never
        be treated as real.

        ..  card-footer:: :ref:`Understand the boundaries <security>`
            :button-style: btn btn-secondary stretched-link

..  toctree::
    :hidden:
    :titlesonly:

    Introduction
    Installation
    SiteSetup
    Configuration
    Protocols/Index
    Security
    Developer/Index

..  toctree::
    :hidden:

    Sitemap
