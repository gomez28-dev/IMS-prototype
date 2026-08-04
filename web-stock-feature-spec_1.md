# Feature Spec: Web Stock (Fuel Storage Module)

## 1. Context — Existing System

This is a Laravel 11 Inventory Management System (IMS) for a fuel sales business. It already has:

- **Orders** — a sales order (`account`, `so_number`, `po_number`, `qty_ordered`, `clearing_status`)
- **Deliveries** — one order can have many deliveries (`dr_number`, `qty_out`, `status`, `type`, `delivery_date`)
  - `status` is one of: `PENDING`, `FULFILLED`, `CANCELLED`
  - Only deliveries marked `FULFILLED` count toward the order's "total quantity delivered"

**This existing PENDING → FULFILLED logic is the foundation Web Stock must reuse — not replace.**

## 2. Architecture Decision — One System, Not Two

Web Stock will be built as a **new module inside the existing IMS app** — same codebase, same database, same login/session, not a separate application.

This was a deliberate choice, not a default: a Facebook/Messenger-style split (two independent apps talking over an API) was considered and rejected, because Web Stock's numbers are fully dependent on real-time Sales delivery status changes — building a sync API between two apps just to keep them in step would be pure overhead with no upside here.

### 2.1 Navigation model — portal picker, not a shared navbar

A flat navbar with both modules' links crammed into one bar (e.g. "Sales inventory | Web stock" as tabs, each pulling in its own sub-links) was tried and rejected — it gets crowded fast once each module needs its own sub-navigation, and it's poor UX for warehouse staff who only ever need Web Stock.

**Confirmed structure instead:**
- After login, the user lands on a **portal picker screen** with two cards: "Sales Inventory" and "Web Stock," each with a short description and an "Enter" button.
- Each portal then has **its own scoped navbar** with only its own sub-links (e.g. Web Stock's navbar: Dashboard, Warehouses, Stock In). No links to the other portal appear inside either navbar.
- **Switching portals is intentionally not one click** — the user logs out and picks again from the portal screen. This is by design: it keeps warehouse staff focused on Web Stock without a distracting "back to Sales" link they'd never use, at the cost of a few extra clicks for the rare user (e.g. an admin) who needs both.
- This is still "one system, not two" per Section 2 — same login, same session, same database. Only the navigation UI is split into scoped portals instead of a shared bar.

## 3. What Web Stock Is

Web Stock is a **fuel storage tracking module**, dependent on (not separate from) the Sales/Delivery module above. It reflects and reacts to what's already happening in Sales rather than running its own independent workflow.

Web Stock has two operations:
- **IN** — fuel being stocked/added into a storage tank
- **OUT** — fuel being taken out of the tank because a delivery tied to it was fulfilled

## 4. Physical Structure

- **2 Warehouses**: Valenzuela and Pampanga
- Each warehouse contains **multiple Storage Tanks**
- Tanks within a warehouse are otherwise identical in behavior — the **only difference between tanks is their volume/capacity** (how much fuel each one currently holds and how much it can hold)

## 5. Main Dashboard Metrics

The Web Stock dashboard (per warehouse, and per tank) shows three numbers:

| Metric | Meaning |
|---|---|
| **Stock Available** | Fuel currently usable/on-hand in the tank |
| **Stock for Delivery** | Fuel already earmarked for a delivery that hasn't been completed yet |
| **Out** | Fuel that has actually left the tank (completed deliveries) |

## 6. Core Business Rule (the important part)

Stock Available is **only reduced when a delivery is marked FULFILLED** — a PENDING delivery reserves/earmarks stock but does not deduct it yet.

**Worked example the team gave:**

1. Stock IN: `50,000` liters added to a tank.
   → Stock Available = `50,000`
2. A Sales delivery (DR) is created for `20,000` liters, status = `PENDING`.
   → Stock Available is **still `50,000`** (not yet deducted)
   → Stock for Delivery = `20,000`
3. That same DR later changes status to `FULFILLED`.
   → Out = `20,000`
   → Stock Available = `50,000 − 20,000` = `30,000`
   → Stock for Delivery = `0` (no longer pending)

**Formulas:**
```
Stock Available   = Total Stock IN  −  Sum of qty_out for deliveries where status = FULFILLED
Stock for Delivery = Sum of qty_out for deliveries where status = PENDING
Out                = Sum of qty_out for deliveries where status = FULFILLED
```
(This mirrors the existing `Order::getTotalQtyOutAttribute()` logic in the codebase, which already sums only `FULFILLED` deliveries — Web Stock reuses this same pattern, scoped to a tank instead of an order.)

## 7. Confirmed Decisions

These were open questions, now answered by the team:

1. **One tank per delivery** — for now, each delivery is linked to exactly one specific storage tank (not split across multiple tanks). A delivery still needs a way to select which tank it draws from (form field, likely manual selection) — the rule is just that it resolves to a single tank.
2. **Stock IN is a manual form entry**, logged with a history/audit trail (similar to the existing `AuditLog` pattern).
3. **Tank capacity is enforced (for now)** — a Stock IN that would exceed a tank's max capacity should be blocked.
4. **Deliveries draw from a specific tank, not the warehouse's combined total** — confirmed strongly. Web Stock math must be scoped per-tank first, then rolled up to a warehouse total for the dashboard view.
5. **Cancelled deliveries are likely excluded** from both "Stock for Delivery" and "Out" (same as the rest of the app) — flagged as "maybe yes," so worth a quick double-check with the supervisor before finalizing, but safe to build this way for now.
6. **Warehouse access is not restricted per-user** — staff accounts can access both Valenzuela and Pampanga, since the same staff manage both warehouses. No per-warehouse permission split is needed.

## 8. Suggested Scope for Implementation Plan

Ask the other AI to produce a plan covering:
- New tables/models: `Warehouse`, `StorageTank`, `StockIn` (manual entry + audit trail), plus a `tank_id` (or similar) field linking each `Delivery` to exactly one `StorageTank`
- Tank capacity validation on Stock IN (reject/flag entries that would overfill a tank)
- A **portal picker screen** shown right after login (Sales Inventory / Web Stock, each with an "Enter" button) — see Section 2.1
- A **Web Stock portal** with its own scoped navbar (Dashboard, Warehouses, Stock In) and pages:
  - **Dashboard** — per-tank stats rolling up into a per-warehouse total (Stock Available / Stock for Delivery / Out)
  - **Warehouses** — view/manage Valenzuela and Pampanga and their tanks
  - **Stock In** — the manual form entry from Confirmed Decision #2
- No cross-navigation between Sales Inventory and Web Stock inside either portal's navbar — switching portals means logging out and picking again (see Section 2.1)
- Reuse of the existing PENDING/FULFILLED/CANCELLED delivery status logic rather than building a parallel status system
- No per-warehouse user permission restrictions — any authenticated staff account can view/manage both Valenzuela and Pampanga
