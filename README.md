# Ripple

Ripple is an AI-assisted code change impact analyzer. It helps developers understand what a change can affect before a pull request is merged.

## The problem

Code reviews often miss hidden blast radius: a small diff can touch shared symbols, downstream callers, and high-risk paths. Ripple will analyze Git diffs, parse source code, and produce a deterministic impact report so that risk is visible before merge.

## Current status

Task 02 — CLI foundation.

The `analyze` command accepts `--format=text` or `--format=json` and prints a structured readiness result. AST analysis, dependency graphs, risk detection, AI explanations, and GitHub integration are not implemented yet.

## Requirements

- PHP 8.3+
- Composer

## Install dependencies

```bash
composer install
```

## Run the CLI

```bash
./bin/ripple --help
./bin/ripple analyze
./bin/ripple analyze --format=text
./bin/ripple analyze --format=json
```

Expected text output (default):

```text
🌊 Ripple

Ripple is ready.
```

Expected JSON output:

```json
{
    "status": "ready",
    "message": "Ripple is ready."
}
```

### Docker

The image contains only Ripple. It does not copy or execute the project you want to analyze.

```bash
docker build -t ripple .
docker run --rm ripple --help
docker run --rm ripple analyze
```

## Run tests

```bash
composer test
```
