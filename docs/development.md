# Development

## Requirements

- PHP 8.3+
- Composer
- Git (analysis and many tests spawn `git`)
- PHPUnit 11 (via `composer install`)

`composer.json` pins `platform.php` to `8.3.0`. Runtime dependencies: `nikic/php-parser`, `symfony/console`.

## Setup

```bash
composer install
```

## Tests

The only Composer script is `test`:

```bash
composer test
```

That runs PHPUnit with `phpunit.xml` (`tests/Unit` and `tests/Integration`).

GitHub Actions workflow `.github/workflows/ci.yml` runs `composer validate --strict`, `composer install`, and `composer test` on PHP 8.3.

Temporary Git repositories used in tests live under the system temp directory, not `tests/Fixtures`.

## Project structure

```text
bin/ripple                 CLI entry
src/AI/                    Provider interface, NullAIProvider, OpenAIProvider, CodeCraftProvider, explanations, test recommendations
src/AI/Http/               Minimal HTTP client used only by AI providers
src/Analysis/              Runner, AST, index, graph, impact, risk, flows, semantics, PHPUnit impact
src/Analysis/Semantics/Laravel/  Optional Laravel AST adapter
src/CLI/                   Symfony command + application factory
src/Git/                   Diff + history
src/GitHub/                PR comment selector (marker + oldest-match)
src/Reporting/             text, JSON, compact PR comment
tests/Unit/
tests/Integration/
tests/Fixtures/            PHP snippets and recorded diffs
tests/Support/             Temporary Git repos, AI test doubles
.github/workflows/ci.yml       PHPUnit + Composer validation
.github/workflows/ripple.yml   PR impact analysis
Dockerfile
docs/
```

## Adding a new analyzer

Keep work inside `AnalysisRunner` as another step that **consumes existing results**. Do not rescan the tree or re-parse PHP.

Typical pattern:

1. Pure domain types (readonly value objects).
2. An analyzer class that takes graphs/index/diff already built.
3. Store the result on `AnalysisResult`.
4. Teach formatters to print it.
5. Unit tests with constructed graphs; one integration test through `./bin/ripple` if the CLI contract changes.

Do not import `Ripple\AI` from analysis code.

## Adding a semantic adapter

Implement `Ripple\Analysis\Semantics\SemanticAnnotationProvider`.

Laravel is the reference: `LaravelSemanticAnnotationProvider` reads `RepositoryIndex` AST, emits `SemanticAnnotation` values, and is attached only when `ripple.json` has `"framework": "laravel"`.

Wire new adapters in `ConfiguredSemanticAnnotationProvider` the same way. Prefer “leave unlabeled” over guessing from class names.

## Adding an AI provider

Implement `Ripple\AI\AIProvider`:

```php
public function generate(AIRequest $request): AIResponse;
```

Return `AIResponse::generated($text)` or `AIResponse::none()`. Throw `AIProviderException` on provider failure; the CLI will omit that AI section.

Do not put HTTP, API keys, or vendor SDKs in the deterministic layers. Request builders already serialize analysis facts; providers should not parse the repository.

`ApplicationFactory` constructs `ConfiguredAIProvider`, which resolves `NullAIProvider`, `OpenAIProvider`, or `CodeCraftProvider` only when `--ai` actually calls `generate()`.

API keys come from `RIPPLE_AI_API_KEY`, never from `ripple.json`. For local development, copy `.env.example` to `.env` (gitignored). `.env` fills missing environment variables only; a key already set in the process wins. PHPUnit uses `FakeAIHttpClient`; do not make live OpenAI or CodeCraft calls in CI.

Optional local smoke test (not part of `composer test`):

```bash
cp .env.example .env
# set RIPPLE_AI_API_KEY in .env, or:
export RIPPLE_AI_API_KEY="..."
./bin/ripple analyze --ai
```

## Conventions

- PHP 8.3, `declare(strict_types=1);`, readonly value objects where practical.
- Deterministic ordering (`SORT_STRING` / explicit `usort`) on anything that reaches JSON.
- Errors meant for operators go to stderr from the command.

## Contributing

Fork, branch, add tests, run `composer test`, update docs if flags or JSON change, open a PR. Keep analysis AI-independent.
