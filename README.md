# solutions

Canonical source for solutions team tools including microchaos; but may be expanded in the future to home additional tools.

## Tools

| Path | What it is |
|------|------------|
| [`tools/microchaos-cli`](tools/microchaos-cli) | WP-CLI load testing for WordPress on Pressable. Drives traffic from inside the container, so it works where external load testers are blocked by loopback rate limits. |

## Layout

Each tool lives in its own directory under `tools/` and is self-contained: its own
build, its own tests, its own `.gitignore`. The root only holds things that apply
to every tool.

Tools are imported with `git subtree`, so their upstream history comes with them
and `git blame` still answers questions about code written before it moved here.

## Versioning

Tags are prefixed with the tool they release, because one repository holds several
of them:

```
microchaos/v4.1.0
```

Anything consuming a tool from here should pin to a tag rather than a branch. A
tag is protectable and reads as a deliberate version bump; a branch tip means two
runs a month apart may have used different code with no way to tell after the fact.

## Licensing

This repository is GPL-3.0-or-later.

`tools/microchaos-cli` derives from
[phillipclapham/microchaos-cli](https://github.com/phillipclapham/microchaos-cli)
via [pressable/pressable-microchaos-cli](https://github.com/pressable/pressable-microchaos-cli),
imported at commit `20fd0d8`. It was already GPL-3.0 and keeps its own `LICENSE`
file; modifications made since the import are recorded in this repository's commit
history.
