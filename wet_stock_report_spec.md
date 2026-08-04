# Wet Stock Report — Feature Spec

## 1. Purpose
The Wet Stock Report tracks liquid fuel inventory across all branches/locations. It answers three questions in real time:
1. How much stock do we physically have on hand (depots + tankers)?
2. How much is committed/incoming (from suppliers) or committed/outgoing (to clients)?
3. How much is actually free to sell right now?

Each branch/location (e.g., Valenzuela, San Simon) gets its own identical report block. New branches should be addable without changing the underlying structure.

---

## 2. Data Structure

### 2.1 Warehouse (Depot) Table — per location
| Field | Type | Notes |
|---|---|---|
| Depot Name | Text | e.g., "Depot 1" |
| Liters | Number | Current volume stored |
| Remarks | Text | Optional notes |

**Total** = SUM of all depot rows for that location.

### 2.2 Tanker Table — per location
| Field | Type | Notes |
|---|---|---|
| Tanker ID / Plate # | Text | e.g., "T1 - NBF 9098" |
| Liters | Number | Current volume loaded |
| Remarks | Text | Optional notes |
| **Contaminated (new)** | Boolean toggle | See Section 5 |

**Total** = SUM of all tanker rows for that location.

### 2.3 Unlifted Stock Pick Up Table — per location
| Field | Type | Notes |
|---|---|---|
| PO# | Text | Purchase order reference |
| Location | Text | |
| Supplier | Text | |
| Liters | Number | Purchased, not yet picked up from supplier |

**Total** = SUM of Liters column.

### 2.4 Pending Stock Delivery Table — per location
| Field | Type | Notes |
|---|---|---|
| PO# | Text | |
| Location | Text | |
| Supplier | Text | |
| Liters | Number | Purchased, in transit, not yet delivered to depot |

**Total** = SUM of Liters column.

### 2.5 Big Tanker Undelivered Table — per location
| Field | Type | Notes |
|---|---|---|
| SO# | Text | Sales order reference |
| Client | Text | |
| Liters | Number | Committed to client, undelivered, big tanker |

**Total** = SUM of Liters column.

### 2.6 Small Tanker Undelivered Table — per location
Same fields as 2.5, filtered to small tanker deliveries.

**Total** = SUM of Liters column.

### 2.7 Client Pick Up Table — per location
| Field | Type | Notes |
|---|---|---|
| SO# | Text | |
| Client | Text | |
| Liters | Number | Client will pick up themselves, not yet lifted |

**Total** = SUM of Liters column.

---

## 3. Top Summary Table (per location)

| Row | Calculation |
|---|---|
| Depot | = Total of 2.1 |
| Tankers | = Total of 2.2 |
| Contaminated | = Auto-calculated (see Section 5) |
| Unlifted Stock Pick Up | = Total of 2.3 |
| Pending Stock Delivery | = Total of 2.4 |
| **TOTAL** | = Depot + Tankers + Contaminated + Unlifted Stock Pick Up + Pending Stock Delivery |
| Client Unlifted Pick Up | = Total of 2.7 |
| Client Pending Delivery | = Total of 2.5 + Total of 2.6 |
| **TOTAL (Hold for Clearing)** | = Client Unlifted Pick Up + Client Pending Delivery |
| **TOTAL AVAILABLE FOR SALE** | = TOTAL + TOTAL (Hold for Clearing) |
| **TOTAL AVAILABLE ON HAND FOR SELLING** | = Depot + Tankers − Client Unlifted Pick Up |

> **Decision (2026-08-03):** Match the original `WET STOCK REPORT.xlsx` template exactly. Contaminated liters remain *inside* the Depot/Tanker totals and are shown as a separate awareness line in the summary (the template's TOTAL therefore includes contaminated volume on top of the physical totals). The On-Hand line does NOT subtract contaminated — this deliberately overrides the earlier §5.3 language (kept below for history).

> Note: "Hold for Clearing" pulls from the Sales/DR module's clearance status — i.e., Client Unlifted Pick Up and Client Pending Delivery should sync with actual SO/DR records, not be manually re-typed.

> If "TOTAL AVAILABLE ON HAND FOR SELLING" goes negative, this indicates a stock deficit — the system should visually flag this (e.g., red text) as an early warning that commitments exceed physical stock.

---

## 4. Report Rules
- Every location gets its own full copy of Sections 2 and 3.
- All totals are system-calculated, not manually entered — the underlying tables are the single source of truth.
- New locations can be added without restructuring the report; the report should support N locations, not just two.

---

## 5. New Feature: Contaminated Stock Indicator

**Business rule (per supervisor):** Whether a tank/tanker is contaminated will still be **entered manually** by staff — this is not something the system can detect automatically. However, once flagged, the system should treat it as a proper status rather than a one-off manual number, so it's consistent and visible everywhere it matters.

### 5.1 Data model change
Add to each **Tank** and **Tanker** record:
| Field | Type | Notes |
|---|---|---|
| `is_contaminated` | Boolean toggle | Manually set by staff |
| `contaminated_liters` | Number | Defaults to the tank/tanker's current liters when flagged; editable if only part of the volume is contaminated |
| `contaminated_date` | Date/time | Auto-stamped when the toggle is switched on |
| `contaminated_by` | User reference | Auto-captured from the logged-in user who flagged it |
| `remarks` | Text | Already exists in the current sheet (e.g., "contaminated") — becomes optional free-text once the toggle exists, since the status itself no longer depends on this text field |

### 5.2 UI indicator
- In the Warehouse Breakdown and Tanker Breakdown tables, a tank/tanker marked contaminated should show a clear visual flag on that row — e.g., a red badge or icon labeled "Contaminated" — so it's obvious at a glance without needing to read the Remarks column.
- Toggling the flag on should prompt for (or auto-fill) the contaminated liters amount.

### 5.3 Reports impact
- The **Contaminated** row in the Top Summary Table (Section 3) should **no longer be a manually typed number**. Instead:
  - `Contaminated Total (per location)` = SUM of `contaminated_liters` across all tanks and tankers at that location where `is_contaminated = true`.
- ~~This also means a contaminated tank/tanker's volume should be excluded from the sellable Depot/Tanker totals used in **TOTAL AVAILABLE ON HAND FOR SELLING**~~ — **Superseded 2026-08-03:** the On-Hand line follows the original spreadsheet template exactly (Depot + Tankers − Client Unlifted Pick Up) and does NOT subtract contaminated. See the note under Section 3.
- Historical reports (past months) should preserve the contaminated status/amount as it was at that point in time, not retroactively change if the flag is later toggled off (e.g., after the tank is cleaned/cleared).

### 5.4 Audit trail
- Every contamination flag change (on or off) should log: who changed it, when, previous status, new status, and liters affected — visible in the Audit Log module already in the system.
