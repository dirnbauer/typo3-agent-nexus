# Protocol diagrams

One sequence diagram per protocol, rendered with
[Archify](https://github.com/tt-a1i/archify) (MIT) into a self-contained,
interactive HTML file in `Resources/Public/Diagrams/<key>.html`. The protocol
info plugin shows it in embed mode (`?embed=1&theme=light|dark`); the full
viewer, with diagram navigation, trace and export, opens from the caption link.

## Source format

`<key>.json` is an Archify sequence document (`diagram_type: "sequence"`)
without coordinates. `npm run diagrams` (Build/render-diagrams.mjs) adds them:

- `phases` lists the messages in order, grouped into labelled phases. Every
  message gets its own row (42 px), phases are 14 px apart, and each phase
  becomes an Archify segment.
- `activations` name the first and last message of each activation bar.
- Legacy `meta.viewBox` values stay in the authored sources. The renderer
  removes that fixed canvas from the generated specification so Archify sizes
  the timeline and legend and declares its responsive width-first Reader fit.

The renderer supplies the portable `meta.output` name required by Archify 3.2.
Everything else (participants, message labels, variants, notes and the summary
`cards`) is plain Archify. The authored `meta.views` chapter metadata remains
in the sources; Archify 3.2 replaces the older Guided/Story viewer with diagram
navigation and trace controls. Internal steps of one
participant go into a message `note`: Archify arrows need two participants.

The build validates each document against Archify's `showcase` quality
profile and fails on any error or warning, then delivers the HTML and
records the hashes in `Build/diagrams.lock.json`. `npm run diagrams:check`
verifies those hashes without node modules or a browser. `npm run test:diagrams`
checks source preservation, wrapped-note spacing and legend clearance.

The generator pins the stable Archify v3.2.0 release commit (MIT).
