# Gate 0 Inspection — Transport decision: Drupal MCP (Option C) vs custom REST (Option B)

- **Date:** 2026-06-29 · **Scope:** READ-ONLY evidence gathering. No installs, no config, no build.
- **Decision being informed:** transport/auth for a **create-only** Work Order intake (WO +
  wo_notes + scheduling; never completion or field-execution children), run by a "System" service
  account holding the minimal `system_integration` role. See `work_order_api.md` and
  `system_integration_role_inspection.md`.
- **Client:** Cowork, which speaks MCP natively.
- *Note:* the originating prompt was truncated mid-Task-3. This report covers the three received
  areas — module state/maturity, auth-model fit, encapsulation — plus a recommendation. Redirect
  if more was intended.

---

## TL;DR

| | Option B — custom key-auth REST | Option C — Drupal MCP server |
|---|---|---|
| **New contrib surface** | None (uses `key`, already enabled) | MCP Server module + Tool API (+ optional Simple OAuth 2.1) |
| **Security coverage** | N/A (our code) | ⚠ **The forward module is alpha / NOT covered**; the covered one is deprecated (see §1) |
| **Runs as our service account (least-privilege)** | ✅ Yes, natively | ✅ Yes — token auth maps to a specific user account |
| **Custom encapsulated write logic** | ✅ Full control | ✅ Yes — custom Tool API plugin |
| **Client fit (Cowork = MCP)** | Needs a thin MCP→REST shim client-side | ✅ Native |
| **Maturity risk on a write path** | Low (we own it) | **High right now** |

**Recommendation: Option B now**, with the write logic in a **transport-agnostic service** so
Option C can be added later (as a Tool API plugin) once `drupal/mcp_server` reaches a stable,
security-covered release. The maturity gate disqualifies C for a production WO write path **today**.

---

## 1. Module state & maturity

**Present in this codebase (composer.lock):**
- `drupal/ai` **1.2.17** (enabled), `drupal/ai_agents` **1.2.5** (enabled),
  `drupal/ai_provider_openai` **1.2.2** (enabled). `key` enabled.
- **No MCP module is installed** — `drupal/ai` 1.2.x ships no `mcp` submodule, and there is no
  `drupal/mcp` / `drupal/mcp_server` in the tree. Option C is a **net-new contrib add**.

**The MCP server capability — two-module situation (this is the crux):**
- **`drupal/mcp` 1.2.3** (2025-11-14) — **stable, security-covered (green shield)**, D10/11, ~317
  sites. **BUT** its project page states it is *"in the process of merging with the MCP Server
  module"* and directs users seeking a server implementation to **MCP Server** instead. Adopting
  it means building a critical write path on a **sunsetting** module.
- **`drupal/mcp_server` 2.0.0-alpha1** (2026-06-11) — the **dedicated, forward** module — **has
  NO stable release**, therefore is **NOT security-covered** (the shield applies only to stable
  releases; *"There are currently no supported stable releases"*). D `^10 || ^11`. It is also at a
  **2.0 alpha** with active protocol churn.

> **Hard gate, stated plainly:** the module the project tells you to use for an MCP server
> (`mcp_server`) is **alpha and not security-covered** — **disqualifying for the WO write path
> today**. The only security-covered alternative (`mcp` 1.2.3) is **deprecated/merging away**.
> Either choice is a liability for a production create path right now.

**Maintainers' own posture (from the installed `drupal/ai` security doc,
`docs/agents/security.md`, Problem #5):** *"As of 2025-06-22, the advice is — do not use MCP
tools on critical websites at all,"* and the MCP **Client** module *"will not have security
coverage until it can mitigate any and all risks with MCP."* That specific warning targets MCP
**client** (outbound; prompt-injection via untrusted tool descriptions) — it does **not** fully
apply to Drupal-as-MCP-**server** exposing our own tools to our own client — but it signals the
AI initiative treats MCP as not-yet-hardened for critical use.

**Dependency footprint if we took Option C:** MCP Server pulls in the **Tool API** module
(auto), needs **Drush ≥12** for the STDIO transport, and **Simple OAuth 2.1** *optionally* for
OAuth. Notably it does **not** depend on `drupal/ai` (it uses the Tool API + a JSON-RPC plugin
layer, not the AI function-call system). PHP: not explicitly pinned on either page; D10/11
modules target PHP 8.1+, so our 8.3 is fine (not independently verified).

---

## 2. Auth-model fit (decisive: least-privilege is preserved either way)

MCP Server offers a **multi-strategy** inbound auth model:
- **Token-based** — a shared secret stored via the **Key module** (already enabled). Per the
  architecture docs, it *"can be configured to authenticate as a specific user account
  (recommended) or default to the administrator account (UID 1),"* a capability added in 1.1
  *"enabling principle-of-least-privilege operations."*
