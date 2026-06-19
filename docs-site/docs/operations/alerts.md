---
title: "Alerts"
description: "Configure channels, rules, logs, and tests."
---

# Alerts

Alerts are split into channels, rules, and log entries.

| Endpoint family | Use |
| --- | --- |
| `/alerts/channels` | Configure delivery destinations |
| `/alerts/rules` | Define thresholds and conditions |
| `/alerts/log` | Review sent or failed notifications |

## Channel testing

Use `POST /alerts/channels/{id}/test` after creating or rotating a channel.

::: callout tip "Host delivery" icon:send
The package records and dispatches alert events. Host applications own final mail, Slack, Teams, webhook, or SMS delivery wiring.
:::

## Deduplication

Threshold rules track last-notified percentages so operators are not spammed for the same crossing until the rule re-arms.

