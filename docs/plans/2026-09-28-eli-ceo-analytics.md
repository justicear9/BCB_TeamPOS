# Eli CEO Analytics Implementation Plan

> **For agentic workers:** Implement task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Eli answer CEO-style business questions (averages, branch comparisons, advice) from live TeamPOS data, starting with “average loaves sold at a branch.”

**Architecture:** Keep data access mediated (permissioned, `business_id`-scoped, TeamPOS sell-line UoM definitions). Add first-class **derived metrics** tools that return averages/comparisons as JSON Eli can quote. Loosen prompt + reply post-processing that currently forbid quoting amounts when grids attach. Do **not** give the model raw SQL.

**Tech Stack:** Laravel module `Modules/AIBusinessManager`, existing `BusinessDataToolService` tool loop, OpenAI function calling, PHPUnit feature/unit tests under `tests/`.

## Global Constraints

- Stay read-only at the application layer; reuse existing location permission helpers (`permitted_locations`, `parseDateRange*`, `sellLinesInRangeQuery`, `qtySellingUomSql`).
- Never invent numbers — every figure must come from a tool result.
- Multi-tenant: always filter `business_id`; never cross businesses.
- Prefer invoice selling UoM (`quantity_selling_uom`) consistent with other Eli sales tools; surface unit labels and refuse silent mixing of different units.
- No new vendor packages unless unavoidable.
- Repo-relative paths only in this plan.

---

## Problem Frame

Eli is positioned as Embedded Local Intelligence / business manager, but today:

1. Tools mostly return **sums**, not **averages** or period comparisons.
2. Closest bakery helper (`bake_plan`) averages same-weekday demand for production — wrong shape for “avg sold at Branch X.”
3. System prompt tells Eli **not to write amounts** for location/product grids.
4. `AiBusinessAssistantService::attachOfficialGrids()` **strips currency and large numbers** from the model reply whenever a `verbatim_block` grid is attached — so even if the model computed an average, the number can be deleted before the user sees it.

That combination makes CEO Q&A fail even though the DB is already queried live.

## Scope

**In (v1):**

- Product (or small product set / name match) × location × date range **quantity averages**.
- Clear average definitions (calendar day vs selling day).
- Branch comparison for that product (avg/total across permitted locations).
- Prior-period comparison (same length window immediately before).
- Prompt + post-process changes so Eli may **quote tool numbers** and give short operational advice.
- Tests for the new tool and for “amounts not stripped” when using the metrics path.

**Out (later):**

- Raw SQL / schema browsing for the model.
- Full GL / Accounting deep drill beyond existing Accounting snapshot tools.
- Autonomous write actions (PO create, price change, stock adjust).
- Perfect holiday/campus calendar intelligence beyond existing holiday blocks.

## Requirements Traceability

| Need | Approach |
|------|----------|
| CEO asks avg loaves @ branch | New tool `product_location_metrics` |
| Accurate definition of “average” | Explicit `avg_basis`: `calendar_day` (default) and `selling_day` |
| Ambiguous “loaves” | Resolve via `name_query` / `product_id` / optional `category_query`; return `ambiguous` matches like `product_sales_trend` |
| Advice / suggest | Prompt: after metrics, 2–4 bullets of operational advice grounded in returned deltas |
| Don’t invent | Still require tool call; advice must reference tool fields |
| Don’t break grid UX | Keep `verbatim_block` grids for matrix tools; **do not** strip prose amounts when reply used metrics tools (or stop stripping amounts entirely and only strip model-drawn tables/charts) |

## Key Decisions

1. **New tool over overloading `sales_report`** — `sales_report` is a pivot/grid path with verbatim attach + amount stripping. A scalar metrics tool is clearer for Q&A and advice.
2. **Default average basis = calendar days in range** (inclusive), with `selling_day` as opt-in (days with ≥1 finalized sell of that product at that location). Document both in tool `note`.
3. **Allow quoting numbers** for metrics tools; keep verbatim grids for large matrices, but fix stripping so advice text with figures survives.
4. **Category/name “loaves”** — if multiple products match and units match, return per-product rows plus optional `combined` when same unit; if units differ, refuse combine and list separately.
5. **Advice stays heuristic in the prompt** (no separate recommendation engine in v1).

