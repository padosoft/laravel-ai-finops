# Piano implementazione — `laravel-ai-finops` (core) + `laravel-ai-finops-admin`

## Context
Padosoft ha tutti i mattoncini di un sistema agentico tranne la **governance della spesa AI
cross-provider** (budget, soglie, alert, block, FinOps). Due pacchetti di terzi lo confermano:
`subhashladumor1/laravel-ai-guard` (enforcement, pricing statico) e `jonaaix/laravel-ai-costs`
(solo tracking, pricing tipo LiteLLM). Nessuno fa entrambe le cose né governance enterprise.
Costruiamo `padosoft/laravel-ai-finops` (+ `-admin`) che li supera e diventa riferimento di settore.

**Backbone confermato (composer.json):** il pacchetto ufficiale **`laravel/ai`** (v0.6/0.7) è la spina
dorsale (`AskMyDocs`, `laravel-ai-regolo` = provider di laravel/ai, `price-intelligence`; `eval-harness`
opt-in). Quindi **un solo hook** (middleware/eventi su `laravel/ai`) intercetta ogni chiamata di ogni
provider, senza invadere i singoli pacchetti.
**Correzioni recepite:** `laravel-ai-chat` è solo una DEMO di laravel/ai + Vercel AI SDK (non una
capability da integrare); URL QA corretto `padosoft/agentic-qa-kit` (clonato; uso laravel/ai da
verificare in Fase 0). Repo `laravel-ai-finops` e `-admin` già clonati in sottocartelle di `\Ai`
(scheletri: solo LICENSE + README stub).

**Stack:** Laravel 13.x (ultima), PHP 8.3+ (8.4+ se richiesto da dipendenze). Admin: **React + Vite +
Tailwind ultime versioni stabili** (il reference usa CSS custom; qui si moderna a Tailwind v4).
Multi-tenant, config-toggle ovunque, EU-compliant.

---

## REGOLE DI LAVORO (vincolanti — replicate in AGENTS.md/RULES.md/skill)
- **Branch per macro-task.** Ogni sottotask → PR verso il branch del macro-task. Macro-task concluso →
  PR macro→`main`, iter di validazione, merge.
- **Definition of Done (ogni task/sottotask):** obiettivo preciso + dettagli implementativi +
  **guardrail con test**: PHPUnit (PHP), Vitest (JS), e **se c'è UI/UX → scenari Playwright di TUTTE le
  interazioni**. Se è solo codice, niente Playwright.
- **Loop di chiusura step:**
  1. Tutti i test locali verdi (PHPUnit + Vitest + `npm run build` + Playwright).
  2. Review locale Copilot: `copilot --autopilot --yolo -p "/review <diff branch vs origin/main> ..."`
     passando **tutto il diff del branch** (se troppo grande → salva su file temp e passa il file).
  3. Solo con test verdi **e zero commenti** Copilot locale → `push`.
  4. Apri PR verso il branch in lavorazione; **reviewer = Copilot**; assicurati che la review parta.
  5. Attendi **CI tutti verdi + commenti Copilot**.
  6. Se tutto ok → merge. Altrimenti fixa test rotti + commenti Copilot e **ripeti il loop**.
  7. Solo a tutto-ok il task è chiuso → next.
- **`docs/PROGRESS.md`**: log datato (YYYY-MM-DD) di cosa si sta facendo e dove, per riprendere dopo
  interruzioni. **`docs/LESSON.md`**: scoperte/errori/fix/lezioni (anche dai commenti Copilot). Passare
  LESSON.md nel contesto a **ogni subagent** e a se stessi a inizio sessione.
- **README WOW finale** (modello `lopadova/AskMyDocs`): badge, banner (`resources/banner.png` se
  presente), TOC, "cosa ha di innovativo", screenshot (`resources/` se presenti), quick start step-by-step
  a prova di junior.
- **Chiusura pacchetto:** consolidare LESSON.md nelle rules/skills/AGENTS.md; poi tag `vX.X.X` + release GitHub.

---

## Architettura core

Hook unico su `laravel/ai` (`FinOpsMiddleware`/listener):
- **Pre-flight**: stima token+costo → `PolicyEngine` → `allow | block | throttle | downgrade | queue |
  require-approval`.
- **Post-flight**: usage reale → `CostCalculator` (pricing DB) → `Ledger` (append-only) → evento →
  aggiorna budget/forecast/alert.

Value object **`AiCallEnvelope`** (provider, model, token in/out/cached, cost, currency, tenant, user,
cost-center, agent-step, purpose-tag, **trace-id**) = anche il **collante** cross-pacchetto.

