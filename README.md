# Agent Nexus

Agent Nexus is a unified TYPO3 v14 extension for the agent protocol demos that
were previously split across A2UI, AG-UI, A2A, UCP, AP2 and Agent Stack packages.

It adds one backend hub with six modules:

- Overview
- A2UI Playground
- AG-UI Playground
- A2A Console
- UCP Console
- AP2 Mandate Studio

It also registers five frontend plugins:

- A2UI Smart Project Inquiry
- AG-UI AI Site Assistant
- A2A Expert Router
- UCP Package & Quote Builder
- AP2 Signed Quote Authorization

## Requirements

- PHP 8.3+
- TYPO3 14.3+

## Installation

```bash
composer require webconsulting/agent-nexus
```

For the TYPO3 lab repository this package is also available from
`packages/agent_nexus` and from the VCS repository:

```json
{
  "type": "vcs",
  "url": "https://github.com/dirnbauer/typo3-agent-nexus.git"
}
```

## Notes

The commerce and payment flows are sandbox demos. UCP never charges a real
payment method, and AP2 signs demo mandates only. A2UI can use `netresearch/nr-llm`
when configured, otherwise the deterministic generator keeps the demo usable
without an external AI provider.