## Files to Touch

| File | Responsibility |
|------|----------------|
| `Modules/AIBusinessManager/Services/Concerns/ProductLocationMetricsTool.php` | New trait: resolve product(s)/location, compute totals + averages + comparisons |
| `Modules/AIBusinessManager/Services/BusinessDataToolService.php` | Register tool definition + `execute()` dispatch; `use` new trait |
| `Modules/AIBusinessManager/Services/AiBusinessAssistantService.php` | Prompt: CEO metrics behavior; soften/fix `attachOfficialGrids` |
| `Modules/AIBusinessManager/Config/config.php` | Optional caps (row limits, max products combined) |
| `tests/Unit/AIBusinessManager/ProductLocationMetricsToolTest.php` | Unit/feature tests for averages and ambiguity |
| `tests/Unit/AIBusinessManager/AttachOfficialGridsTest.php` | Ensure metrics answers keep numeric prose |

Follow existing patterns in `SalesReportTool.php`, `LocationSalesMatrixTools.php`, and `productSalesTrend()` for date/location/product resolution.

---

## Tool Contract (directional)

`product_location_metrics` args:

- `start_date`, `end_date` (ISO; omit → YTD like other matrix tools via `parseDateRangeOrYearToDate`)
- `location_id` **or** `location_name` (substring; resolve among permitted locations; ambiguous → list)
- `product_id` **and/or** `name_query` **and/or** `category_query`
- `avg_basis`: `calendar_day` | `selling_day` (default `calendar_day`)
- `compare_prior_period`: bool (default true)
- `include_all_locations`: bool — when true, return one row per permitted location for the resolved product(s)

Success payload (sketch):

```text
ok, currency_symbol, unit, avg_basis,
product_id, product_name, location_id, location_name,
start, end, days_in_range, selling_days,
total_quantity, total_revenue, avg_quantity, avg_revenue,
prior: { start, end, total_quantity, avg_quantity, qty_delta_pct },
siblings: [ { location_name, total_quantity, avg_quantity } ],  // other branches
note, caveat
```

Ambiguous product/location → `ok: true`, `ambiguous: true`, `matches: [...]`, empty metrics (same pattern as `product_sales_trend`).

---

## Implementation Units

### Task 1 — Product × location metrics tool

**Files:**
- Create: `Modules/AIBusinessManager/Services/Concerns/ProductLocationMetricsTool.php`
- Modify: `Modules/AIBusinessManager/Services/BusinessDataToolService.php`
- Modify: `Modules/AIBusinessManager/Config/config.php` (limits only if needed)
- Test: `tests/Unit/AIBusinessManager/ProductLocationMetricsToolTest.php`

**Requirements:**
- Scoped sell-line query; main lines only (`parent_sell_line_id` null) like other sales tools.
- Resolve location by id or name among permitted locations.
- Resolve product by id / name / category; handle ambiguous.
- Compute totals + avg for chosen basis; optional prior window of equal day count ending day before `start`.
- Optional all-locations sibling rows for the same product.
- Mixed units across matched products: do not combine; return error or per-product only.

**Test scenarios:**
1. Known product + location over N calendar days → `avg_quantity = total / N`.
2. `selling_day` basis → divisor is count of days with sales, not calendar span.
3. Ambiguous `name_query` → matches list, no invented average.
4. Location outside permitted set → ignored / forbidden, no leak.
5. Prior period enabled → `qty_delta_pct` correct on fixture data.
6. Two products different units matching “loaf” → no combined total.

**Steps:**
- [ ] Add failing tests for avg calendar/selling day and ambiguity.
- [ ] Implement trait + wire OpenAI tool definition + `execute()` case.
- [ ] Make tests pass.
- [ ] Commit.

### Task 2 — Stop killing CEO numbers in the reply path

**Files:**
- Modify: `Modules/AIBusinessManager/Services/AiBusinessAssistantService.php`
- Test: `tests/Unit/AIBusinessManager/AttachOfficialGridsTest.php`

