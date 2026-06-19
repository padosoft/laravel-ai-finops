---
title: "Installation"
description: "Install package files, migrations, and optional token counting."
---

# Installation

## Requirements

| Requirement | Supported |
| --- | --- |
| PHP | `^8.3` |
| Laravel components | `^12.0 || ^13.0` |
| JSON extension | Required |
| Database | Any Laravel-supported connection used by migrations |

## Composer

```bash
composer require padosoft/laravel-ai-finops
```

Publish the package artifacts:

```bash
php artisan vendor:publish --tag=ai-finops-config
php artisan vendor:publish --tag=ai-finops-migrations
php artisan migrate
```

## Optional packages

::: callout info "Exact token counting" icon:binary
The built-in heuristic estimator is always available. Install `yethee/tiktoken` when exact OpenAI-compatible token counts matter for pre-flight estimates and estimated cascade rows.
:::

```bash
composer require yethee/tiktoken
```

## Routes

The package mounts API routes under `config('ai-finops.routes.prefix')`, defaulting to `api/ai-finops`. The public `health` endpoint is open. Privileged endpoints use the configured `auth_middleware`.

