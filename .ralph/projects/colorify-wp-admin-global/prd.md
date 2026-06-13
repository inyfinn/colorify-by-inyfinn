# PRD: Colorify — Global wp-admin Dark Mode Readability

## Goal

Ensure **every wp-admin subpage** is readable in dark mode for **unknown third-party plugins**, using structural CSS patterns — not per-plugin class prediction.

## Success criteria

1. Zero **critical** contrast failures (light text on light bg OR dark text invisible on dark bg) in `#wpbody-content` on all audited screens.
2. Global CSS layer (`colorify-admin-global-readable.css`) loaded **last** catches plugin white boxes, form controls, tables, modals.
3. Plugin-specific rules in `colorify-admin-plugins.css` remain only where global structural rules cannot reach (iframes, shadow DOM, unique widgets).
4. Browser CDP contrast audit passes on all stories in `tasks.json`.

## Architecture

```
colorify-branding.css          → tokens, base wp-admin chrome
colorify-admin-overrides.css   → guardrails, readable text vars
colorify-global-darkmode.css   → CSS custom properties + structural patterns
colorify-admin-plugins.css     → legacy per-plugin (shrinking)
colorify-admin-global-readable.css → FINAL catch-all (loads last)
colorify-admin-compat.js       → dismiss/pointer/modal JS fixes
```

## Global strategies

| Layer | Strategy |
|-------|----------|
| Surfaces | Force dark tokens on `#wpbody-content`, `.wrap`, `.postbox .inside`, `[id$="-wrap"]`, modals on `body` |
| Light bg kill | Attribute selectors for inline `#fff`, `#fafafa`, `#f6f7f7`; broad `background-color` on nested divs in metaboxes |
| Text | Reset `-webkit-text-fill-color`; inherit readable tokens on all text nodes |
| Forms | All `input`/`select`/`textarea` except checkbox/radio/hidden |
| Links | Default readable accent; muted only for `.row-actions`, `.description` |
| Overlays | `.wp-pointer`, `#TB_*`, `.components-modal`, `.jitm-banner`, `.woocommerce-layout` |
| Tables | `.widefat`, `.wp-list-table`, generic `table` in content |

## Out of scope

- Front-end / block editor canvas (frontend)
- Plugin iframes with isolated stylesheets (document separately)
- Light mode regressions

## Test environment

- Local WP: `http://eta-innovations-2.local/wp-admin/?localwp_auto_login=1`
- Scheme: dark avocado, accent `#cd6313`
- Product edit: `post.php?post=12441&action=edit`

## Release

Version **1.4.0** after all P0 stories pass browser audit.
