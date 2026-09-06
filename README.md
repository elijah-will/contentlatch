# ContentGuard

A WordPress plugin for content quality-control rules on [Advanced Custom Fields (ACF)](https://www.advancedcustomfields.com/) backed posts.

V1 validates and audits scalar/simple ACF fields using a domain rule engine that is independent of WordPress and ACF.

## Requirements

- PHP 8.1+
- WordPress 6.6+
- Advanced Custom Fields 6.0+ (Free or Pro). Pro is not required for V1.

## Development

```bash
composer install
composer test
```

Domain unit tests run without WordPress or ACF.

## Architecture

The rule engine lives in `includes/Domain` and has no WordPress, ACF, or database dependencies. WordPress and ACF adapters will be added in later phases.

## License

GPL-2.0-or-later
