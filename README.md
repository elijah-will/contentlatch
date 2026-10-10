# ContentLatch

A WordPress plugin for content governance and validation on WordPress Core and [Advanced Custom Fields (ACF)](https://www.advancedcustomfields.com/) fields.

Define the content rules your site requires, then automatically validate content against those rules before publication.

See `readme.txt` for the full WordPress.org product description, supported fields, and uninstall behavior.

## Requirements

- PHP 8.1+
- WordPress 6.6+
- Advanced Custom Fields 6.0+ (Free or Pro) for ACF field validation only. WordPress Core field validation works without ACF. Repeater, Flexible Content, and Clone require ACF Pro.

## Development

```bash
composer install
composer test
```

Domain unit tests run without WordPress or ACF. The production ZIP should exclude development files listed in `.distignore`.

## Architecture

The rule engine lives in `includes/Domain` and has no WordPress, ACF, or database dependencies. WordPress and ACF adapters live under `includes/Infrastructure`.

## License

GPL-2.0-or-later
