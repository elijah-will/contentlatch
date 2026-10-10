# Contributing to ContentLatch

Thank you for your interest in contributing. ContentLatch is a WordPress plugin for content governance and validation on WordPress Core and [Advanced Custom Fields (ACF)](https://www.advancedcustomfields.com/) fields.

This guide covers local development, testing, and how to propose changes. Contributions are welcome through GitHub Issues, Discussions, and pull requests.

## Code of collaboration

- Be respectful and constructive in Issues, Discussions, and pull request reviews.
- Prefer small, focused changes with a clear purpose.
- Open a Discussion for larger feature ideas before investing in a large pull request.

## Requirements

Runtime (from the plugin headers and `README.md`):

- PHP 8.1 or newer
- WordPress 6.6 or newer
- ACF 6.0 or newer (Free or Pro) when working on ACF-backed features  
  Repeater, Flexible Content, and Clone require ACF Pro.  
  ACF is optional for WordPress Core field validation.

Development tooling (from `composer.json`):

- [Composer](https://getcomposer.org/)
- PHPUnit 10.5 (installed via Composer as a development dependency)

## Repository layout

| Path | Role |
| --- | --- |
| `contentlatch.php` | Plugin bootstrap |
| `includes/Domain` | Rule engine (no WordPress, ACF, or database dependencies) |
| `includes/Application` | Application services and presentation helpers |
| `includes/Infrastructure/WordPress` | WordPress adapters |
| `includes/Infrastructure/ACF` | ACF adapters |
| `includes/Admin` | Admin controllers and page wiring |
| `admin/` | Admin views, CSS, and JavaScript |
| `tests/Unit` | PHPUnit unit tests |
| `tests/Support` | Shared test helpers and stubs |
| `languages/` | Translation template (`.pot`) |
| `readme.txt` | WordPress.org plugin readme |
| `.distignore` | Files excluded from production ZIPs |

Keep Domain logic free of WordPress and ACF calls. Put WordPress- and ACF-specific code under `includes/Infrastructure`.

## Getting started

### 1. Fork and clone

1. Fork [elijah-will/contentlatch](https://github.com/elijah-will/contentlatch) on GitHub.
2. Clone your fork:

```bash
git clone https://github.com/<your-username>/contentlatch.git
cd contentlatch
```

3. Add the upstream remote (optional but recommended):

```bash
git remote add upstream https://github.com/elijah-will/contentlatch.git
```

### 2. Install development dependencies

```bash
composer install
```

This installs PHPUnit and generates the Composer autoloader under `vendor/`.

### 3. Run the plugin in WordPress (manual testing)

1. Place or symlink this repository under `wp-content/plugins/contentlatch` on a local WordPress 6.6+ site.
2. Activate **ContentLatch** in the WordPress admin.
3. Install ACF 6.0+ (Free or Pro) if you need to exercise ACF field validation.

The unit test suite does not boot WordPress. Use a local WordPress site for admin UI, editor save paths, and ACF integration checks.

## Running tests

From the repository root:

```bash
composer test
```

This runs PHPUnit using `phpunit.xml.dist` against `tests/Unit`.

Notes:

- Domain-focused tests run without WordPress or ACF.
- `tests/bootstrap.php` defines a minimal stub environment (`ABSPATH` and a few WordPress/ACF helper functions) so Application, Infrastructure, and Admin code can be unit-tested without a full WordPress install.
- If Composer dependencies are missing, PHPUnit exits and asks you to run `composer install`.

Please run `composer test` before opening a pull request and include or update tests for behavior changes whenever practical.

## Coding conventions

There is no PHPCS or WordPress Coding Standards config in this repository today. Match the existing style:

- PHP 8.1+ with `declare(strict_types=1);` on PHP files
- PSR-4 namespaces: `ContentLatch\` → `includes/`, `ContentLatch\Tests\` → `tests/`
- File docblocks use `@package ContentLatch`
- Prefer focused classes and keep Domain free of WordPress/ACF APIs
- User-facing strings should remain translation-ready (`__()`, `_x()`, and related helpers with the `contentlatch` text domain)

Do not commit `vendor/`, `.phpunit.cache/`, or other paths listed in `.gitignore`.

## Reporting bugs (GitHub Issues)

Use [GitHub Issues](https://github.com/elijah-will/contentlatch/issues) for reproducible bugs.

Please include:

- ContentLatch version
- WordPress version and PHP version
- ACF version (or note if ACF is not installed), when relevant
- Steps to reproduce
- Expected vs actual behavior
- Relevant screenshots, stack traces, or console/network errors

Search existing Issues first to avoid duplicates.

## Proposing features (GitHub Discussions)

Use [GitHub Discussions](https://github.com/elijah-will/contentlatch/discussions) for feature ideas, design questions, and open-ended conversation.

For larger changes, open a Discussion before submitting a pull request so the approach can be aligned early.

## Pull requests

`main` is protected and requires pull requests. Do not commit directly to `main`.

### Branching

1. Sync with upstream `main`.
2. Create a feature branch from `main`:

```bash
git checkout main
git pull upstream main
git checkout -b describe-your-change
```

Use a short, descriptive branch name (for example `fix-acf-notice` or `add-operator-docs`).

### What to include

- A clear description of the problem and the change
- Linked Issue or Discussion when one exists
- Tests covering new or changed behavior when practical
- Updates to `readme.txt` or other docs when user-facing behavior changes
- No unrelated refactors or formatting-only churn

### Submit the pull request

1. Push your branch to your fork.
2. Open a pull request against **`main`** on [elijah-will/contentlatch](https://github.com/elijah-will/contentlatch).
3. Fill in the PR description and note how you tested the change (`composer test`, local WordPress checks, and so on).

### Review and merge

Maintainers review pull requests for correctness, scope, test coverage, and fit with the architecture above. Feedback may request changes before merge. Merging into `main` is done by maintainers after approval.

## Releases and WordPress.org

WordPress.org packaging, version bumps for release, tagging, and SVN publishes are handled by the project maintainer. Contributors should not publish releases or upload plugin ZIPs to WordPress.org.

Production distributions exclude development files listed in `.distignore` (for example `tests/`, `vendor/`, and `bin/`).

## License

By contributing, you agree that your contributions are licensed under the same terms as the project: [GPL-2.0-or-later](license.txt).
