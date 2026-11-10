# Release Impact

Compare two Git revisions of a Laravel application and list the changes that matter at deploy time: migrations, environment keys, Composer dependencies, and queue, scheduler and deployment files. Each finding comes with evidence (commit, path and line).

It is a review aid for developers and AI agents, not a verdict. Release Impact only reads committed Git objects. It never boots Laravel, runs project code, loads the project's dependencies, touches the working tree or uses the network.

## Requirements

- PHP 8.2 or later
- Git on `PATH`
- Composer

## Installation

```sh
# Global install
composer global require shaungsimpson/release-impact

# Or run without installing (https://cpx.dev)
cpx shaungsimpson/release-impact compare v1.0 v1.1

# Or from source
git clone https://github.com/shaungsimpson/release-impact.git
cd release-impact && composer install --no-dev
php bin/release-impact --help
```

## Usage

```sh
release-impact compare <base> <head> [--repo=<path>] [--format=text|json]
```

| Argument or option | Meaning |
|---|---|
| `<base>`, `<head>` | Any revision that resolves to a commit: a tag, branch, `HEAD~3` or a SHA. Only committed content is analysed; uncommitted changes are ignored. |
| `--repo=<path>` | The repository to analyse. Defaults to the current directory. Bare repositories work too. |
| `--format=json` | Machine-readable output (see [JSON output](#json-output)). The default is `text`. |
| `-h`, `--help` / `-V`, `--version` | Help and version. |

The command never asks for input. Reports go to stdout and diagnostics go to stderr.

```text
$ release-impact compare v1.0 HEAD
Release Impact: v1.0 (41b3a6843064) -> HEAD (dd380e6fc70f)

Migrations (1)
  added        database/migrations/2024_06_01_000000_add_status_to_orders.php

Config env() keys (2)
  added        BILLING_API_KEY  config/app.php:4
  undocumented BILLING_API_KEY  config/app.php:4  (not in head .env.example; review)

Composer dependencies (3)
  added        stripe/stripe-php  v15.0.0
  changed      laravel/framework  v11.9.0 -> v11.12.0
  changed      phpunit/phpunit  10.5.0 [dev] -> 11.2.0 [dev]

Queue, scheduler and deployment files (2)
  added        Procfile  [deployment]
  added        routes/console.php  [scheduler]

Limitations (1)
  config/app.php:5 @dd380e6fc70f: env() call without a literal key; the key cannot be determined statically.
```

## What it analyses

All paths are relative to the repository root, so the Laravel application must live at the root. In a monorepo with the app in a subdirectory, sections come back empty.

| Area | What is reported | What is not |
|---|---|---|
| Migrations | `.php` files under `database/migrations/` (any depth) that were added, modified, deleted or renamed. Renames come from Git rename detection. | Whether a migration is safe. File contents are not read. |
| Config `env()` keys | Literal keys in `env('KEY')`, `\env('KEY')` and `env(key: 'KEY')` across `config/**/*.php`, compared as a set between revisions and parsed with [nikic/php-parser](https://github.com/nikic/PHP-Parser). An added key missing from `.env.example` at head is flagged as `undocumented`, which is a prompt to review, not a defect. | `Env::get()`, `getenv()`, `$_ENV`, and `env()` outside `config/`. A key that only moves between config files is not reported. A non-literal key is recorded as a limitation. |
| `.env.example` keys | Key names on `KEY=` and `export KEY=` lines, skipping lines inside multi-line quoted values. | Values. No other `.env*` file is read. |
| Dependencies | `composer.lock` packages that were added, removed or changed (version, source reference or scope), split into runtime (`packages`) and dev (`packages-dev`). | `composer.json` constraints. |
| Operational files | Changes to the fixed path list below. `*` does not cross `/`. | What the change does at runtime. |

| Category | Paths |
|---|---|
| queue | `config/queue.php`, `config/horizon.php` |
| scheduler | `routes/console.php`, `app/Console/Kernel.php`, `bootstrap/app.php` |
| deployment | `Procfile`, `Dockerfile`, `Dockerfile.*`, `docker-compose*.y(a)ml`, `compose*.y(a)ml`, `Envoy.blade.php`, `deploy.php`, `supervisor*.conf`, `.github/workflows/*.y(a)ml` |

## JSON output

```json
{
  "schema_version": 1,
  "tool": "release-impact",
  "base": { "ref": "v1.0", "commit": "41b3a684306477743808826d539a1d515bc7097a" },
  "head": { "ref": "HEAD", "commit": "dd380e6fc70f1858fa276e71f39f5346261c390e" },
  "findings": [
    { "type": "migration.renamed", "path": "database/migrations/b.php", "from": "database/migrations/a.php", "similarity": 100 },
    { "type": "env.config_key.added", "key": "BILLING_API_KEY",
      "locations": [{ "revision": "dd380e6f…", "path": "config/app.php", "line": 4 }] },
    { "type": "dependency.changed", "package": "laravel/framework",
      "base": { "version": "v11.9.0", "reference": null, "scope": "runtime" },
      "head": { "version": "v11.12.0", "reference": null, "scope": "runtime" } }
  ],
  "limitations": [
    { "type": "config.dynamic_env_reference", "revision": "dd380e6f…", "path": "config/app.php", "line": 5,
      "message": "env() call without a literal key; the key cannot be determined statically." }
  ]
}
```

**Contract**
- `schema_version` changes only for breaking changes: a field or type removed or renamed, or a meaning changed. New finding types, limitation types and fields can be added without a version change, so ignore what you don't recognise.
- Ordering is deterministic. Items are sorted by `type`, then path, key or package, then `from`, `revision` and `line`, before any redaction is applied.
- `commit` and `revision` hold full SHAs. Paths are repository-relative and unquoted. Invalid UTF-8 is replaced with U+FFFD.

| Finding type | Fields |
|---|---|
| `migration.{added,modified,deleted,renamed}` | `path`, plus `from` and `similarity` (%) for renames |
| `env.config_key.{added,removed,undocumented}` | `key`, `locations[]` (`revision`, `path`, `line`) |
| `env.example_key.{added,removed}` | `key`, `locations[]` |
| `dependency.{added,removed,changed}` | `package`, `base` and `head`, each `null` or `{version, reference, scope}` |
| `operational_file.{added,modified,deleted,renamed}` | `path`, `category`, plus `from` and `similarity` for renames |

| Limitation type | Meaning |
|---|---|
| `config.dynamic_env_reference` | An `env()` call without a literal key |
| `config.parse_error` | A config file could not be parsed, so its keys were skipped |
| `env_example.missing` | `.env.example` is absent at that revision, so the comparison was skipped. If it is absent at head, `undocumented` findings are not reported |
| `composer_lock.missing`, `composer_lock.invalid` | The lock file is absent or invalid at that revision, so the dependency comparison was skipped |
| `composer_lock.invalid_entry` | A lock entry without a name was skipped |

Limitations from config files that are the same at both revisions are reported once, against head.

## Exit codes

| Code | Meaning |
|---|---|
| 0 | Report produced. Findings and limitations never change the exit code. |
| 1 | Internal error, or dependencies are not installed |
| 2 | Usage error |
| 3 | The path is missing or Git will not read it (not a repository, or "dubious ownership", with Git's reason printed), or a revision does not resolve to a commit |
| 4 | Git could not run, a Git command failed, or a file present in a commit could not be read |

A missing or unparsable file is never fatal. That part of the analysis is skipped, recorded under `limitations`, and the exit code stays 0. On any non-zero exit, stdout is empty.

## Security and privacy

The output is designed to be safe to pass to an AI agent.

1. **Values are never read into the report.** Only key names are taken from `.env.example`. Only the literal key is taken from `env()`, never the default. Only name, version, scope and commit reference are taken from `composer.lock`, never URLs. `.env`, `.env.*`, `auth.json`, file contents, and commit and tag messages are never output.
2. **Secret-shaped text is redacted.** Every output string and error message, including PHP notices, passes through a redactor. It catches:
   - known token formats: Stripe, GitHub, GitLab, Slack, AWS key IDs, Google API keys, JWTs, PEM blocks and Laravel `base64:` keys
   - `user:pass@` in URLs
   - hex strings of 32 or more characters
   - random-looking runs of 20 or more characters that mix digits, uppercase and lowercase letters

   Each match becomes `[REDACTED:<16 hex>]`, an HMAC-SHA256 with a random key that is generated for each run and never stored. The token cannot be reversed. It stays the same within one report and changes between runs. Git object IDs in `commit`, `revision` and `reference` are kept.

Pattern matching cannot recognise every secret, so layer 1 is the guarantee and layer 2 is the backstop for secrets placed where a name belongs, such as `env('sk_live_…')`.

Git runs without a shell and only uses read commands (`rev-parse`, `diff`, `ls-tree`, `cat-file`). It runs with optional locks off, prompts off, literal pathspecs, and inherited `GIT_DIR`/`GIT_WORK_TREE` ignored. The repository is never modified.

## Development

```sh
composer install
composer test
```

The tests build disposable Git repositories and run the real executable against them. They cover renames, odd and control-character file names, literal versus dynamic `env()`, `.env.example` quoting, runtime and dev dependency changes, missing, invalid and corrupt files, invalid revisions, non-Git paths, usage errors and deterministic JSON. A leak test plants canary values and secret-shaped names in every input source and checks that none of them appears on stdout or stderr. Every redaction pattern has a unit test, along with a list of ordinary names that must stay readable.

## Licence

MIT
