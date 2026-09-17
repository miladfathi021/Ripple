# Ripple

Ripple is an AI-assisted code change impact analyzer. It helps developers understand what a change can affect before a pull request is merged.

## The problem

Code reviews often miss hidden blast radius: a small diff can touch shared symbols, downstream callers, and high-risk paths. Ripple will analyze Git diffs, parse source code, and produce a deterministic impact report so that risk is visible before merge.

## Current status

Task 25 — AI risk explanation.

Ripple builds a **repository-wide static dependency graph**, then reports **direct impact**, **blast radius**, **test impact**, **affected flows**, **semantic impact**, **risk factors**, a **deterministic risk score**, and **Git history churn** for the current Git diff.

The Git diff determines what changed.
The repository index determines what exists and how it is connected.
Direct impact answers which project symbols **directly depend on** those changed symbols.
Blast radius follows reverse-dependency edges further to find every reachable dependent.
Test impact filters that blast radius to PHPUnit test methods that may be affected by the change.
Affected flows trace the downstream dependency paths that changed code itself relies on.
Semantic impact labels those symbols from explicit JSON annotations and, when enabled, a Laravel adapter that reads Laravel APIs from the AST.
Risk factors describe measurable characteristics of that analysis.
The risk score turns those factors into a 0–100 potential-impact level with explainable contributions.
Git history reports how much the changed files have historically changed: commit counts, line additions and deletions, contributors, and the most recent commit timestamp.

Impact views, flows, tests, and the score are **potential** static impact, not a guarantee that runtime behavior will break. Churn is historical evidence, not a prediction.

### Repository index

Ripple recursively discovers PHP files from the Git working tree, including tests and currently untracked PHP files.

It does **not** scan:

- `.git`
- `vendor`
- `node_modules`
- `storage`
- `bootstrap/cache`

Symlinked directories are not followed.

Each indexed PHP file is parsed once through the existing AST pipeline. Application code is not executed. Composer packages are not installed or resolved. External classes such as `Illuminate\Database\Eloquent\Model` remain unknown graph nodes (`known: false`).

Duplicate symbol definitions (the same FQN in two files) fail analysis with both file paths.

The graph includes isolated project symbols that have no dependencies.

### Direct impact

For each changed symbol, Ripple looks up **direct** dependents on the reverse dependency graph — one lookup, no recursion.

Example:

```text
A → B → C → D

If A changes:

Direct impact:
B
```

`C` and `D` are not direct impact.

If `PaymentService::validate` calls `ReservationService::updateStatus`, a change to `updateStatus` lists `PaymentService::validate` as direct impact. Callers of `validate` are not included here.

- A changed symbol with no dependents reports `Direct impact: None`.
- The changed symbol itself is not listed as impacted, including self-edges (`A → A`).
- Deleted files are absent from the working-tree index, so Ripple does not guess their previous dependents.
- Distinct dependency types (`method_call`, `parameter_type`, …) are preserved.

### Blast radius

Blast radius is the **transitive** closure of those reverse-dependency edges: every symbol that can be reached by walking dependents of dependents.

```text
A → B → C → D

If A changes:

Direct impact:
B

Blast radius:
B
C
D
```

Depth is the shortest reverse-dependency distance from a changed symbol (`B` is depth 1, `C` is depth 2, `D` is depth 3). The changed symbol is depth 0 and is never listed as impacted.

Cycles terminate: if `A → B → C → A` and `A` changes, the blast radius is `B` and `C`.

This is still static analysis. A symbol in the blast radius **can** be affected through the dependency chain. Ripple does not execute the project, so it does not prove that a given call will run or fail at runtime.

### Test impact

Ripple identifies tests that **may be affected** by a code change based on statically discovered dependencies. It does not predict test failures. It does not run PHPUnit, collect coverage, or perform mutation testing.

A symbol is a PHPUnit test only with deterministic evidence:

- The class directly or transitively extends `PHPUnit\Framework\TestCase` (including project base test classes). `PHPUnit\Framework\TestCase` itself does not need to be indexed.
- A method on that class is a test method if its name starts with `test`, or it has the `#[PHPUnit\Framework\Attributes\Test]` attribute.

Ripple does **not** treat a file as a test because it lives under `tests/` or because the class name ends with `Test`. Unresolved parents are not guessed.

