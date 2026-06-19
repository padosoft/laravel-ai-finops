---
title: "Motivazione"
description: "Why AI FinOps governance belongs in Laravel applications."
---

# Motivazione

Le applicazioni agentiche spostano la spesa AI da pochi job controllati a molte decisioni runtime. Senza governance, lo stesso agente puo generare costi non attribuiti, budget sforati, routing subottimale e report non riconciliabili.

::: callout info "Il problema" icon:target
Tracking e blocking separati non bastano. Serve un ledger unico che sia anche input per budget, policy, forecast, chargeback e audit.
:::

## Motivazione profonda

::: grids
  ::: grid
    ::: card "Costo variabile" icon:trending-up
    Token, media, cache, endpoint e provider rendono la spesa non lineare.
    :::
  :::
  ::: grid
    ::: card "Contesto distribuito" icon:git-merge
    Tenant, user, agent step e purpose devono viaggiare con la chiamata.
    :::
  :::
  ::: grid
    ::: card "Governance runtime" icon:shield-alert
    Una policy deve poter bloccare prima che un sistema autonomo moltiplichi la spesa.
    :::
  :::
:::

## Worked example

Un agente di supporto usa tre step: classificazione, retrieval e risposta. Il costo totale non basta: il chargeback richiede tenant e cost center, il routing richiede modello e qualita, l'audit richiede chi ha cambiato budget e policy.

## Gotcha

::: callout warning "Metriche incomplete" icon:triangle-alert
Un provider puo restituire costo reale, solo token, o nessun usage affidabile. Per questo il modello dati conserva sia il metodo di costo sia il flag `tokens_estimated`.
:::

