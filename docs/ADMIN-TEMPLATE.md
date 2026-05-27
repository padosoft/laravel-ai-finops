# Admin Template — React + Vite + Tailwind (laravel-ai-finops-admin)

Implementation plan + architecture for the **professional admin template** that Lorenzo builds in
parallel while the core package is implemented. Target repo: `../laravel-ai-finops-admin`.
The template is the visual/interaction baseline; screens get wired to real `/api/ai-finops/*` endpoints
as the core delivers them (gap-backfill rule: never leave a screen mocked at release).

---

## 1. Stack & versions (pin latest stable at scaffold time)

- **React 19.x**, **Vite 8.x**, **Tailwind CSS v4.x** (CSS-first config via `@theme` in CSS, no JS config).
- **TypeScript 5.x** (typed API client + components).
- **React Router v7** (SPA routing under `/admin/ai-finops`).
- **TanStack Query v5** (server state: fetch/cache/invalidate for all API calls).
- **TanStack Table v8** (DataTable), **Recharts** (charts) or **visx** (FlameGraph), **Zod** (DTO validation).
- **Vitest 4** + **Testing Library** (component units), **Playwright 1.5x** (e2e, desktop + tablet).
- Laravel host: Laravel 13, `laravel-vite-plugin`, Blade shell. PHP `^8.3` (cover 8.4/8.5).
- Package manager: npm (match Padosoft repos). Lint/format: ESLint + Prettier (or Biome if preferred).

---

## 2. Laravel wiring

- Blade shell `resources/views/ai-finops/admin.blade.php` mounts the SPA and injects a bootstrap object:
  ```blade
  <div id="aifinops-admin"></div>
  @vite(['resources/css/admin-ai-finops.css','resources/js/admin-ai-finops/main.tsx'])
  <script>window.AIFINOPS_ADMIN = {
    apiBase: "{{ url('/api/ai-finops') }}",
    adminBase: "{{ url('/admin/ai-finops') }}",
    csrfToken: "{{ csrf_token() }}",
    appName: "AI FinOps", logoutUrl: "{{ url('/admin/ai-finops/logout') }}",
    user: @json($adminUser ?? null), locale: "{{ app()->getLocale() }}"
  };</script>
  ```
- Admin shell route at `/admin/ai-finops` (configurable middleware, default `web`+`auth`).
- All API calls use session + CSRF header `X-CSRF-TOKEN` (no browser-held tokens).
- Vite builds the SPA into `public/build/`; injected via `@vite`.

---

## 3. Folder structure (`resources/js/admin-ai-finops/`)

```
main.tsx                      # entry: mount <App/> on #aifinops-admin, providers
App.tsx                       # Router + layout shell (sidebar, topbar, content)
config.ts                     # reads window.AIFINOPS_ADMIN
lib/
  apiClient.ts                # fetch wrapper (CSRF, JSON, error normalization)
  queryClient.ts              # TanStack Query client
  format.ts                   # currency/number/date/token formatters (multi-currency)
  hooks/                      # useKpis, useUsage, useBudgets, usePolicies, ... (one per domain)
types/                        # Zod schemas + inferred TS types per domain DTO
components/                   # reusable primitives (see §5)
layout/                       # AppShell, Sidebar, Topbar, PageHeader, Breadcrumbs
features/                     # one folder per screen (see §6), each: <Screen>.tsx + parts + tests
  dashboard/  usage/  trace/  budgets/  policies/  approvals/  pricing/
  chargeback/ alerts/ forecast/ routing/ whatif/ price-watch/ credits/
  copilot/ footprint/ settings/ diagnostics/ auth/
i18n/                         # en + it message catalogs
styles/                       # tailwind layers, design tokens
```

---

## 4. Design system (Tailwind v4 `@theme`)

Dense, operational admin (no marketing hero). CSS-first tokens in `resources/css/admin-ai-finops.css`:

- Tokens: `--color-bg`, `--color-surface`, `--color-border`, `--color-text`, `--color-muted`,
  status accents (`--color-ok/warn/danger/info`), brand accent; **radius ≤ 8px**; fonts: Inter (UI),
  JetBrains Mono (numbers/JSON). Light + dark via `prefers-color-scheme` and a manual toggle.
- Rules: compact tables/panels/drawers/modals/toasts; cards only for repeated items/framed tools; no
  nested cards; every icon-only button has an aria-label + tooltip; no text overflow at desktop/narrow
  desktop/tablet/125%/150% zoom; numbers right-aligned & monospace; cost always shows currency.

---

## 5. Reusable components (`components/`)

`AppShell`, `Sidebar`, `Topbar`, `PageHeader`, `KpiCard`, `SpendChart`, `BurndownChart`,
`ForecastChart`, `FlameGraph`, `BudgetTree`, `PolicyEditor` (DSL textarea + validate/simulate),
`DataTable` (TanStack: sort/filter/paginate/column-visibility/CSV), `FilterBar`, `Drawer`,
`ConfirmModal`, `JsonViewer`, `Timeline`, `StatusBadge`, `CostPill`, `CurrencyTag`, `ChannelBadge`,
`AnomalyBadge`, `CarbonBadge`, `CreditMeter`, `CopilotChat`, `Toast`, `LoadingState`, `EmptyState`,
`Pagination`, `DateRangePicker`, `TenantSwitcher`, `Money` (formatter component).