Test impact reuses the existing blast radius. It does not rescan, reparse, or rebuild the graph.

- **Direct:** the test method has a reverse-dependency depth of 1 from a changed symbol.
- **Indirect:** the test method is reachable further in the blast radius. The reported depth is the shortest path from that changed symbol.

The same test is not listed twice for the same changed symbol. If one test depends on multiple changed symbols, JSON keeps one row per origin.

```text
Test impact:
  Direct:
    tests/Unit/ReservationServiceTest.php
      Tests\Unit\ReservationServiceTest::testUpdateStatus

  Indirect:
    tests/Feature/PaymentTest.php
      Tests\Feature\PaymentTest::testReservationPayment
        depth: 3
```

```json
{
  "test_impact": [
    {
      "changed_symbol": "App\\Services\\ReservationService::updateStatus",
      "test_symbol": "Tests\\Unit\\ReservationServiceTest::testUpdateStatus",
      "test_file": "tests/Unit/ReservationServiceTest.php",
      "depth": 1,
      "impact": "direct"
    }
  ]
}
```

Limitations:

- Only PHPUnit-style tests are detected. Pest is not supported.
- Trait methods are not copied onto the using test class; only methods declared on a recognized test class are catalogued.
- This is static analysis. An affected test is not guaranteed to fail, and an unlisted test is not guaranteed to be safe.

### Affected flows

Ripple also traces **downstream** dependency paths from changed symbols. This helps developers see which code the changed code relies on.

```text
A → B → C → D

If B changes:

Blast radius (who depends on B):
A

Affected flows (what B depends on):
B → C → D
```

Default maximum flow depth is **3** dependency edges after the changed symbol. Cycles terminate; if the same node is reachable through multiple paths, Ripple keeps the shortest deterministic path.

Flow types are generic and come only from existing dependency edges:

- `call_chain` — method_call, static_call
- `construction_chain` — constructor_call
- `inheritance_chain` — extends
- `contract_chain` — implements, trait_use
- `type_dependency_chain` — parameter_type, return_type, property_type, type_reference

This is intentionally framework-agnostic. Ripple does not infer database, queue, API, authentication, or external-service semantics from naming conventions. Those labels come only from explicit semantic annotations (see Semantic impact).

Current limitation: flows stop at the configured depth and do not reconstruct every alternate equal-length path to a shared node.

### Semantic impact

Ripple can classify symbols in the blast radius and in affected flows using **explicit** semantic annotations.

Meaning is deterministic. Ripple does **not** infer `database`, `queue`, API, authentication, or external-service semantics from class names, namespaces, filenames, or framework conventions.

Configure exact symbol IDs in `ripple.json` at the repository root:

```json
{
  "semantic_annotations": [
    {
      "symbol": "App\\Http\\Controllers\\ReservationController::update",
      "type": "api_entrypoint"
    },
    {
      "symbol": "App\\Repositories\\PaymentRepository::update",
      "type": "database_write"
    },
    {
      "symbol": "App\\Integrations\\StripeClient::charge",
      "type": "external_integration"
    }
  ]
}
```

Supported types: `api_entrypoint`, `database_read`, `database_write`, `queue`, `event`, `authentication`, `external_integration`.

If `ReservationService::updateStatus` changes:

- Blast radius (who depends on the change) can include `ReservationController::update` → `api_entrypoint`
- Affected flows (what the change depends on) can include `PaymentRepository::update` → `database_write`

The controller is upstream of the changed service, so it will not appear as a downstream affected flow.

Text output (omitted when there is nothing to report):

```text
Semantic impact:
  Blast radius:
    [api_entrypoint]
      App\Http\Controllers\ReservationController::update()

  Affected flows:
    [database_write]
      App\Repositories\PaymentRepository::update()
```

JSON always includes:

```json
{
  "semantic_impact": {
    "blast_radius": [
      {
        "symbol": "App\\Http\\Controllers\\ReservationController::update",
        "type": "api_entrypoint"
      }
    ],
    "affected_flows": [
      {
        "symbol": "App\\Repositories\\PaymentRepository::update",
        "type": "database_write"
      }
    ]
  }
}
```

