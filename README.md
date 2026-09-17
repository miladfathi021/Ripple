# Ripple

[![CI](https://github.com/miladfathi021/Ripple/actions/workflows/ci.yml/badge.svg)](https://github.com/miladfathi021/Ripple/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-%5E8.3-777BB4.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

See how far your code changes travel.

Ripple is a PHP code change impact analyzer that helps developers understand how far a code change can travel before merging a pull request.

It combines a Git working-tree diff with PHP AST parsing, a repository-wide dependency graph, blast radius, risk factors, affected flows, PHPUnit test impact, and Git history. Optional AI can turn those deterministic findings into human-readable explanations. AI is not the analysis engine.

**Ripple reports potential impact, not guaranteed breakage.**

Further reading: [architecture](docs/architecture.md) · [development](docs/development.md) · [limitations](docs/limitations.md)

---

## Why Ripple?

A typical Git diff answers *what changed*:

```text
I changed ReservationService::updateStatus()
```

The questions that matter in review are usually:

- What depends on this?
- How far can the change travel?
- Which downstream flows may be affected?
- Which tests should I review?
- Has this area changed frequently?
- Why is this change considered risky?

Ripple answers *what might this change affect?* using static analysis. It does not predict production failures.

---

## How it works

```text
Git working-tree diff
        │
        ▼
Changed symbols
        │
        ▼
Repository PHP index
        │
        ▼
Dependency graph  ──►  Affected flows
        │
        ▼
Direct impact
        │
        ▼
Blast radius
        │
        ├── Risk factors → Risk score
        ├── Semantic impact
        ├── Git churn
        └── PHPUnit test impact
                │
                ▼
          AnalysisResult
                │
                ▼
        Optional AI layer
```

| Stage | What it does |
| --- | --- |
| Git diff | Collects unstaged working-tree changes (`git diff --find-renames`). |
| Changed symbols | Maps added lines to the most specific known PHP symbol. |
| Repository index | Parses project PHP files once (not `vendor`). |
| Dependency graph | Records `A → B` as “A depends on B”. Unresolved targets stay unknown. |
| Direct impact | Project symbols that depend on a changed symbol in one hop. |
| Blast radius | Transitive reverse dependents, with shortest depth. |
| Affected flows | Downstream paths the changed code itself depends on (max depth 3). |
| Risk | Deterministic 0–100 score from explainable factors. |
| Semantics | Labels from `ripple.json` and, optionally, a Laravel AST adapter. |
| Churn | Git history of the changed files. |
| Test impact | PHPUnit tests that appear in the blast radius. |
| AI | Optional interpretation of the result. Never recalculates it. |

---

## Core principle

Ripple is static and deterministic:

- Application code is not executed.
- Artisan, PHPUnit, and Composer packages in the analyzed tree are not run.
- Unresolved dependencies are left unknown rather than guessed.
- The risk score is computed from analysis facts. AI cannot change it.
- Test impact is graph-based, not coverage-based.

Ripple is not a linter, test runner, coverage tool, mutation tester, or runtime executor. Optional AI never replaces the deterministic result.

---

## Quick start

Requires PHP 8.3+, Composer, and Git.

```bash
git clone https://github.com/miladfathi021/Ripple.git
cd Ripple
composer install
./bin/ripple analyze
```

`analyze` is the default command, so `./bin/ripple` is equivalent.

Analyze a checkout that has unstaged PHP changes. A clean working tree produces an empty diff and a successful low-risk report.

---

## CLI

| Invocation | Meaning |
| --- | --- |
| `./bin/ripple analyze` | Text report on stdout. |
| `./bin/ripple analyze --format=json` | Machine-readable JSON. |
| `./bin/ripple analyze --format=comment` | Compact PR-comment body (includes `<!-- ripple-analysis -->`). |
| `./bin/ripple analyze --comment-file=path` | Also write that compact comment to a file. |
| `./bin/ripple analyze --ai` | Request optional AI sections (see [AI](#ai)). |

`--comment-file` can be combined with `--format=json`. A comment-file write failure is reported on stderr and does not fail analysis.

### Exit codes

| Code | When |
| --- | --- |
| `0` | Analysis completed (`status: ok`). |
| `1` | Analysis failed (not a Git repo, parse error, invalid `ripple.json` semantics, …). |
| `2` | Invalid CLI usage (unknown `--format`). |

Invalid-format and AI errors go to stderr so JSON on stdout stays parseable.

---

## Example output

The compact `--format=comment` report below is actual CLI output from a small sample app (not this repository). Full graphs, file lists, and history stay in `--format=json`.

```text
<!-- ripple-analysis -->
🌊 Ripple — Code Impact Analysis

Risk: 60 / High

Changed symbols
────────────────────────
ReservationService::updateStatus()
ReservationService::cancel()

Blast radius
────────────────────────
Depth 1: 11 symbols
Depth 2: 1 symbol
Depth 3: 1 symbol
Depth 4: 1 symbol

Affected flows
────────────────────────
→ PaymentService

Test impact
────────────────────────
✓ 1 direct test

Historical churn
────────────────────────
None

⚠ Risk factors
────────────────────────
High fan-in
Large blast radius
Deep dependency chain
Multiple changed symbols

See full analysis in the workflow artifact.
```

The matching `--format=text` report is a short terminal summary. Full graphs, file lists, and history stay in `--format=json`.

```text
Ripple Analysis
───────────────
Risk: 60 / 100 (High)
Changed files: 1
Blast radius: 10 symbols
Affected tests: 1 direct, 0 indirect
```

---

## JSON output

Successful JSON includes:

| Field | Contents |
| --- | --- |
| `status` | `"ok"` or `"error"` |
| `diff.files` | Changed paths, change type, added/deleted line numbers |
| `changed_symbols` | Mapped symbols and changed lines |
| `unmapped_lines` / `unmapped_deleted_lines` | Lines that did not map to a symbol |
| `direct_impact` | One-hop reverse dependents |
| `blast_radius` | Transitive dependents with depth and origins |
| `affected_flows` | Downstream paths from changed symbols |
| `test_impact` | Impacted PHPUnit tests |
| `risk_factors` / `risk_score` | Deterministic assessment |
| `semantic_impact` | Annotation labels on blast radius / flows |
| `churn` | Per-file Git history |
| `dependencies` | Dependencies extracted from *changed* files |
| `graph` / `reverse_graph` | Full repository graph |
| `project_index` | PHP file / symbol / dependency counts |

On failure, JSON is `{ "status": "error", "message": "..." }`.

Optional AI objects appear only when a provider actually generated text:

```text
ai_explanation
ai_risk_explanation
ai_test_recommendations
```

Each has `{ "generated": true, "text": "..." }`. Empty or failed AI output is omitted entirely.

`graph` and `reverse_graph` are the full repository model. That keeps reports complete and can make JSON large (several megabytes on mid-size trees). That is a known trade-off.

---

## GitHub Action

The workflow at [`.github/workflows/ripple.yml`](.github/workflows/ripple.yml) runs on `pull_request` (`opened`, `synchronize`, `reopened`):

```text
PR event
   → checkout (fetch-depth: 0)
   → mixed reset to the base SHA
   → intent-to-add untracked files
   → docker build of this repository
   → ripple analyze --format=json --comment-file=...
   → JSON + comment artifact
   → create or update one PR comment
```

As written, the workflow **builds Ripple from the checked-out repository**. It is the Action for *this* project. Copying only the YAML into an unrelated app repository will fail unless that repository also has Ripple’s `Dockerfile` and source, or you adapt the job to a published image (none is published yet).

### Action properties

- Trigger is `pull_request`, not `pull_request_target`.
- Permissions: `contents: read`, `pull-requests: write`.
- Analysis container: `--network none`, no Docker socket, not privileged.
- Repository mount is read-only (`/workspace:ro`); comments write to a separate `/out` volume.
- `--ai` is not passed; no AI secrets are provided to PR code.
- High risk does not fail the analyze job.
- Comment failures are a separate job and do not rewrite the analysis artifact.
- If several Ripple comments exist, the oldest matching `<!-- ripple-analysis -->` is updated; others are left alone.

This is isolation for untrusted PR source, not a claim of perfect sandboxing. `docker build` of PR source still runs on the GitHub runner.

---

## Configuration

Optional file: `ripple.json` in the analyzed working directory.

Missing file: analysis continues with no semantic annotations and AI disabled.

An empty object `{}` is invalid: if the file exists, it must contain `semantic_annotations`, `framework`, and/or `ai`.

Invalid JSON fails analysis.

Duplicate `{symbol, type}` pairs are kept once, then sorted.

### Explicit semantics

```json
{
  "semantic_annotations": [
    {
      "symbol": "App\\Http\\Controllers\\ReservationController::update",
      "type": "api_entrypoint"
    }
  ]
}
```

Types: `api_entrypoint`, `database_read`, `database_write`, `queue`, `event`, `authentication`, `external_integration`.

Unknown types fail analysis. Annotations for symbols that are not in the blast radius or affected flows are ignored at report time; they do not create fake graph nodes.

### Laravel

```json
{
  "framework": "laravel",
  "semantic_annotations": [
    {
      "symbol": "App\\Http\\Controllers\\ReservationController::update",
      "type": "authentication"
    }
  ]
}
```

Only `"laravel"` enables an adapter. Other `framework` strings are accepted and currently have no adapter. See [Laravel support](#laravel-support).

### AI enablement

```json
{
  "ai": {
    "enabled": true,
    "provider": "openai",
    "model": "YOUR_MODEL_NAME",
    "timeout_seconds": 30
  }
}
```

`--ai` is still required. `enabled` must be a boolean. `provider` is required when AI is enabled (`openai` or `codecraft`). `model` is required for those providers. Optional `base_url` is used by CodeCraft (default `https://www.codecraftapi.com/v1`). The API key is **not** stored in this file. See [AI](#ai).

Optional `timeout_seconds` must be a positive JSON integer (not a float such as `30.0`). Default: `30`.

---

## Features

### Git diff

Ripple runs `git diff --no-color --no-ext-diff --find-renames` in the working directory.

That is the **unstaged** working tree versus the index:

- Modified, added, deleted, and renamed files in that diff are included.
- Line numbers come from hunks; binary files are recorded without invented line numbers.
- Untracked files are **not** included unless they have been intent-to-added (`git add -N`), which the GitHub Action does.
- Staged-only changes (index vs `HEAD` with a matching working tree) are **not** in `git diff`.
- Not a Git repository, or a Git execution failure, fails analysis.

The GitHub Action mixed-resets to the PR base SHA so the working-tree diff represents the pull request.

### Symbols

Changed **added** lines map to the most specific overlapping symbol:

`class` · `interface` · `trait` · `enum` · `method` · `function`

Deleted files contribute unmapped deleted lines, not guessed previous symbols. Anonymous classes are not indexed. Duplicate FQNs in two files fail analysis.

### Dependency graph

`A → B` means **A depends on B** (call, type, extends, …).

Targets found in the project index are `known: true`. Composer and other unresolved names remain `known: false` nodes. Ripple does not invent missing edges.

### Direct impact vs blast radius vs affected flows

If `A → B → C` and **A** changes:

| View | Question | Result |
| --- | --- | --- |
| Direct impact | Who depends on A in one hop? | `B` |
| Blast radius | Who can be reached walking dependents? | `B` (depth 1), `C` (depth 2) |
| Affected flows | What does A depend on downstream? | paths starting at A toward its callees |

Blast radius: *who depends on my changed code?*  
Affected flows: *what does my changed code depend on?*

Self-edges are not listed as impact. Cycles terminate; shortest depth wins. Affected flows stop at depth **3**.

### Risk score

Deterministic 0–100 heuristic. Not a probability of failure. AI cannot modify it.

| Factor | Max weight |
| --- | --- |
| `high_fan_in` | 20 |
| `large_blast_radius` | 20 |
| `deep_impact` | 15 |
| `multiple_dependency_types` | 10 |
| `multiple_changed_symbols` | 10 |
| `historical_churn` | 25 |

Weights sum to 100. Each present factor contributes according to severity: **info ≈ 50%**, **warning ≈ 75%**, **high = 100%** of its weight (integer rounding). Duplicate factor codes are rejected.

| Score | Level |
| --- | --- |
| 0–29 | low |
| 30–59 | medium |
| 60–100 | high |

### Git history

For each changed file, Ripple reads `git log --follow` (falls back without `--follow`) and reports commit count, lines added/deleted, contributor count, and last commit time. Churn can raise `historical_churn` (from 10 / 25 / 50 commits). History is not a bug prediction.

### PHPUnit test impact

Classes extending `PHPUnit\Framework\TestCase` are detected statically. Methods named `test*` or marked `#[PHPUnit\Framework\Attributes\Test]` are test methods.

A test is **direct** when the blast-radius depth to it is 1, otherwise **indirect**. PHPUnit is not executed. Coverage is not collected. Pest and other runners are not detected.

---

## Laravel support

With `"framework": "laravel"`, Ripple walks the already-parsed AST for known Laravel APIs (facades such as `Route`, `DB`, `Auth`, `Http`, `Event`, `Bus`, `Queue`; Eloquent / query builder; `ShouldQueue`; dispatchable events). It labels matching symbols with the same semantic types as explicit annotations.

It does **not** boot Laravel, run Artisan, read `.env`, or infer meaning from arbitrary class names. Detection is conservative: unmatched Laravel usage stays unlabeled rather than guessed.

---

## AI

AI is optional interpretation of `AnalysisResult`. Deterministic analysis does not use it.

```bash
./bin/ripple analyze          # never calls AI
./bin/ripple analyze --ai     # calls AI only if ripple.json enables it
```

Supported providers:

| Provider | `ripple.json` `provider` | API |
| --- | --- | --- |
| None | omit `ai` / `"enabled": false` | `NullAIProvider` (no network) |
| OpenAI | `openai` | OpenAI **Responses** API (`POST /v1/responses`) |
| CodeCraft | `codecraft` | CodeCraft **Chat Completions** API (`POST {base_url}/chat/completions`) |

`--ai` plus a valid `ripple.json` AI block is required. OpenAI:

```json
{
  "ai": {
    "enabled": true,
    "provider": "openai",
    "model": "YOUR_MODEL_NAME"
  }
}
```

CodeCraft (choose a model ID from your CodeCraft account; Ripple does not assume which models are available):

```json
{
  "ai": {
    "enabled": true,
    "provider": "codecraft",
    "model": "MODEL_ID",
    "base_url": "https://www.codecraftapi.com/v1"
  }
}
```

`base_url` is optional for CodeCraft and defaults to `https://www.codecraftapi.com/v1`. Do not put the complete `/chat/completions` path in `base_url`.

```bash
export RIPPLE_AI_API_KEY="..."
./bin/ripple analyze --ai
```

### Local AI setup

`.env` is a local convenience for developers. Copy the example file and set the key there if you do not want to export it in your shell:

```bash
cp .env.example .env
```

```env
RIPPLE_AI_API_KEY=cc_...
```

Do not commit `.env`. A placeholder such as `cc_...` is only documentation; never put a real key in the repository.

Then enable AI in `ripple.json` (the API key is never stored in that file). Example for CodeCraft:

```json
{
  "ai": {
    "enabled": true,
    "provider": "codecraft",
    "model": "MODEL_ID",
    "base_url": "https://www.codecraftapi.com/v1"
  }
}
```

Use `"provider": "openai"` and an OpenAI model name for the OpenAI Responses API instead. Obtain CodeCraft model IDs from your CodeCraft account.

```bash
./bin/ripple analyze --ai
```

`.env` is gitignored and must not be committed. Existing process environment variables win over `.env`. Ripple still reads `RIPPLE_AI_API_KEY` from the environment; `.env` only fills the variable when it is not already set.

The GitHub Action does **not** pass `--ai` and must not receive `RIPPLE_AI_API_KEY`. PR analysis stays deterministic.

For Docker, pass the key at run time. Do not bake `.env` into the image:

```bash
docker run --rm \
  -e RIPPLE_AI_API_KEY="..." \
  -v "$PWD":/workspace \
  -w /workspace \
  ripple analyze --ai
```

Default Docker analysis without that flag still works offline with no key.

Three independent calls run after analysis:

| Output | JSON field | Role |
| --- | --- | --- |
| PR explanation | `ai_explanation` | Human summary of the change impact |
| Risk explanation | `ai_risk_explanation` | Why the existing score looks that way |
| Test recommendations | `ai_test_recommendations` | Tests to review or consider adding |

Each can fail without dropping the others or the deterministic report. AI cannot change the risk score, rebuild the graph, or execute application code.

Ripple sends **structured analysis facts** (changed symbols, blast radius, risk contributions, test impact, …), not the repository source tree, `.env`, or credentials.

When AI is enabled, those structured facts are sent to the configured provider (OpenAI or CodeCraft). That is different from uploading complete source files; it is still data leaving your machine. Ripple does not make privacy or compliance guarantees about the vendor.

If a provider call fails, JSON stays valid and the corresponding AI field is omitted. The CLI exit code still follows deterministic analysis.

`NullAIProvider` remains the no-op implementation used when AI is disabled.

Illustrative AI sections (not from a live API call):

```text
AI explanation:
  This change updates ReservationService::updateStatus() and ReservationService::cancel().
  Direct callers include ReservationController::update() and ReservationServiceTest::testUpdateStatus().

AI risk explanation:
  The High score comes from high fan-in, a large blast radius, and a deep dependency chain.

AI test recommendations
────────────────────────
Consider updating Tests\Unit\ReservationServiceTest::testUpdateStatus.
```

Anthropic, Gemini, and other vendors are not supported.

---

## Architecture

CLI → `AnalysisRunner` → `AnalysisResult` → reporters. AI sits only on the result.

Deterministic packages do not import `Ripple\AI`. Laravel is a semantic provider, not a core engine dependency.

See [docs/architecture.md](docs/architecture.md).

---

## Safety

Ripple’s analyzer:

- does not execute application PHP, Artisan, or PHPUnit
- does not `composer install` inside the analyzed tree
- does not need network for deterministic analysis
- does not mount the Docker socket or run a privileged container in the Action

The Action uses `pull_request` so untrusted PR code does not get write tokens via `pull_request_target`. Analysis still builds a Docker image from PR source on the runner.

---

## Limitations

Static analysis is not runtime proof. Unresolved types stay unknown. PHPUnit only. JSON includes the full graph. AI is optional OpenAI or CodeCraft; it is off by default and unused by the GitHub Action.

Full list: [docs/limitations.md](docs/limitations.md).

Possible later work (no timeline): additional AI providers, compact JSON, more framework adapters, Pest, richer PHP types, tighter Action job permissions, non-root image user.

---

## Development

```bash
composer install
composer test
```

PHPUnit also runs in GitHub Actions (`.github/workflows/ci.yml`) on `push` to `main` and on pull requests.

See [docs/development.md](docs/development.md).

---

## Versioning

Ripple is in **0.x** development. The CLI currently reports `0.1.0`.

Releases are Git tags. Composer does not pin a package version in `composer.json`.

Recommended first public tag: **0.1.0**. A `1.0.0` tag would mean a stable public contract; that has not been declared yet. Do not treat current `main` as 1.0.

---

## Contributing

1. Fork and branch.
2. Keep deterministic analysis independent of AI.
3. Add tests for behavior changes.
4. Run `composer test`.
5. Update docs when behavior or flags change.
6. Open a pull request.

---

## License

[MIT](LICENSE)
