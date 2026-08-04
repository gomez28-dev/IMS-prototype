# IMS navbar → collapsible sidebar redesign — implementation plan

## 1. Goal

Replace the current crowded top navbar in `resources/views/layouts/app.blade.php` with a
collapsible left sidebar (desktop) + a slim top bar, to reduce visual crowding and give the
nav room to grow. Preserve all existing routes, role-based visibility logic, and the existing
mobile drawer behavior — this is a presentation-layer change only, no backend/route changes.

## 2. Current state (for context)

- Single file: `resources/views/layouts/app.blade.php` (Blade layout, extended by all views).
- Stack: Laravel Blade + Bootstrap 5 (CDN) + Bootstrap Icons + Montserrat font. No build step /
  no Vite bundling of custom CSS — all custom styles live in a `<style>` block in this file's
  `<head>`.
- Existing top navbar (`.navbar-custom`) currently packs into one row: logo, portal label,
  primary nav links (varies by section), "Switch Portal" link, user name + role badge, logout
  button. This is the crowding problem.
- There is already a **mobile** sliding drawer (`.sidebar-drawer` / `#sidebarDrawer`, toggled by
  `openSidebar()` / `closeSidebar()`) that duplicates the nav links for screens `< 992px`. Its
  structure is a good starting point for the new **desktop** sidebar — we are essentially
  promoting this pattern to be the primary desktop nav too, not just a mobile fallback.
- CSS variables already defined in `:root` — reuse these, don't hardcode new colors:
  `--primary-navy: #0f172a`, `--secondary-navy: #1e293b`, `--brand-color: #FF4500`,
  `--brand-hover: #CC3700`, `--light-slate: #f8fafc`, `--border-color: #e2e8f0`,
  `--text-dark: #334155`, `--text-muted: #64748b`.
- Role badge classes already exist and should be reused as-is: `.badge-role-admin`,
  `.badge-role-editor`, `.badge-role-viewer`, `.badge-role-warehouse`, `.badge-role-accounting`.

## 3. Target design

Reference mockup (already reviewed/approved by stakeholder): dark navy (`--primary-navy`)
fixed-width left sidebar containing brand mark, section label, primary nav links with an
orange (`--brand-color`) active-state left border, and a bottom-anchored user menu. A
chevron toggle button next to the brand mark collapses the sidebar to a 68px icon-only
rail and back (same interaction pattern as Firefox's collapsible bookmarks sidebar — icons
persist, labels fade out, a chevron flips direction to indicate expand/collapse).

Top bar shrinks to: current page title (left) + page-specific primary actions / notification
icon placeholder (right, optional, page-dependent — out of scope to wire up real notifications).

### Key changes vs. current navbar
| Current | New |
|---|---|
| Nav links in horizontal navbar row | Nav links as vertical list in left sidebar |
| "Switch Portal" as an inline nav link | Secondary link inside sidebar nav, visually de-emphasized (smaller, muted) |
| User name + role badge + Logout as 3 separate elements | Single user menu control (avatar + name + role) at bottom of sidebar, expands to reveal Logout |
| No collapse option | Chevron toggle collapses sidebar to icon-only rail, state persists across page loads |
| Mobile: separate `.sidebar-drawer` overlay | Keep mobile drawer as-is (bootstrap breakpoint `< 992px`); desktop sidebar only applies `>= 992px` |

### Note on nav items — correction from mockup
The earlier visual mockup included a "Manage Tanks" sidebar link. **Do not add this** — per
`routes/web.php` there is no standalone tanks index route; tank management
(`wetstock.tanks.create` / `.edit` / `.toggle-active`) is only accessible from within a
warehouse page (`wetstock.warehouses.show`). Sidebar nav items must map 1:1 to the actual nav
links already present in the current navbar/drawer — do not invent new top-level items.

## 4. Sidebar nav items (exact — mirror current logic in the Blade file)

**Wet Stock section** (shown when `request()->is('wetstock*')`):
| Label | Route | Icon |
|---|---|---|
| Dashboard | `wetstock.dashboard` | `bi-speedometer2` |
| Stock IN Log | `wetstock.stock-in.index` | `bi-fuel-pump` |
| Assign Deliveries | `wetstock.deliveries.assignment-history` | `bi-truck` |