- **Basic auth** — Drupal username/password; *"loads the authenticated user with appropriate
  permissions."*
- **OAuth 2.1** — via `simple_oauth_21`, with per-tool scope requirements.
- Gated by a **"Use MCP server"** permission; integrates with Drupal flood control
  (`McpAuthProvider`).

**Answer to the decisive question:** ✅ **Yes — an MCP tool call can execute as a specific,
access-checked Drupal user** (our System service account), so the `system_integration` role *is*
enforced and entity access is checked as that user. Option C does **not** forfeit the
least-privilege guarantee that Option B gives us — provided token auth is bound to the service
account, not UID 1.

(Option B gets the same property trivially: a custom REST resource authenticates via `key` and
runs the request as the service account.)

---

## 3. Encapsulation model (not a disqualifier for C)

- **MCP Server supports custom tools backed by our own code:** *"Create a Tool API plugin in
  your custom module"* — tools are managed through Drupal's configuration system (not PHP
  attributes). So we **can** define a single WO-intake tool whose handler is our code, enforcing
  the **bundle↔service invariant**, the **dated-scheduling→1091 cascade**, **estimate-bundle
  rejection**, and **dedupe/find-or-create** guards. It is **not** limited to generic entity CRUD.
- Because the tool runs as the mapped service account (§2), our custom handler's entity
  operations are **access-checked under the `system_integration` role** — the same enforcement
  Option B gives.
- The installed `drupal/ai` already has the parallel **FunctionCall plugin system**
  (`#[FunctionCall]`, `FunctionCallPluginManager`, `Plugin/AiFunctionCall/`, with
  `ActionPluginAccessTest` proving per-tool access tests) — evidence the ecosystem's tool layer
  is access-aware. (MCP Server uses the Tool API rather than ai's function-calls, but the
  encapsulation principle holds.)

**Conclusion:** encapsulation is achievable in C — it is **not** the disqualifier. The
disqualifier is purely **maturity/security coverage** (§1).

---

## 4. Recommendation & decision logic

**Adopt Option B (custom key-authenticated REST) now.** Rationale:
1. **Maturity gate:** the forward MCP server module is alpha/not-security-covered; the covered
   one is deprecated. Neither is appropriate for a production WO **write** path today (§1).
2. **Zero new churning contrib:** B uses only `key` (already enabled). C would add MCP Server +
   Tool API (+ maybe Simple OAuth 2.1) — a contrib surface that is itself mid-2.0-rewrite.
3. **Least-privilege + encapsulation are equal** between B and C (§2, §3) — so C buys us only
   "native MCP for Cowork," which does not outweigh the maturity risk.
4. **BOS-idiomatic:** "custom over contrib," small, auditable, fully under our control.

**De-risk the choice architecturally — make the transport swappable:**
- Put the entire WO-intake write sequence (invariant, dated-scheduling cascade, estimate
  rejection, dedupe) in a **transport-agnostic service** (e.g. `WorkOrderIntakeService`).
- Expose it **now** via a thin custom REST resource (Option B).
- Expose it **later** via a thin **Tool API plugin** (Option C) once `drupal/mcp_server` ships a
  **stable, security-covered** release — both transports call the same service. The hard part
  (the validated write logic) is built once and reused.
- **Bridge Cowork in the interim:** since Cowork speaks MCP, run a thin **MCP→REST shim** on the
  client side (or a tiny local MCP wrapper) that forwards to our REST endpoint.

**Re-evaluate Option C when:** `drupal/mcp_server` has a **stable release covered by the security
advisory policy**, the 2.x protocol churn settles, and (ideally) the AI initiative's
critical-site guidance on MCP softens for the server direction.

---

## Sources
- `drupal/ai` installed source: `web/modules/contrib/ai/docs/agents/security.md` (Problem #5);
  `src/Service/FunctionCalling/`, `src/Plugin/AiFunctionCall/`.
- composer.lock (drupal/ai 1.2.17, ai_agents 1.2.5, ai_provider_openai 1.2.2).
- [drupal.org/project/mcp](https://www.drupal.org/project/mcp) — 1.2.3, stable, security-covered, merging into MCP Server.
- [drupal.org/project/mcp_server](https://www.drupal.org/project/mcp_server) — 2.0.0-alpha1, no stable release, not security-covered.
- [drupalmcp.io — Concepts & Architecture](https://drupalmcp.io/en/developers/concepts-architecture/) — token auth via Key, map to specific user account, basic auth.
- [The DropTimes — Drupal MCP 1.2 released (security coverage, Tools API, OAuth)](https://www.thedroptimes.com/56065/drupal-mcp-12-released-with-security-coverage-tools-api-integration-and-oauth-support).

**STOP — Gate 0 complete. No module installed, no transport built.**