If `ripple.json` is absent, analysis continues and semantic impact is empty. If the file exists but is invalid, analysis fails with a clear error. The file is read as JSON; it is never executed.

Wildcards, regexes, and namespace patterns are not supported yet. Framework adapters can add annotations from explicit framework APIs without coupling Ripple's core to that framework.

Current limitation: only exact symbol IDs are matched from JSON, and annotations never add nodes to the dependency graph.

### Laravel semantic adapter

Laravel support is **opt-in**. Ripple never assumes a repository is Laravel because of `artisan`, `composer.json`, or class names.

Enable it in `ripple.json`:

```json
{
  "framework": "laravel"
}
```

The adapter implements the same `SemanticAnnotationProvider` as explicit JSON annotations. Ripple core stays framework-agnostic. Laravel-specific rules live under `src/Analysis/Semantics/Laravel/`.

Classification is deterministic AST analysis of already-indexed PHP. Ripple does **not** execute Laravel, bootstrap the application, load `vendor/autoload.php` from the analyzed project, or use AI.

Class names alone are never enough. `PaymentRepository`, `UserController`, and `SendEmailJob` are not semantic evidence. A method is labeled `database_write` only when it calls a recognized Laravel/Eloquent/query-builder write API, `api_entrypoint` when the class extends `Illuminate\Routing\Controller` (including a project base controller that does) or a route explicitly references the action, and so on.

Explicit `semantic_annotations` still work and are combined with Laravel-generated facts. Duplicate `(symbol, type)` pairs collapse; Laravel cannot override an explicit annotation of the same pair.

Example: with `framework` set to `laravel`, a change to `ReservationService::updateStatus` can report:

```text
Semantic impact:
  Blast radius:
    [api_entrypoint]
      App\Http\Controllers\ReservationController::update()

  Affected flows:
    [database_write]
      App\Repositories\PaymentRepository::update()
```

Current limitation: well-known Laravel facade names such as `DB::` and `Auth::` (or `Illuminate\Support\Facades\...`) are recognized. Arbitrary wrappers are ignored unless they use those APIs.

### Git history

Ripple inspects Git history for files in the current working-tree diff. This includes every changed path, not only PHP files.

Churn is historical context: how often a file has changed, how many lines Git recorded as added or deleted, how many distinct Git author identities touched it, and when it last changed.

`commit_count` can contribute a **historical churn** risk factor. Lines added/deleted, contributors, and last-changed timestamps stay in the churn report and do not affect the score yet. Ripple does not predict bugs, blame contributors, or infer developer quality. Contributor counts use Git's own author identity (`Name <email>`).

Task 17 analyzes the complete available Git history. Time windows such as `--since 6.months` are not configured yet.

Current working-tree diff statistics (`+42 -8`) stay separate from historical churn. Newly added files with no commits return zero counts and `last_changed_at: null`. The current diff is not treated as history.

Renames report the **current** path. Ripple queries `git log --follow` for that path. `--follow` is issued per file because Git only applies it reliably to a single pathspec. If the current path has no history and the diff recorded a previous path, Ripple queries that previous path instead. It does not invent history. If rename following is unavailable, analysis continues with whatever Git returned.

Deleted files are not read from the working tree. Ripple queries Git history for the deleted path. If Git has no history, the result is zero counts and a null timestamp.

Binary files are included. Git does not provide line-based churn for binary files (`-` in numstat), so `lines_added` and `lines_deleted` are `0`. Analysis does not fail.

Every commit Git returns is counted, including merge commits. Line additions and deletions come from Git's numstat. Merge commits with empty numstat contribute zero lines. Ripple does not attempt sophisticated merge attribution.

Text output (omitted when there are no changed files):

```text
Git history:
  src/Services/ReservationService.php
    commits: 18
    lines added: 742
    lines deleted: 391
    contributors: 5
    last changed: 2026-09-14 13:42:10 UTC
```

JSON always includes `churn` for a successful analysis:

```json
{
  "churn": [
    {
      "file": "src/Services/ReservationService.php",
      "commit_count": 18,
      "lines_added": 742,
      "lines_deleted": 391,
      "contributors_count": 5,
      "last_changed_at": "2026-09-14T13:42:10+00:00"
    }
  ]
}
```

When a file has no history, `last_changed_at` is `null`.

