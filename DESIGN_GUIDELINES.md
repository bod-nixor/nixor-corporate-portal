# Nixor Corporate Portal - Design Guidelines

This document outlines the visual language and component architecture for the Nixor Corporate Portal. Future updates and new UI components must adhere to these guidelines to preserve a cohesive, premium, and professional feel.

## 1. Core Principles
- **Apple-level Polish**: Maintain clean, sharply structured, and calm interfaces. Use generous padding, thoughtful negative space, and clear sectioning.
- **Restraint**: Avoid gimmicky "vibe-coded" looks or excessive glassmorphism. Subtle surfaces, smooth micro-animations, and high-quality typography are preferred.
- **Institutional & Professional**: The portal is an enterprise tool. It should feel stable, trustworthy, and productive (similar to Stripe Dashboard, Linear, Vercel, or Notion).

## 2. Global Aesthetics & Themes
- **Theming System**: The portal supports multiple premium theme variants (Default Dark, Light, Midnight, Graphite, Indigo, Emerald) via `.theme-[name]` classes applied to `:root`.
- **Token Structure**:
  - `var(--bg-base)`: The deepest background color for the main app canvas.
  - `var(--bg-surface)`: Elevated surfaces like cards and sidebars.
  - `var(--bg-surface-hover)`: Interactive surfaces or secondary depths.
  - `var(--border-subtle)` / `var(--border-strong)`: Translucent and tinted boundaries.
  - `var(--text-primary)` / `secondary` / `tertiary`: Readability hierarchy colors.
  - `var(--color-primary)`: The main accent color used for buttons, rings, and highlights.
- **Acceptable Accent Behavior**: Accents must remain elegant, professional, and readable (AAA contrast where possible on small text). Avoid gimmicky neon or over-saturated colors. Dark themes should use muted, deeper accents, while Light mode relies on primary blues/blacks.
- **Adding New Themes**:
  1. Define a new `:root.theme-[name]` block in `global.css`.
  2. Override the 10 core surface, border, text, and primary color tokens.
  3. Ensure sufficient contrast (e.g., text against surface, primary against base).
  4. Add a preview button to `settings.html` and wire it up via `data-theme`.

## 3. Responsive Behavior
- **Mobile First**: All pages must scale down gracefully.
- **Content Safe Padding**: Ensure elements don't hug the screen edges on mobile (use `p-4 md:p-6 lg:p-8`).
- **Mobile Drawers**: The sidebar collapses into a drawer or hidden menu on mobile, toggled via a distinct header button.
- **Forms & Cards Stack**: Ensure `grid` and `flex` layouts break to single columns early enough that forms don't crush.
- **Tables**: Data tables must use `overflow-x-auto` to allow horizontal scrolling on small screens without breaking the page layout.
- **Toolbars**: Complex action bars (like in Entity Drive) should wrap functionally (`flex-wrap`) and scale down input size.

## 4. Components & Layouts
- **Cards**: Base container for information. Hoverable cards should elevate (`translateY`) and apply a deeper shadow.
- **Forms / Inputs**: Standardize on a calm, subtle look with a focused ring matching the primary brand color. 
- **Buttons**:
  - `btn-primary`: Bright, distinct white/primary, used for the main action. Apple style: inverted dark mode (white bg, dark text) logic or stark primary.
  - `btn-secondary`: Subtle surface background, used for standard actions.
  - `btn-ghost`: Transparent, used for cancelling or minor actions to reduce visual clutter.
  - `btn-danger`: Red tinted surface, used carefully for destructive states.
- **Empty States**: Must be communicative, guiding the user to the next action or explaining the lack of data with an icon/graphic and descriptive text.

## 5. Implementation Rules
- Always use the tokens defined in `global.css`. Avoid hard-coding HEX values in templates if a token exists.
- Preserve all structural `id` tags and `data-*` attributes used by the backend or vanilla JS handlers.
- Do not bypass the `sidebar.js` wrapper for authenticated pages.

Follow these guidelines strictly to ensure the portal remains cohesive as it evolves.
