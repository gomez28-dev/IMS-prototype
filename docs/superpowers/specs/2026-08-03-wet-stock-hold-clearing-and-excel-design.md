# Wet Stock Report: Hold for Clearing from Sales Documentation + Template-Faithful Excel Design

Date: 2026-08-03

## Background

The Wet Stock Report module (new, uncommitted work by the project owner) computes a per-warehouse top summary. Two changes are requested:

1. **HOLD FOR CLEARING** must include sales documentation volume: the sum of `qty_ordered` from the `orders` table where `clearing_status = 'Pending'`.
2. The **Excel export** must be designed to match `WET STOCK REPORT.xlsx` (the company's original spreadsheet format): colors, fonts, borders, merges, and column widths.

## Current Behavior

- `ReportController::compileLiveReport()` builds per-warehouse blocks (`$reportData['blocks'][$warehouse->id]`) with:
  - `depot_total`, `tankers_total`, `contaminated_total` (tanks)
  - `unlifted_supplier_total`, `pending_supplier_total` (supplier orders)
  - `big_tanker_total`, `small_tanker_total`, `client_pickup_total` (PENDING deliveries)
  - `total_commitments_in` = depot + tankers + contaminated + unlifted + pending
  - `client_pending_delivery_total` = big tanker + small tanker
  - `total_hold_for_clearing` = client pickup + client pending delivery
  - `total_available_for_sale` = commitments in + hold
  - `total_available_on_hand_for_selling` = (depot + tankers) − client pickup
- The `orders` table has `clearing_status` (`Pending` / `Approved` / `Declined` / `Hold`, default `Pending`). Orders carry a `location` (e.g. `Valenzuela`, `San Simon`) matching warehouse names.
- Excel export is plain: `resources/views/wetstock/reports/excel.blade.php` renders tables; `WetStockReportExport` uses `FromView`, `ShouldAutoSize`, `WithStyles`, `WithTitle`. No fills/borders/merges.

## Part 1 — HOLD FOR CLEARING (behavior change)

### Rules

- New per-block value: `pending_clearance_orders_total` = SUM of `qty_ordered` from `orders` where:
  - `clearing_status = 'Pending'` (strictly Pending — not Approved, not Declined, not Hold), AND
  - `location` matches the warehouse name case-insensitively (trimmed), mirroring the existing delivery→warehouse matching approach.
- `total_hold_for_clearing` = `client_pickup_total` + `client_pending_delivery_total` + `pending_clearance_orders_total` (user chose: keep delivery-based hold AND add sales-doc hold).
- `total_available_for_sale` = `total_commitments_in` + `total_hold_for_clearing` (formula unchanged; value grows).
- `total_available_on_hand_for_selling` unchanged.

### UI changes

Top summary gains a row between CLIENT PENDING DELIVERY and TOTAL, in every surface:

- Live portal: `resources/views/wetstock/reports/index.blade.php`
- Snapshot view: `resources/views/wetstock/reports/show_snapshot.blade.php`
- Excel export: `resources/views/wetstock/reports/excel.blade.php`

New row label: **SALES DOCS PENDING CLEARANCE** (value = `pending_clearance_orders_total`, with a small note: "SUM of qty_ordered where clearance = Pending").

Detail tables (UNLIFTED STOCK PICK UP, PENDING STOCK DELIVERY, BIG/SMALL TANKER UNDELIVERED, CLIENT PICK UP) are unchanged.

### Snapshot compatibility

Snapshots serialize the whole block map (`report_data` JSON), so the new key is captured automatically at save time. Older snapshots lack the key — views must tolerate a missing `pending_clearance_orders_total` (fall back to 0) so historical snapshots still render.

## Part 2 — Excel design (template-faithful)

Replicate the visual system of `WET STOCK REPORT.xlsx` (verified from its `styles.xml` / `sheet1.xml`):

### Palette & typography

| Element | Style |
|---|---|
| Section headers (WET STOCKS, "<WAREHOUSE> REPORT BREAKDOWN", UNLIFTED STOCK PICK UP, PENDING STOCK DELIVERY, BIG TANKER UNDELIVERED, SMALL TANKER UNDELIVERED, CLIENT PICK UP) | Fill `#FF9900`, white bold text |
| Column header rows (WAREHOUSE/LITERS/REMARKS × TANKERS/LITERS/REMARKS; PO#/LOCATION/SUPPLIER/LITERS; SO#/CLIENT/LITERS) | Fill `#FCE5CD`, bold text |
| TOTAL value cells (top summary + breakdown + detail tables) | Fill `#CCCCCC` |
| Body cells | Thin black borders, Verdana font |
| Number liter values | Thousands separator, e.g. `108,643` |

### Column layout (per warehouse block pair)

- A spacer (1.75), B label (37.63), C/D default, E value (37.88), F/G default, H spacer (1.25), I label (37.63), J/K default, L value (37.88), M/N default.
- Merges (via HTML `colspan` — PhpSpreadsheet Html reader supports it): top-summary label spans B:D with value E:G; section titles span B:G (left block) and I:N (right block); detail-table TOTAL label spans B:E with value F:G (mirrors template merges).

### Implementation notes

- `WetStockReportExport`: remove `ShouldAutoSize` (it overrides fixed widths); remove or keep `styles()` only if harmless — fixed column widths set in an `AfterSheet` event (register via `WithEvents`): B 37.63, E 37.88, I 37.63, L 37.88, A 1.75, H 1.25.
- Rendering stays in `excel.blade.php` using inline styles (`background-color`, `font-weight`, `color`, `border`, `align`) — already supported by the Html reader.
- `colspan` on empty cells is not needed; empty `<td>` cells are skipped by the reader, and row-internal alignment is preserved because non-empty cells are placed at fixed columns (existing pattern already verified).
- HOLD FOR CLEARING value cell shows the numeric hold total; the template's note text "FROM SALES DOCS SUM - ACCOUNTING CLEARANCE" is retained as a sub-line under the HOLD FOR CLEARING label row (small/plain text).

## Verification (local, before any push)

Expected numbers with current local data:

- San Simon: pending-clearance orders = 50,000 + 10,000 + 10,000 = **70,000**; hold = 50,000 (deliveries) + 70,000 = **120,000**
- Valenzuela: pending-clearance orders = 123 + 12,000 = **12,123**; hold = 50,000 (deliveries) + 12,123 = **62,123**
- Available for sale: San Simon 147,286 + 120,000 = **267,286**; Valenzuela 50,000 + 62,123 = **112,123**
- On-hand unchanged: **108,643 / 50,000**

Checks:
1. Live reports page renders the new row with correct values (200).
2. Live + snapshot Excel exports 200; exported values match the expectations above.
3. Excel file carries the template design: orange headers (`FF9900`), peach header rows (`FCE5CD`), gray totals (`CCCCCC`), fixed column widths, merged section titles.
4. Snapshot page renders (missing-key tolerance) for the existing snapshot; a fresh snapshot captures the new key.
5. `php -l` on changed PHP files.

## Out of Scope

- Changing `clearing_status` workflow or order forms (dashboard select already exists).
- Applying the template design to the HTML portal pages (web stays Bootstrap-styled).
- Seeding tanker tanks (user adds via UI).
- Any git push — user tests locally first.