**Sales Inventory section** (shown when `!request()->routeIs('portal')` and not wetstock):
| Label | Route | Icon | Visibility |
|---|---|---|---|
| Dashboard | `dashboard` | `bi-speedometer2` | all authenticated users |
| Reports | `reports.index` | `bi-bar-chart-line` | all authenticated users |
| Manage Accounts | `accounts.index` | `bi-people` | `Auth::user()->isAdmin()` |
| Manage Clients | `clients.index` | `bi-building` | `Auth::user()->isEditor()` |
| Audit Log | `audit-logs` | `bi-journal-text` | `isAdmin() || isAccounting()` |

**Always** (both sections, when not already on the portal page):
| Label | Route | Icon | Style |
|---|---|---|---|
| Switch Portal | `portal` | `bi-grid` | de-emphasized (smaller text, muted color, separated by a divider) |

Keep all `@if` / `@elseif` gating exactly as currently written — only the container markup
and CSS classes change, not the conditional logic.

## 5. Component specs

### 5.1 Sidebar container
- `<aside id="appSidebar" class="app-sidebar" data-collapsed="0">`, visible only `>= 992px`
  (`d-none d-lg-flex`, `flex-direction: column`).
- Width: `220px` expanded, `68px` collapsed (`data-collapsed="1"`), animate `width` with a
  `0.15s ease` transition.
- Background: `var(--primary-navy)`.
- Fixed to the left edge, full viewport height, page content offset by sidebar width via a
  wrapper `margin-left` (or flex layout — either is fine, pick whichever fits the existing
  `<body>` structure with the least disruption).

