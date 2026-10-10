# ContentLatch

[Website](https://contentlatch.com) · [Documentation](https://contentlatch.com/docs/) · [WordPress.org](https://wordpress.org/plugins/contentlatch/) · [GitHub](https://github.com/elijah-will/contentlatch)

ContentLatch is a WordPress plugin for **content governance and validation**. Administrators define rules for WordPress Core fields and optional [Advanced Custom Fields (ACF)](https://www.advancedcustomfields.com/) fields. ContentLatch evaluates those rules when editors save or publish, and can audit existing published and private content.

It is not an AI tool, SEO scanner, malware scanner, spell checker, or content generator. It enforces the rules you configure.

## Features

- **WHEN / THEN rules** — Optional conditions (WHEN) and validations (THEN), including condition-only rules
- **WordPress Core fields** — Title, Content, Excerpt, Slug, Featured Image, and Author
- **Optional ACF support** — Supported scalar types plus Group; Repeater, Flexible Content, and Clone with ACF Pro (see documentation for limits)
- **Save-time validation** — Classic Editor and block editor on supported Core and ACF save paths
- **Blocking and Warning severity** — Blocking can prevent publish / published-or-private updates; Warnings notify without blocking
- **Content Audit** — Batch evaluation of existing published and private content, with findings and Repeater row context
- **On-site only** — Rules, validation, and audits run on your WordPress site; the plugin does not send plugin data to external services

For supported operators, validators, field limits, and unsupported ACF types, see [`readme.txt`](readme.txt) or the [documentation](https://contentlatch.com/docs/).

## Requirements

- WordPress 6.6 or newer
- PHP 8.1 or newer
- Advanced Custom Fields 6.0+ (Free or Pro) **only** for ACF field validation
- ACF Pro for Repeater, Flexible Content, and Clone

WordPress Core field validation works without ACF.

## Installation

The recommended way to install ContentLatch is from [WordPress.org](https://wordpress.org/plugins/contentlatch/):

1. In WordPress Admin, go to **Plugins → Add New**.
2. Search for **ContentLatch**, then install and activate it.
3. For ACF field validation, install and activate Advanced Custom Fields 6.0 or higher (Free or Pro, as needed for your field types).

**Manual alternative:** upload a plugin ZIP via **Plugins → Add New → Upload Plugin**, or place the plugin folder at `/wp-content/plugins/contentlatch`, then activate ContentLatch.

Full installation notes: [Installation documentation](https://contentlatch.com/docs/getting-started/installation/).

## Getting Started

1. Open **ContentLatch → Rules** in the WordPress admin.
2. Choose **Add New Rule** and select a post type.
3. Optionally add WHEN conditions and THEN validations (at least one of either is required).
4. Choose **Blocking** or **Warning** severity, save, and keep the rule active.
5. Edit a matching post and try publishing to confirm the rule behaves as expected.

Detailed walkthroughs: [Creating Your First Rule](https://contentlatch.com/docs/rules/creating-your-first-rule/) and the [documentation index](https://contentlatch.com/docs/).

## Development

```bash
composer install
composer test
```

- `composer install` installs PHPUnit and generates the Composer autoloader.
- `composer test` runs the PHPUnit suite in `tests/Unit` (see `phpunit.xml.dist`).
- Domain unit tests run without WordPress or ACF. `tests/bootstrap.php` provides a minimal stub environment for other unit tests.
- Production ZIPs exclude development files listed in [`.distignore`](.distignore).

Architecture note: the rule engine lives in `includes/Domain` and has no WordPress, ACF, or database dependencies. WordPress and ACF adapters live under `includes/Infrastructure`.

Contributor setup, branching, and pull request expectations: [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

See [SECURITY.md](SECURITY.md) for supported versions and reporting guidance.

Report security vulnerabilities through [GitHub private vulnerability reporting](https://github.com/elijah-will/contentlatch/security/advisories/new). Do not disclose vulnerabilities in public Issues or Discussions.

## License

ContentLatch is licensed under [GPL-2.0-or-later](license.txt).