Each component: typed props, Vitest unit test, story-like usage in the feature that consumes it.

---

## 6. Screens → endpoints (data contract the template targets)

> Endpoints are under `window.AIFINOPS_ADMIN.apiBase` (`/api/ai-finops`). Full list also in the plan.

1. **Auth/Login** `auth/` — POST login, logout (session/CSRF).
2. **Dashboard** `dashboard/` — `GET /dashboard/{kpis,spend-trend,top-models,top-tenants,budget-burn,anomalies}`.
3. **Usage Explorer** `usage/` — `GET /usage`, `POST /usage/export`, `GET /usage/exports/{id}`.
4. **Call/Trace Detail** `trace/` — `GET /usage/{id}`, `GET /usage/{traceId}/trace` (FlameGraph).
5. **Budgets** `budgets/` — `GET /budgets/tree|/budgets`, CRUD `/budgets/{id}`, `/budgets/{id}/{burndown,reset,history}`.
6. **Policies** `policies/` — CRUD `/policies`, `POST /policies/{id}/simulate`, `POST /policies/validate`.
7. **Approvals** `approvals/` — `GET /approvals`, `/approvals/{id}`, approve/reject.
8. **Pricing** `pricing/` — `GET /pricing/models`, `POST /pricing/sync`, overrides CRUD, discounts.
9. **Chargeback** `chargeback/` — cost-centers CRUD, `GET /chargeback/report`, schedules.
10. **Alerts** `alerts/` — channels CRUD + test, rules CRUD, `GET /alerts/log`.
11. **Forecast & Anomalies** `forecast/` — `GET /forecast`, `/forecast/{budgetId}`, anomalies + ack.
12. **Cost-aware Routing** `routing/` — rules CRUD, `GET /routing/quality-scores`, `POST /routing/simulate`.
13. **What-if Simulator** `whatif/` — `POST /whatif/simulate`, scenarios CRUD.
14. **Price-change Watcher** `price-watch/` — changes, subscriptions.
15. **Credit Pools** `credits/` — pools CRUD, topup, `GET /credits/pools/{id}/ledger`.
16. **FinOps Copilot** `copilot/` — `POST /copilot/query`, `GET /copilot/history`.
17. **CO₂/ESG** `footprint/` — `GET /footprint/{summary,trend}`.
18. **Settings** `settings/` — `GET/PUT /settings`, kill-switch scoped.
19. **Diagnostics** `diagnostics/` — `GET /health`, `POST /diagnostics/estimate`.

While the core is incomplete, screens may read from a typed **MSW (Mock Service Worker)** layer keyed to
the same Zod DTOs — but every screen MUST be switched to the real endpoint before its PR merges.

---

## 7. Data layer

- `apiClient.ts`: `GET/POST/PUT/DELETE` helpers; inject `X-CSRF-TOKEN`; parse JSON; normalize errors to
  `{status, message, fields?}`; never surface raw provider payloads.
- One typed hook per resource via TanStack Query (`useUsageList`, `useBudgetTree`, `useCreatePolicy`…)
  with query keys per domain + `invalidateQueries` on mutations.
- All DTOs validated by Zod (`types/`), shared between mock and real layers.

---

## 8. Testing

- **Vitest**: every reusable component + every hook (mocked apiClient). `tests/JavaScript/**/*.test.tsx`.
- **Playwright**: one spec per screen covering ALL interactions (load, filter, paginate, open drawer,
  create/edit/delete, validate/simulate, approve/reject, export, error states). Projects: desktop
  (1440×900) + tablet (1024×768). `playwright.config.ts` spawns `php artisan serve` against an SQLite
  test DB and seeds demo data (incl. a large dataset ~500k ledger rows for dashboard stress).
- Gates: `npm run build`, `npm run test`, `npm run e2e` (+ core `composer validate`/phpunit).

---

## 9. Build order for the template (parallelizable with core)

1. **T1 — Scaffold**: Vite+React+TS+Tailwind, ESLint/Prettier, `apiClient`, `queryClient`, config,
   i18n, AppShell + Sidebar + Topbar + routing skeleton, design tokens. Vitest+Playwright harness.
2. **T2 — Primitives**: DataTable, FilterBar, Drawer, ConfirmModal, Toast, StatusBadge, CostPill,
   Money, JsonViewer, charts wrappers, EmptyState/LoadingState. Vitest each.
3. **T3 — Dashboard + Usage + Trace** (highest value; FlameGraph).
4. **T4 — Budgets + Policies + Approvals**.
5. **T5 — Pricing + Chargeback + Alerts**.
6. **T6 — Forecast + Routing + What-if + Price-watch + Credits**.
7. **T7 — Copilot + Footprint + Settings + Diagnostics + Auth**.
8. **T8 — Polish**: dark mode, a11y pass, responsive/zoom audit, large-dataset stress, README screenshots.

Each step follows the standard branch/PR/Copilot loop (see `AGENTS.md`).

---

## 10. Notes / open choices for Lorenzo
- Charts lib: Recharts (fast) vs visx (flexible FlameGraph). FlameGraph likely custom on visx/SVG.
- Mock layer: MSW recommended so the template is fully demoable before core endpoints land.
- Keep the component API stable; the core team targets these DTOs (Zod schemas are the contract).