**Requirements:**
- Today `attachOfficialGrids()` strips `¢/₵/GH₵/GHS` amounts and `\b\d{1,3}(?:,\d{3})+…\b` whenever any grid attached.
- Change behavior so:
  - Still strip **model-authored** `aibm-chart` fences and markdown tables when attaching official grids (anti-drift).
  - **Do not strip** currency/number prose from the assistant text (or only strip when a dedicated flag says the tool owns all figures — prefer keep prose).
- When `product_location_metrics` is used, Eli should be able to say “Airport averages 42 loaves/day” and the user must see `42`.

**Test scenarios:**
1. Text containing `42` and `GH₵1,234.50` plus a grid → numbers remain after attach.
2. Model-drawn markdown table still removed when grids present.
3. Model `aibm-chart` fence still removed when grids present.

**Steps:**
- [ ] Add failing tests around current strip behavior.
- [ ] Narrow `attachOfficialGrids` to tables/charts only (or metrics-aware path).
- [ ] Pass tests; commit.

### Task 3 — Prompt: manager behavior for metrics

**Files:**
- Modify: `Modules/AIBusinessManager/Services/AiBusinessAssistantService.php` (system prompt in `chatWithTools`)
- Optionally update tool descriptions in `BusinessDataToolService` that currently say “Do not write any amount”

**Requirements:**
- Instruct Eli: for quantity/average/compare questions, call `product_location_metrics` (not only `sales_by_product_location`).
- Quote `avg_quantity`, basis, unit, location, and date range from the tool.
- If `siblings` present, rank branches and call out lagging/leading.
- If prior delta present, state direction briefly.
- End with 2–4 concrete suggestions (bake adjust, transfer, promo, investigate waste) **tied to those numbers** — label as guidance, not orders.
- Keep existing matrix rules for huge grids, but clarify metrics answers **may and should** include amounts.

**Test scenarios:**
- Manual / smoke: “Average loaves sold at Airport last 30 days” → tool call + numeric answer + short advice (document as manual smoke if no OpenAI in CI).

**Steps:**
- [ ] Update system prompt sections for sales + behavior.
- [ ] Soften conflicting tool description strings on matrix tools only where needed (grids can stay “app attaches table”; metrics is separate).
- [ ] Commit.

### Task 4 — CEO follow-ons (same PR if small, else follow-up)

**Files:** same Services concerns as needed

**Minimum follow-ons (if time):**
- `compare_locations: true` already covered via `include_all_locations`.
- Support `group_products: true` when category “Bread/Loaves” is clear and units match.
- Optional: expose `avg_quantity_by_weekday` array (7 slots) for “what’s a normal Tuesday at Airport?”

Defer if it bloats v1; ship Tasks 1–3 first.

**Steps:**
- [ ] Only if Tasks 1–3 green and scope allows; else open as follow-up notes in PR.

### Task 5 — Verify and ship

**Steps:**
- [ ] Run PHPUnit for new tests.
- [ ] Smoke the assistant path if env has OpenAI key; otherwise tool unit tests are the gate.
- [ ] Push branch + PR summarizing CEO analytics unlock and stripping fix.

---

## Risks

| Risk | Mitigation |
|------|------------|
| Wrong average definition angers operators | Return both basis fields in `note`; default calendar; explain in reply |
| “Loaves” matches many SKUs | Ambiguous flow; category_query; no silent combine on mixed units |
| Stripping regression on matrix anti-drift | Keep table/chart strip tests |
| Model still avoids tools | Prompt examples + tool description priority for average language |
| Performance on wide date × all locations | Caps, require product resolution before all-locations expand |

## Success Criteria

- CEO question: “What’s the average loaves sold at [branch]?” → numeric avg with unit, basis, range, from DB.
- Follow-up: “How does that compare to other branches?” → sibling averages, short ranking + advice.
- Reply text retains the numbers (no silent strip).
- Still no raw SQL; still tenant- and location-scoped.

## Execution Direction

Test-first for the metrics math and reply post-processing. Prompt changes validated by review + optional live smoke.