Servizi: `PricingRegistry` (sync DB tipo LiteLLM + override locali + sconti) · `CostCalculator`
(multimodale, multi-currency+FX) · `BudgetResolver` · `PolicyEngine` (DSL) · `Ledger` · `Enforcer` ·
`Forecaster`/`AnomalyDetector` · `AlertDispatcher` (mail/Slack/Teams/webhook/SMS).

---

## SUPERFICIE API COMPLETA (core, consumata anche dall'admin)
Prefisso programmatico `/api/ai-finops/*` (session+CSRF per l'admin, come il reference). Tutte filtrabili
per tenant/periodo/cost-center dove sensato.

**Dashboard/KPI**
- `GET /dashboard/kpis` · `GET /dashboard/spend-trend` · `GET /dashboard/top-models` ·
  `GET /dashboard/top-tenants` · `GET /dashboard/budget-burn` · `GET /dashboard/anomalies`

**Usage / Ledger**
- `GET /usage` (lista paginata+filtri) · `GET /usage/{id}` (dettaglio chiamata) ·
  `GET /usage/{traceId}/trace` (flame-graph costo per step) · `POST /usage/export` (CSV/PDF) ·
  `GET /usage/exports/{id}` (stato/download)

**Pricing**
- `GET /pricing/models` · `GET /pricing/models/{id}` · `POST /pricing/sync` · `GET /pricing/sync/status` ·
  `GET /pricing/overrides` · `POST /pricing/overrides` · `PUT/DELETE /pricing/overrides/{id}` ·
  `GET /pricing/discounts` · `POST /pricing/discounts`

**Budgets (gerarchia N-livelli)**
- `GET /budgets/tree` · `GET /budgets` · `POST /budgets` · `GET/PUT/DELETE /budgets/{id}` ·
  `GET /budgets/{id}/burndown` · `POST /budgets/{id}/reset` · `GET /budgets/{id}/history`

**Policies (DSL)**
- `GET /policies` · `POST /policies` · `GET/PUT/DELETE /policies/{id}` · `POST /policies/{id}/simulate` ·
  `POST /policies/validate`

**Approvals**
- `GET /approvals` (queue) · `GET /approvals/{id}` · `POST /approvals/{id}/approve` ·
  `POST /approvals/{id}/reject`

**Chargeback/Showback**
- `GET /cost-centers` · `POST /cost-centers` · `GET/PUT/DELETE /cost-centers/{id}` ·
  `GET /chargeback/report` · `POST /chargeback/schedule` · `GET /chargeback/schedules`

**Alerts**
- `GET /alerts/channels` · `POST /alerts/channels` · `PUT/DELETE /alerts/channels/{id}` ·
  `POST /alerts/channels/{id}/test` · `GET /alerts/rules` · `POST /alerts/rules` ·
  `PUT/DELETE /alerts/rules/{id}` · `GET /alerts/log`

**Forecast & Anomaly**
- `GET /forecast` · `GET /forecast/{budgetId}` · `GET /anomalies` · `POST /anomalies/{id}/ack`

**Cost-aware Routing (eval-harness)**
- `GET /routing/rules` · `POST /routing/rules` · `PUT/DELETE /routing/rules/{id}` ·
  `GET /routing/quality-scores` · `POST /routing/simulate`

**What-if Simulator**
- `POST /whatif/simulate` · `GET /whatif/scenarios` · `POST /whatif/scenarios` · `GET /whatif/scenarios/{id}`

**Provider price-change watcher**
- `GET /price-watch/changes` · `GET /price-watch/subscriptions` · `POST /price-watch/subscriptions`

**Credit pools**
- `GET /credits/pools` · `POST /credits/pools` · `PUT/DELETE /credits/pools/{id}` ·
  `POST /credits/pools/{id}/topup` · `GET /credits/pools/{id}/ledger`

**FinOps Copilot**
- `POST /copilot/query` (NL→insight, via ai-chat/AskMyDocs) · `GET /copilot/history`

**CO₂/ESG**
- `GET /footprint/summary` · `GET /footprint/trend`

**Settings/Admin**
- `GET/PUT /settings` (currency/FX, retention, tenant) · `GET/POST /settings/kill-switch`
  (scoped: global/provider/tenant/feature) · `GET /health` · `POST /diagnostics/estimate`
  (stima costo di una chiamata di prova)

---

## SCREEN & COMPONENTI ADMIN (tutti previsti)

**Componenti riusabili** (in `resources/js/admin-ai-finops/components/`): `KpiCard`, `SpendChart`,
`BurndownChart`, `ForecastChart`, `FlameGraph`, `BudgetTree`, `PolicyEditor`(DSL), `DataTable`,
`FilterBar`, `Drawer`, `ConfirmModal`, `JsonViewer`, `Timeline`, `StatusBadge`, `CostPill`,
`CurrencyTag`, `ChannelBadge`, `AnomalyBadge`, `CarbonBadge`, `CreditMeter`, `CopilotChat`, `Toast`,
`LoadingState`, `EmptyState`.

**Screen** (ognuno con i suoi endpoint e scenari Playwright):
1. **Login/Auth** — `/login` (session/CSRF).
2. **Dashboard** — KPI spesa oggi/mese, trend, top model/tenant, budget burn, forecast, anomalie, CO₂.
3. **Usage Explorer** — tabella ledger + filtri + export.
4. **Call/Trace Detail** — dettaglio chiamata + flame-graph costo per step agentico.
5. **Budgets** — albero gerarchia, CRUD budget, periodi, soft/hard limit, burndown.
6. **Policies** — editor DSL, lista, simulate/validate.
7. **Approvals** — coda, approve/reject.
8. **Pricing** — modelli, prezzi, stato sync, editor override + sconti.
9. **Chargeback/Showback** — cost-center CRUD, report allocazione, export schedulati.
10. **Alerts** — canali (mail/Slack/Teams/webhook/SMS) + test, regole soglie %, log.
11. **Forecast & Anomalies** — proiezioni month-end, ack anomalie.
12. **Cost-aware Routing** — regole quality-per-dollar, punteggi eval-harness, simulate.
13. **What-if Simulator** — replay storico, scenari salvati.
14. **Price-change Watcher** — cambi listino provider, subscription.
15. **Credit Pools** — pool prepagati, topup, ledger.
16. **FinOps Copilot** — chat NL sui costi.
17. **CO₂/ESG** — footprint summary/trend.
18. **Settings** — currency/FX, retention, tenant, kill-switch scoped.
19. **Diagnostics/Workbench** — stima costo di prova, health.

---

## MACRO-TASK & SOTTOTASK (ognuno = branch; DoD + loop come sopra)

### M0 — Governance bootstrap & scaffolding (PRIMA del codice) — branch `chore/governance-bootstrap`
- **M0.1** Copiare/adattare da `product_image_discovery_admin`: `AGENTS.md`, `docs/RULES.md`,
  `docs/PROGRESS.md`, `docs/LESSON.md`. Adattare al dominio finops e alle regole qui sopra. *(solo codice/doc → no Playwright)*
- **M0.2** Creare governance formato Claude: `CLAUDE.md`, `.claude/skills/laravel-ai-finops-plan/SKILL.md`,
  regole/rules che codificano workflow PR + DoD + loop Copilot + LESSON/PROGRESS.
- **M0.3** Scaffolding pacchetto core: `composer.json` (laravel/ai ^0.6.8||^0.7, PHP ^8.3), service
  provider, config `ai-finops.php` (toggle ovunque), `phpunit.xml`, Pint, CI GitHub Actions (PHPUnit gate).
- **M0.4** **File template admin per Lorenzo**: `docs/ADMIN-TEMPLATE.md` — guida per costruire il template
  professionale **React + Vite + Tailwind (ultime versioni)**: stack/versioni, struttura cartelle
  `resources/js/admin-ai-finops/`, design tokens, set componenti riusabili (lista sopra), wiring Laravel
  (Blade shell + `@vite`, `window.AIFINOPS_ADMIN`), config Vitest + Playwright (desktop/tablet). Così
  Lorenzo costruisce il template mentre l'agente fa il core.
- **M0.5** Scaffolding repo admin: `composer.json` (path repo verso `../laravel-ai-finops`), provider,
  rotte admin, CI.
- **Verifica M0**: `composer validate` ok, CI parte, doc presenti.

### M1 — Metering foundation (core) — branch `feat/core-metering`
- **M1.1 Fase 0 assessment**: verificare quali repo usano `laravel/ai`; ricontrollare `agentic-qa-kit`;
  annotare eventuali "fix to laravel/ai" in LESSON.md. *(solo codice)*
- **M1.2** `AiCallEnvelope` + migrazioni ledger. PHPUnit.
- **M1.3** Hook `FinOpsMiddleware`/listener su `laravel/ai` (pre/post). PHPUnit con provider fake + Regolo.
- **M1.4** `PricingRegistry` (sync DB tipo LiteLLM + override locali + sconti) + `CostCalculator`
  (multimodale, multi-currency). PHPUnit.
- **M1.5** `Ledger` + API **Usage** (`/usage`, `/usage/{id}`, export) e **Pricing**. PHPUnit.
- **M1.6** API **Dashboard/KPI** base. PHPUnit.

### M2 — Budgets & enforcement (core) — branch `feat/core-budgets`
- **M2.1** Gerarchia budget N-livelli + `BudgetResolver` + periodi/reset. API **Budgets**. PHPUnit.
- **M2.2** `PolicyEngine` base (allow/block/kill-switch scoped) + `Enforcer` (HTTP 402). API **Policies**,
  **Settings/kill-switch**, `diagnostics/estimate`. PHPUnit.
- **M2.3** Report Artisan (`ai-finops:report`, `ai-finops:reset-budgets`). PHPUnit.

### M3 — Enterprise governance (core) — branch `feat/core-enterprise`
- **M3.1** Chargeback/showback + cost-center. API **Cost-centers/Chargeback**. PHPUnit.
- **M3.2** Policy avanzate (throttle/downgrade/queue/approval) + workflow approvazioni + RBAC.
  API **Approvals**. PHPUnit.
- **M3.3** Multi-currency/FX/tax + retention GDPR + audit trail (aggancio `ai-act-compliance`). PHPUnit.
- **M3.4** Alert multi-canale + regole soglie %. API **Alerts**. PHPUnit.

### M4 — WOW features (core) — branch `feat/core-wow`
- **M4.1** Forecast + anomaly detection. API **Forecast/Anomaly**. PHPUnit.
- **M4.2** Cost-aware routing + integrazione punteggi `eval-harness`. API **Routing**. PHPUnit.
- **M4.3** What-if simulator (replay storico). API **What-if**. PHPUnit.
- **M4.4** Streaming cost meter (websocket) + taglio mid-stream. PHPUnit + test integrazione.
- **M4.5** Price-change watcher (DNA `price-intelligence`). API **Price-watch**. PHPUnit.
- **M4.6** Credit pools prepagati. API **Credits**. PHPUnit.
- **M4.7** CO₂/ESG footprint. API **Footprint**. PHPUnit.
- **M4.8** Guardrail-linked spend (gate `pii-redactor`/`ai-act-compliance`). PHPUnit.
- **M4.9** FinOps Copilot (NL via `ai-chat`/`AskMyDocs`). API **Copilot**. PHPUnit.

### M5 — Admin package — branch `feat/admin-ui` (repo `laravel-ai-finops-admin`)
Per OGNI screen (lista sopra) un sottotask **M5.x**: implementare React+Tailwind, collegare gli endpoint,
**scenari Playwright di tutte le interazioni** + Vitest dei componenti. Se manca un'API nel core →
**gap-backfill**: implementare+rilasciare nel core, poi completare lo screen (mai mock).
Sottotask: M5.1 Login · M5.2 Dashboard · M5.3 Usage Explorer · M5.4 Call/Trace Detail · M5.5 Budgets ·
M5.6 Policies · M5.7 Approvals · M5.8 Pricing · M5.9 Chargeback · M5.10 Alerts · M5.11 Forecast/Anomalies ·
M5.12 Routing · M5.13 What-if · M5.14 Price-watch · M5.15 Credit Pools · M5.16 Copilot · M5.17 CO₂/ESG ·
M5.18 Settings · M5.19 Diagnostics. Stress dashboard con dataset grande (~500k chiamate).

### M6 — Collante agentico — branch `feat/agentic-glue`
- **M6.1** Propagazione `trace-id`/budget-context end-to-end via `laravel-flow` → costo per step. PHPUnit.
- **M6.2** Vista correlata costo + qualità (eval-harness) + compliance (ai-act) per trace-id (core API +
  screen admin). PHPUnit + Playwright.

### M7 — Finalizzazione — branch `chore/release`
- **M7.1** README WOW core + admin (badge, banner, TOC, innovazione, screenshot, quick start junior-proof).
- **M7.2** Consolidare `docs/LESSON.md` → potenziare/creare rules, skills, AGENTS.md con il knowhow nuovo.
- **M7.3** Tag `vX.X.X` (core e admin) + release GitHub.

---

## Verifica end-to-end
- **Core**: middleware intercetta pre/post di provider reale + Regolo; ledger/budget aggiornati; 402 a
  soglia; pricing sync; policy DSL (downgrade/approval); forecast su storico; routing sceglie il modello
  dati i punteggi eval-harness; taglio mid-stream.
- **Admin**: Playwright per ogni screen (desktop+tablet), dataset ~500k per stress.
- **Collante**: run `laravel-flow` multi-step → flame-graph costo per step con un unico trace-id; vista
  correlata costo/qualità/compliance.
- Gate ripetibili: `composer validate` · PHPUnit · Vitest · `npm run build` · Playwright + loop Copilot.

## Da confermare in esecuzione
- Sorgente pricing definitiva (mirror LiteLLM vs DB Padosoft) e cadenza sync.
- API eventi/middleware di `laravel/ai` 0.6 vs 0.7 (range compat).
- Versioni esatte da pinnare: React/Vite/Tailwind (ultime stabili) + Laravel 13.x + PHP 8.3/8.4.
- `agentic-qa-kit`: conferma uso `laravel/ai` (grep locale non conclusivo).
