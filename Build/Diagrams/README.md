# Protocol diagrams

One sequence diagram per protocol, rendered with
[Archify](https://github.com/tt-a1i/archify) (MIT) into a self-contained,
interactive HTML file in `Resources/Public/Diagrams/<key>.html`. The protocol
info plugin shows it in embed mode (`?embed=1&theme=light|dark`); the full
viewer, with guided chapters, trace and export, opens from the caption link.

## Source format

`<key>.json` is an Archify sequence document (`diagram_type: "sequence"`)
without coordinates. `npm run diagrams` (Build/render-diagrams.mjs) adds them:

- `phases` lists the messages in order, grouped into labelled phases. Every
  message gets its own row (32 px), phases are 14 px apart, and each phase
  becomes an Archify segment.
- `activations` name the first and last message of each activation bar.
- `meta.viewBox` is the minimum size; the height grows with the messages.

Everything else (participants, message labels, variants, notes, `meta.views`
chapters and the summary `cards`) is plain Archify. Internal steps of one
participant go into a message `note`: Archify arrows need two participants.

The build validates each document against Archify's `showcase` quality
profile and fails on any error or warning, then delivers the HTML and
records the hashes in `Build/diagrams.lock.json`. `npm run diagrams:check`
verifies those hashes without node modules or a browser.