### Risk factors

Ripple identifies deterministic characteristics that may increase the **potential** risk of a change.

Examples:

- large blast radius
- deep dependency chains
- high fan-in
- multiple dependency types
- multiple changed symbols
- historical churn (`commit_count` of changed files)

Each factor includes a severity, a human-readable explanation, and evidence (for example an affected-symbol count or a maximum depth).

Risk factors are **signals**, not proof of a runtime failure. They remain available separately from the overall score.

### Historical churn

Ripple can use Git history to identify changed files that have historically changed frequently.

This is historical data and only a risk signal. It does **not** mean the code is defective, that the current change will fail, or that a production incident will occur. Newly added files usually have little or no history and do not produce this factor. Only files in the current working-tree diff are considered.

Thresholds (complete Git history, `commit_count` only):

```text
< 10 commits     → no factor
10–24            → info
25–49            → warning
50+              → high
```

One `historical_churn` factor is produced for the whole change, using the highest severity among changed files that meet the threshold.

```text
⚠ Historical churn
   ReservationService.php has changed 31 times in Git history.
```

### Risk score

Ripple calculates a deterministic risk score from detected risk factors.

The score:

- is an integer between 0 and 100
- maps to Low (0–29), Medium (30–59), or High (60–100)
- is explainable through per-factor contributions
- does not use AI, coverage, or runtime execution
- may include historical Git churn as one explainable factor
- does not predict that a change will fail in production

Example:

```text
Risk score: 55/100 — Medium
```

This is a medium potential-impact score based on the detected factors. It is not a claim that the PR will break production.

Each contribution is the factor's maximum weight scaled by severity (`info` 50%, `warning` 75%, `high` 100%), rounded with half-up integer arithmetic.

Maximum weights sum to 100:

```text
high_fan_in                 20
large_blast_radius          20
deep_impact                 15
multiple_dependency_types   10
multiple_changed_symbols    10
historical_churn            25
```

Example: high fan-in warning (15) + large blast radius warning (15) + historical churn high (25) = 55, medium.

### Changed symbol detection

Ripple maps changed Git lines to PHP symbols using the AST.

It identifies the most specific changed symbol for each changed line.

Current limitation: deleted lines are not mapped to historical symbols yet.

Anonymous classes are skipped (including their methods) so they are not treated as named symbols.

### Dependency extraction

Ripple statically extracts deterministic dependencies between PHP symbols.

Examples include:

- method calls
- static calls
- constructor calls
- inheritance
- interfaces
- traits
- parameter types
- return types
- property types

### Dependency graph

Ripple converts extracted dependencies into a directed graph of unique symbol nodes and typed edges.

- Node identity is the existing symbol fully-qualified name (for example `App\Services\ReservationService::updateStatus`).
- All indexed project symbols become known nodes, including symbols with no edges.
- Distinct dependency types between the same pair remain distinct edges.
- If a target is not in the repository index, Ripple still keeps the edge and records an unindexed node (`known: false`).
- Graph output is sorted deterministically.

The graph does not execute application code. Impact is calculated from this static graph.

### Reverse dependency graph

Ripple indexes the same directed edges so you can ask which symbols **directly** depend on a given symbol.

For `A → B`, reverse lookup of `B` returns `A`. It does not walk further callers.

### Current Git limitations

- Only unstaged changes to **tracked** files (`git diff`) are treated as the current change set.
- Staged-only changes are not included in the diff.
- Untracked PHP files are indexed, but they are not treated as changed files.
- Deleted lines are not mapped onto historical/old-file symbols.
- The GitHub Action materializes the pull request against its base as a working-tree diff so the existing CLI can analyze it. There is no separate GitHub comparison engine.

## Requirements

- PHP 8.3+
- Composer
- Git

## Install dependencies

```bash
composer install
```

## Run the CLI

From inside a Git repository:

```bash
./bin/ripple --help
./bin/ripple analyze
./bin/ripple analyze --format=text
./bin/ripple analyze --format=json
./bin/ripple analyze --format=comment
```

Expected text output:

```text
🌊 Ripple

Repository index:
  PHP files: 24
  Symbols: 138
  Dependencies: 312

Changed files: 1

M src/Services/ReservationService.php
  +3

Changed symbols:

  App\Services\ReservationService::updateStatus()
    lines: 35, 36, 40

Direct impact:
  App\Services\PaymentService::validate()
    ← method_call

Blast radius:
  Depth 1:
    App\Services\PaymentService::validate()

  Depth 2:
    App\Repositories\PaymentRepository::update()

Test impact:
  Direct:
    tests/Unit/ReservationServiceTest.php
      Tests\Unit\ReservationServiceTest::testUpdateStatus

Risk score:
  24/100 — Low

Risk factors:

⚠ Deep dependency chain
   The change can reach impacted symbols up to 2 dependency levels away.

ℹ Historical churn
   ReservationService.php has changed 18 times in Git history.

Git history:
  src/Services/ReservationService.php
    commits: 18
    lines added: 742
    lines deleted: 391
    contributors: 5
    last changed: 2026-09-14 13:42:10 UTC

Dependencies:

  App\Services\ReservationService::updateStatus
    → App\Services\PaymentService::validate
      type: method_call
      line: 35

Dependency graph:
  Nodes: 138
  Edges: 312

Reverse dependencies:
  App\Services\PaymentService::validate
    ← App\Services\ReservationService::updateStatus [method_call]
```

Expected JSON output includes `direct_impact`, `blast_radius`, `test_impact`, `risk_factors`, `risk_score`, `affected_flows`, `semantic_impact`, and `churn` as separate fields. The `graph` is repository-wide.

```json
{
    "status": "ok",
    "changed_symbols": [
        {
            "fully_qualified_name": "App\\Services\\ReservationService::updateStatus"
        }
    ],
    "direct_impact": [
        {
            "changed_symbol": "App\\Services\\ReservationService::updateStatus",
            "impacted_symbol": "App\\Services\\PaymentService::validate",
            "dependency_type": "method_call",
            "lines": [12],
            "occurrences": 1
        }
    ],
    "blast_radius": [
        {
            "impacted_symbol": "App\\Services\\PaymentService::validate",
            "depth": 1,
            "origins": [
                {
                    "changed_symbol": "App\\Services\\ReservationService::updateStatus",
                    "depth": 1,
                    "dependency_type": "method_call",
                    "lines": [12],
                    "occurrences": 1
                }
            ]
        }
    ],
    "test_impact": [
        {
            "changed_symbol": "App\\Services\\ReservationService::updateStatus",
            "test_symbol": "Tests\\Unit\\ReservationServiceTest::testUpdateStatus",
            "test_file": "tests/Unit/ReservationServiceTest.php",
            "depth": 1,
            "impact": "direct"
        }
    ],
    "risk_factors": [
        {
            "code": "deep_impact",
            "severity": "warning",
            "title": "Deep dependency chain",
            "description": "The change can reach impacted symbols up to 2 dependency levels away.",
            "value": 2,
            "evidence": {
                "max_depth": 2
            }
        },
        {
            "code": "historical_churn",
            "severity": "info",
            "title": "Historical churn",
            "description": "ReservationService.php has changed 18 times in Git history.",
            "value": 18,
            "evidence": {
                "max_commit_count": 18,
                "affected_files": [
                    {
                        "file": "src/Services/ReservationService.php",
                        "commit_count": 18
                    }
                ]
            }
        }
    ],
    "risk_score": {
        "score": 24,
        "level": "low",
        "contributions": [
            {
                "code": "deep_impact",
                "title": "Deep dependency chain",
                "severity": "warning",
                "max_weight": 15,
                "contribution": 11
            },
            {
                "code": "historical_churn",
                "title": "Historical churn",
                "severity": "info",
                "max_weight": 25,
                "contribution": 13
            }
        ]
    },
    "affected_flows": [],
    "semantic_impact": {
        "blast_radius": [],
        "affected_flows": []
    },
    "churn": [
        {
            "file": "src/Services/ReservationService.php",
            "commit_count": 18,
            "lines_added": 742,
            "lines_deleted": 391,
            "contributors_count": 5,
            "last_changed_at": "2026-09-14T13:42:10+00:00"
        }
    ],
    "project_index": {
        "php_files": 24,
        "symbols": 138,
        "dependencies": 312
    }
}
```

If the current directory is not a Git repository, Ripple exits with a non-zero status and:

```text
Ripple could not analyze this directory:
not a Git repository.
```

### Docker

The image contains only Ripple. It does not copy or execute the project you want to analyze. Mount a Git repository and run the CLI against it:

```bash
docker build -t ripple .
docker run --rm -v "$PWD":/work -w /work ripple analyze
```

The GitHub Action uses the same image entrypoint. To reproduce the JSON run locally:

```bash
docker build -t ripple .
mkdir -p ripple-out
docker run --rm \
  -e GIT_CONFIG_COUNT=1 \
  -e GIT_CONFIG_KEY_0=safe.directory \
  -e GIT_CONFIG_VALUE_0=/workspace \
  -e GIT_OPTIONAL_LOCKS=0 \
  -v "$PWD:/workspace:ro" \
  -v "$PWD/ripple-out:/out" \
  -w /workspace \
  ripple \
  analyze --format=json --comment-file=/out/ripple-comment.md
```

The container already includes Ripple's Composer dependencies. It does not run `composer install`, `php artisan`, or any other commands from the mounted project.

## GitHub Actions

Ripple can run automatically on pull requests via [`.github/workflows/ripple.yml`](.github/workflows/ripple.yml).

The workflow checks out the pull request, builds the Ripple Docker image, and runs the same CLI used locally:

```bash
ripple analyze --format=json
```

It writes `ripple-result.json`, uploads that file as the `ripple-analysis` artifact, adds a GitHub Actions job summary, and posts or updates a single pull request comment.

The comment is a compact summary. It does not include source code, the full dependency graph, or long lists of affected symbols. Details stay in `ripple-result.json`.

Ripple keeps **one** active comment per pull request, identified by the exact marker `<!-- ripple-analysis -->`.

- First run creates the comment.
- Later commits and a reopened pull request update that same comment.
- If several comments contain the marker, Ripple updates the **oldest** matching comment and leaves the others unchanged. It does not delete extra comments.

Analysis and commenting are separate jobs. A GitHub comment failure is reported as `PR comment failure` and does not rewrite `ripple-result.json`. A Ripple analysis failure still fails the Analyze job.

The workflow uses `pull_request` (not `pull_request_target`). It requests `contents: read` and `pull-requests: write` so it can update one Ripple comment. It does not execute the analyzed application's code. It does not require an AI API key.

Example:

```text
🌊 Ripple — Code Impact Analysis

Risk: 67 / High

Changed symbols
────────────────────────
ReservationService::updateStatus()

Blast radius
────────────────────────
Depth 1: 3 symbols
Depth 2: 5 symbols
Depth 3: 2 symbols

Affected flows
────────────────────────
→ NotificationService
→ PaymentService
→ ReservationRepository

Test impact
────────────────────────
✓ 3 direct tests
⚠ 2 indirect tests

Historical churn
────────────────────────
ReservationService.php
18 commits

⚠ Risk factors
────────────────────────
High fan-in
Large blast radius
Deep dependency chain

See full analysis in the workflow artifact.
```

A high risk score does **not** fail the Analyze job. Analysis is informational.

## AI support

Ripple has an optional AI explanation layer.
AI is disabled by default.
The deterministic analysis engine does not depend on an AI provider.

AI only turns structured analysis results into human-readable explanations. It does not calculate blast radius, risk, flows, tests, or churn.

Ripple computes the risk score deterministically. Optional AI can explain that existing assessment. AI cannot change the score or risk level.

No real provider is included yet. `NullAIProvider` never makes an external request and never invents explanation text.

Normal `ripple analyze` never requires an API key and never calls AI:

```bash
ripple analyze
ripple analyze --format=json
```

To request an explanation after analysis, use:

```bash
ripple analyze --ai
```

`--ai` generates PR and risk explanations only when AI is enabled in `ripple.json` **and** a provider actually produces text. With the current no-op provider, analysis still succeeds and JSON stays unchanged. Generated JSON may include `ai_explanation` and/or `ai_risk_explanation`; those fields never appear when nothing was generated, and they never modify `risk_score`.

```json
{
  "ai": {
    "enabled": false
  }
}
```

The GitHub Action does not request AI explanations and does not use provider secrets.

Future tasks will add real providers.

## Run tests

```bash
composer test
```