### 5.2 Header row (brand + collapse toggle)
- Left: existing logo image (reuse `{{ asset('images/logo_ims.png') }}` or a simplified badge
  version if the full logo doesn't fit at 68px collapsed).
- Right: icon-only button, `aria-label="Collapse sidebar"` / `"Expand sidebar"` (update
  dynamically), `bi-chevron-left` / `bi-chevron-right`, toggles `data-collapsed` attribute.
- On collapse: hide brand text/logo wordmark if applicable, center the remaining icon(s), CSS
  attribute-selector driven (`.app-sidebar[data-collapsed="1"] .brand-label { display: none }`).

### 5.3 Nav list
- Each link: icon (fixed width, e.g. `1.25rem`) + `<span class="nav-label">Label</span>`.
- Active state: current route/section highlighted with `background: rgba(255,69,0,0.15)` (or
  reuse `--brand-color` at low opacity) + `border-left: 3px solid var(--brand-color)`. Determine
  "active" via `request()->routeIs(...)` per link.
- Collapsed state: hide `.nav-label`, center icons, active state uses background fill instead
  of left border (border doesn't read well at 68px).
- "Switch Portal" separated from primary links by a `<hr>`/divider, smaller font size, muted
  color (`var(--text-muted)` on dark bg → use a lighter muted tone like `#94a3b8` since sidebar
  bg is dark navy, not light).

### 5.4 User menu (bottom of sidebar)
- Button containing: avatar circle with initials (derive from `Auth::user()->name`, e.g. first
  letters of first + last word) + name + existing role badge (reuse `.badge-role-*` classes,
  note: these badges were designed for light backgrounds — verify contrast on navy background,
  adjust badge text/border colors if needed for this dark context only) + chevron.
- Click toggles a small popover/dropdown containing:
  - Logout (existing form + `route('logout')` POST, keep CSRF)
- Collapsed state: hide name/role/chevron, show avatar only, centered; dropdown still
  accessible on click, anchored so it doesn't get clipped by the sidebar edge (open to the
  right/flyout rather than below, since there's no room below at rail width).

### 5.5 Top bar
- Slim bar (`~52-56px` height), border-bottom `1px solid var(--border-color)`.
- Left: current page title. Use the existing `@yield('title', ...)` value, or add a new
  `@yield('page-heading')` section if a shorter/cleaner label is needed than the `<title>` tag
  value (check each Blade view that extends this layout and set an appropriate short heading).
- Right: reserved space for page-specific action buttons — do not hardcode "Log Stock IN" etc.
  into the shared layout; instead expose a `@yield('page-actions')` (or a stack `@stack`) block
  that individual views can populate, so this stays generic across all pages using the layout.
- Keep the existing session flash alerts (`session('success')`, `session('danger')`,
  `session('info')`) rendering below the top bar, inside the main content area, unchanged.

### 5.6 Mobile (`< 992px`)
- No structural change required: keep the existing `.sidebar-drawer` / hamburger button /
  overlay exactly as implemented today. Only restyle it lightly if needed so it visually matches
  the new sidebar's look (nav item styling, active states) for consistency — this is optional
  polish, not required for the redesign to succeed.
- Ensure `#appSidebar` (new desktop sidebar) and `.sidebar-drawer` (existing mobile drawer) are
  never both visible at the same breakpoint — `d-none d-lg-flex` on the desktop sidebar,
  `d-lg-none` on the mobile drawer (drawer already has this).

### 5.7 Collapse state persistence
- Store collapsed/expanded state in `localStorage` (key e.g. `ims_sidebar_collapsed`).
- To avoid a flash of the wrong state on page load, read the stored value in a small **inline
  script placed early in `<head>`** (before the sidebar renders) and set the `data-collapsed`
  attribute on `<html>` or directly write it via a class before first paint.
- This is a full page reload app (Blade, no SPA), so state must be re-applied on every page
  load — a plain `localStorage` read is sufficient; a server-side cookie is a nice-to-have
  upgrade but not required for v1.

## 6. Accessibility
- Collapse toggle: `aria-label` (dynamic, matches current action), `aria-expanded` reflecting
  sidebar state.
- Nav links: no change needed beyond existing semantics (`<a>` tags already correct).
- User menu button: `aria-haspopup="true"`, `aria-expanded` toggled on open/close.
- Maintain keyboard operability: toggle button and user menu must be reachable/operable via
  `Tab` + `Enter`/`Space`, not click-only.
- Icon-only collapsed state: nav links must still have accessible text — do not remove the
  label from the DOM, only visually hide it (`.nav-label` hidden via CSS, not conditionally
  rendered in Blade), or provide a `title`/`aria-label` fallback so screen readers still get
  the label.

## 7. Implementation steps

1. In `resources/views/layouts/app.blade.php`, add the new sidebar CSS (container, header,
   nav list, active/collapsed states, user menu, top bar) to the existing `<style>` block —
   reuse existing CSS variables, don't introduce new hardcoded colors.
2. Build the sidebar markup as a new `@auth`-gated block, using the nav item table in section 4
   and the exact same `@if (request()->is('wetstock*')) @elseif (...) @endif` structure
   currently in the mobile drawer — do not change the conditional logic, only the surrounding
   markup/classes.
3. Build the collapsed/expanded toggle button + small inline `<script>` for:
   - toggling `data-collapsed` on click,
   - reading/writing `localStorage`,
   - swapping the chevron icon class,
   - toggling `aria-expanded`.
4. Build the user menu (avatar initials, existing role badge partial reused, logout form
   reused as-is from the current navbar).
5. Replace the current `<nav class="navbar ...">` block with the new slim top bar (title +
   `@yield('page-actions')` slot). Remove the now-redundant desktop nav markup from inside the
   old navbar (the drawer stays for mobile).
6. Adjust the `<body>`/content wrapper so the main content area is offset by the sidebar
   width on `>= 992px` and full-width on `< 992px` (existing mobile behavior unaffected).
7. Add `@section('page-heading')` / `@section('page-actions')` to each view currently relying
   on in-page headers/buttons that duplicated navbar content, if any — audit views under
   `resources/views/` for anything that assumed the old navbar layout.
8. Manually test every role (admin, editor, accounting, warehouse, viewer) to confirm nav item
   visibility still matches current behavior exactly (no regressions in what each role can see).
9. Test at `992px` boundary specifically (Bootstrap's `lg` breakpoint) to confirm no dead zone
   where neither sidebar nor drawer is accessible.

## 8. Acceptance criteria

- [ ] All existing routes/links reachable from the new sidebar; no link removed or added beyond
      what's listed in section 4.
- [ ] Role-based visibility of nav items unchanged (verified per role).
- [ ] Sidebar collapses to a 68px icon rail and back via the chevron toggle.
- [ ] Collapsed/expanded state persists across full page reloads (localStorage).
- [ ] User menu consolidates name, role badge, and logout into a single control.
- [ ] Mobile (`< 992px`) experience unchanged from current behavior (drawer + hamburger).
- [ ] No hardcoded colors introduced — all new styles reference existing CSS variables.
- [ ] Keyboard-only navigation can open/close both the sidebar collapse toggle and the user
      menu.
- [ ] No JS console errors; no layout shift/flash of uncollapsed sidebar on reload when
      previously collapsed.

## 9. Out of scope (do not do in this pass)

- Real notification bell functionality (icon can be a visual placeholder only).
- Any change to `routes/web.php`, controllers, or middleware.
- Adding a "Manage Tanks" or any other nav item not in section 4.
- Restyling the mobile drawer beyond optional cosmetic alignment.
- Any change to `resources/views/wetstock/tanks/form.blade.php` or other page-level content.
