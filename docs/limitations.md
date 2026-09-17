# Limitations

Ripple is a static impact analyzer. These limits are intentional unless noted as future product work.

## Static analysis is not runtime proof

A high blast radius or high risk score means *potential* static reach, not that production will break. Unreachable runtime branches, feature flags, and dynamic dispatch that PHP cannot see are out of scope.

## Unresolved dependencies stay unknown

Names that are not defined in the indexed tree (framework classes, Composer packages, missing files) become `known: false` graph nodes. Ripple does not download packages or guess missing methods.

## PHP type resolution is limited

The extractor understands ordinary names, imports, and common call/type edges. Generics, `class-string<T>`, conditional types, and many dynamic expressions are not fully resolved. Prefer unknown over a fabricated edge.

## Working-tree diff only

Local analysis uses unstaged `git diff`. Staged-only changes and untracked files (without intent-to-add) are omitted. Deleted symbols are not reconstructed from Git history; deleted PHP files yield unmapped deleted lines.

## Anonymous classes

Anonymous classes are skipped and are not symbols.

## PHPUnit only

Test impact requires subclasses of `PHPUnit\Framework\TestCase` and `test*` methods or `#[Test]`. Pest, Codeception, and coverage data are not used. Tests are never executed.

## Affected-flow depth

Downstream flow paths are truncated at depth 3.

## Laravel adapter is conservative

The adapter matches known Laravel APIs on the AST. It does not boot the application, read config/cache, or label symbols merely because they *sound* like controllers or jobs. Unrecognized Laravel usage stays unlabeled.

## JSON includes the full graph

`graph` and `reverse_graph` are complete. Reports can be several megabytes. Compact JSON is not implemented.

## AI is optional and vendor-specific

OpenAI is implemented via `OpenAIProvider` and the Responses API. CodeCraft is implemented via `CodeCraftProvider` and the Chat Completions API. Other vendors are not supported. `NullAIProvider` is used when AI is disabled.

`--ai` plus `ripple.json` (`enabled`, `provider`, and `model`) plus `RIPPLE_AI_API_KEY` are required for generated text. CodeCraft also accepts optional `base_url` (default `https://www.codecraftapi.com/v1`). Missing configuration or provider errors omit AI sections; they do not fail deterministic analysis.

Requests send structured analysis facts, not the full source tree. Those facts still leave the machine when a provider is used.

There is no streaming, tool calling, web search, or automatic retry. The HTTP timeout defaults to 30 seconds. Optional `ai.timeout_seconds` must be a positive JSON integer (not a float such as `30.0`).

PHPUnit never calls OpenAI or CodeCraft. Do not add tests that require a real `RIPPLE_AI_API_KEY`. Local `.env` is optional, gitignored, and must not be committed. It does not change GitHub Action behavior.

The GitHub Action does not enable `--ai` and must not be given an AI secret.

## GitHub Action builds this repository

`.github/workflows/ripple.yml` `docker build`s the checked-out tree. It is not a reusable marketplace action and does not publish an image for arbitrary app repos.

## Scoring is a heuristic

Factor weights are fixed. They are not empirically calibrated to incident rates. Do not treat the score as a probability.

## Why keep these limits

They keep Ripple deterministic, explainable, and safe to run on untrusted pull requests: no application execution, no package install of the target app, no network for analysis, no invented dependencies.
