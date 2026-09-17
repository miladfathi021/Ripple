# Architecture

Ripple is a one-shot CLI analyzer. There is no service container, database, or background worker.

## Layers

```text
CLI            Command parsing, --ai opt-in, exit codes
     │
     ▼
AnalysisRunner Orchestrates one analysis pass
     │
     ├── Git           Working-tree diff, file history
     ├── AST           nikic/php-parser → symbols
     ├── Index         Repository PHP scan (once)
     ├── Graph         Forward + reverse dependency graphs
     ├── Impact        Direct impact, blast radius
     ├── Flow          Affected downstream paths
     ├── Risk          Factors + 0–100 score
     ├── Semantics     ripple.json + optional Laravel adapter
     ├── History       Churn from git log
     └── Testing       PHPUnit symbol detection + test impact
               │
               ▼
        AnalysisResult
               │
               ├── Reporting     text / json / PR comment
               └── AI            optional interpretation
GitHub         Workflow + comment selection (not used by analysis)
```

### CLI

`bin/ripple` boots `ApplicationFactory`, which wires `AnalysisRunner`, formatters, and three AI services that share `NullAIProvider`. `analyze` is the default command.

`ConfiguredSemanticAnnotationProvider` is CLI composition: it loads `ripple.json` and optionally attaches the Laravel provider. Analysis core does not import Laravel unless that provider is constructed.

### Analysis

`AnalysisRunner` is the only orchestrator. It:

1. Reads the working-tree diff.
2. Builds the repository index (parse + extract dependencies per PHP file).
3. Builds the forward graph, then the reverse graph from it (no second scan).
4. Maps the diff onto indexed symbols.
5. Computes direct impact, blast radius, and test impact from those graphs.
6. Loads Git history for changed files.
7. Computes risk factors and the score.
8. Computes affected flows on the **forward** graph.
9. Applies semantic labels to blast-radius and flow symbols.

Failures (not a Git repo, parse error, duplicate FQN, invalid semantic JSON, Git history error) become `AnalysisResult` with `status: error`. They do not throw through the CLI.

### Git

`GitRepository` runs `git` via `proc_open` with an argument array (no shell string), `GIT_TERMINAL_PROMPT=0`, and `GIT_OPTIONAL_LOCKS=0`.

Diff: `git diff --no-color --no-ext-diff --find-renames`.  
History: `git log --pretty=tformat:… --numstat --follow -- -- <path>`.

### Graph identity

Node id is the symbol FQN (`Class`, `Class::method`, or function name). Duplicate node ids collapse; a known definition replaces an unknown placeholder. Edges are unique on `(source, target, type)`.

`A → B` means A depends on B. Reverse graph incoming edges to B are dependents of B.

### Impact

Direct impact: reverse-graph neighbors of changed symbols, excluding the changed symbol itself.

Blast radius: BFS over reverse edges from each changed origin; shortest depth; cycles stop via a visited set.

Affected flows: BFS over **forward** edges from each changed origin, truncated at depth 3, emitting one path per shortest-path tree leaf.

These three views are separate on purpose.

### Risk

Rules emit at most one factor per code. `RiskScoreCalculator` rejects duplicate or unknown codes. Severity scales the factor’s max weight (info ≈ 50%, warning ≈ 75%, high = 100%). The score is `min(100, sum)`. Levels: 0–29 low, 30–59 medium, 60–100 high.

### Semantics

`SemanticAnnotationProvider` returns a list of `{symbolId, type}`. JSON config and Laravel both implement it. `SemanticImpactAnalyzer` only labels symbols that already appear in blast radius or affected flows.

Laravel rules inspect AST call facts and inheritance already extracted from the index. They do not execute Laravel.

### Reporting

Formatters receive `AnalysisResult` plus optional AI value objects. They never call `AIProvider`.

### GitHub

`PullRequestCommentSelector` encodes the “oldest matching `<!-- ripple-analysis -->`” rule used by the workflow’s JavaScript. Analysis does not call the GitHub API.

## Dependency direction

```text
deterministic analysis  →  AnalysisResult  →  reporters
                                         →  optional AI
```

Packages under `Analysis/`, `Git/` (except being used by analysis), and risk/flow/test code must not import `Ripple\AI`, `Ripple\CLI`, or `Ripple\Reporting`.

AI request builders read result objects only. They do not parse PHP, walk the graph, or recompute scores.

Framework adapters depend on `SemanticAnnotationProvider` and the index. Laravel types do not leak into graph or risk calculation.

## AI architecture

```text
AnalysisResult
      │
      ├── AIPrExplanationService
      ├── AIRiskExplanationService
      └── AITestRecommendationService
                    │
                    ▼
               AIProvider::generate(AIRequest): AIResponse
```

Three services exist so a PR summary, a risk narrative, and test advice can succeed or fail independently. The CLI catches `AIProviderException` per call.

`AIProvider` is the extension point. `NullAIProvider` returns `AIResponse::none()`.

## Stability

Graph node/edge getters sort by id. Index file lists are sorted. JSON object key order follows the formatter. Repeated analysis of the same tree should not depend on hash-map iteration order.
