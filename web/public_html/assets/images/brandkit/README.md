# Handoff: WebMS brand & logo assets

## Overview
A complete, self-contained brand kit for one product line with three sub-brands:

| Brand | Name | Use |
|---|---|---|
| **WebMS** | Website Management System | Generic / parent product |
| **ChurchMS** | Church Management System | Church preset |
| **WebMS-Intra** | Internal Management Portal | Internal-tools portal (sub-brand of WebMS) |

They are designed as **one family**: identical indigo palette, shape language and wordmark treatment. Only the emblem motif and the prefix word change. This repo (`WebMS-Intra`) primarily needs **`assets/webms-intra/`**; `assets/webms/` is included for shared/parent contexts.

## About these files — IMPORTANT
**These are final, production-ready SVG assets — not mockups to recreate.** Use them **as-is**. Do **not** re-draw, re-trace, or convert them to components by hand. Drop the `assets/` tree into the repo and reference the SVGs directly (`<img>`, CSS `background`, `mask`, or inline `<svg>`).

All text in the wordmarks/lockups is **converted to vector outlines** — there is **no font dependency**, so they render identically everywhere with nothing to install.

**Sizing:** every SVG is fluid — root `width="100%" height="100%"` + `viewBox` + `preserveAspectRatio="xMidYMid meet"`. They **scale to fill their container** (centred, no distortion). Just size the parent (or the `<img>`/element via CSS), e.g. `.logo { height: 32px; }` or `width: 100%`.

## Each brand folder contains 6 SVGs
| File | What it is | Typical use |
|---|---|---|
| `emblem.svg` | Symbol only, transparent bg | Inline marks, favicons, watermarks |
| `emblem-animated.svg` | Emblem with a 4.5s breathing loop¹ | Loading / splash accents |
| `icon.svg` | Emblem on a rounded indigo tile | PWA / app icon, favicon, avatar |
| `wordmark.svg` | The name only (`WebMS` / `ChurchMS` / `WebMS Intra`) | Headers, nav, tight spaces |
| `subtag.svg` | Letterspaced descriptor, justified to the wordmark width | Under the wordmark, footers |
| `full.svg` | Emblem + wordmark + descriptor lockup | Primary logo: headers, login, docs |

¹ **Animation:** SVG animation can only be CSS or SMIL. The tool these assets were authored in strips both from saved `.svg` files (a storage-sanitiser quirk — it does **not** happen in your repo/build), so the bundled `emblem-animated.svg` files render **static**. Two ways to get the live breathing version:
- **`download-animated.html`** — open it and click "Download .svg" to save real animating SVG files (built client-side, so the animation is intact). They breathe when opened directly in any browser.
- **Inline** the emblem markup in your app and apply the `.brand-breathe` / `.brand-glow` classes from `assets/brand-tokens.css` (reduced-motion safe). `animation-demo.html` is a live reference for all three brands.

## Palette / design tokens
Defined in `assets/brand-tokens.css`:

| Token | Hex | Role |
|---|---|---|
| `--brand` | `#5e6ad2` | Indigo — primary |
| `--brand-2` | `#7a85e8` | Indigo light — gradient end / glow |
| `--ink` | `#23263a` | Wordmark prefix (near-black) |
| `--grey` | `#8b90a0` | Descriptor / subtag |
| `--intra` | `#a3a9b6` | The half-height "Intra" suffix word |

The emblem uses a single indigo gradient (`#5e6ad2 → #7a85e8`). To **recolour** an SVG, edit its `<linearGradient>` stops and the `fill="…"` values, or find-and-replace the hex codes above. Layers inside each SVG are named groups (`id` + `<title>`: e.g. `emblem`, `wordmark`, `wm-prefix`, `wm-ms`, `subtag`, and for the network mark `web-connections` / `web-nodes` / `web-hub`).

## Typography (for reference / re-typesetting)
Wordmarks are outlined from **Plus Jakarta Sans** — prefix **700**, `MS` **800**, the `Intra` suffix **600** (at 50% height, `--intra` grey), descriptors **600** letterspaced caps. If you ever need editable text instead of outlines, set type in Plus Jakarta Sans at those weights and match the colours above.

## Suggested integration
1. Copy `assets/webms-intra/` (and `assets/webms/` if needed) into the repo, e.g. `public/brand/` or `src/assets/brand/`.
2. Add `assets/brand-tokens.css` to the global styles (or fold the variables into the existing theme).
3. **Favicon / PWA:** point `manifest.json` icons and `<link rel="icon">` at `icon.svg` (SVG favicons are supported by modern browsers; add a PNG fallback only if you must support legacy).
4. **App header / login:** use `full.svg`; fall back to `wordmark.svg` in narrow layouts.
5. **Animated accents:** inline the emblem and apply `.brand-breathe` / `.brand-glow` (reduced-motion safe).

## Files in this bundle
```
assets/
  brand-tokens.css           ← palette + breathing animation CSS
  webms/        {emblem,emblem-animated,icon,wordmark,subtag,full}.svg
  churchms/     {emblem,emblem-animated,icon,wordmark,subtag,full}.svg
  webms-intra/  {emblem,emblem-animated,icon,wordmark,subtag,full}.svg
animation-demo.html          ← live breathing emblems (all three brands)
README.md                    ← this file
```

---

## Paste-this prompt for Claude Code
> These are final, production-ready SVG brand assets for our product line (WebMS, ChurchMS, WebMS-Intra) — use them as-is, do not re-draw them. Add the `assets/webms-intra/` and `assets/webms/` folders to our repo following our existing asset conventions, and wire up: (1) favicon + PWA manifest icons from `icon.svg`; (2) the app header/login logo using `full.svg`, falling back to `wordmark.svg` in tight layouts; (3) import `brand-tokens.css` into our theme (or merge the variables into our existing tokens). For any breathing-emblem usage, inline the emblem SVG and apply the `.brand-breathe`/`.brand-glow` classes from `brand-tokens.css` (it's already reduced-motion safe). The wordmark text is outlined (no font needed). Match our existing import/lint/formatting patterns.
