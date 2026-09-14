=== ContentGuard ===
Contributors: contentguard
Tags: acf, validation, content audit, quality control, content governance
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 8.1
Requires Plugins: advanced-custom-fields
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Define the content rules your site requires, then automatically validate content against those rules before publication.

== Description ==

ContentGuard is a WordPress content governance and validation plugin. Site administrators define rules for WordPress Core and Advanced Custom Fields (ACF) fields, then ContentGuard evaluates content against those rules when editors save or publish.

Define the content rules your site requires, then automatically validate content against those rules before publication.

ContentGuard is not an AI tool, SEO scanner, malware scanner, spell checker, or content generator. It enforces the rules you configure.

= How it works =

1. Create a rule for a post type.
2. Optionally choose when the rule applies (a WHEN condition).
3. Optionally choose what must be true (a THEN validation).
4. Choose severity: Blocking or Warning.
5. ContentGuard evaluates content during editing and save.
6. Blocking findings prevent publishing or updating published/private content.
7. Warnings alert the editor but do not block publication.
8. Run an Audit to evaluate existing published and private content against your active rules.

A rule needs at least a WHEN condition or a THEN validation. You may create a condition-only rule (WHEN without THEN). When that condition matches, the rule itself becomes the finding. That is useful for governance rules such as prohibited terminology.

= WordPress Core fields =

* Title
* Content
* Excerpt
* Slug
* Featured Image
* Author

= ACF field types =

Supported scalar types:

* Text, Textarea, Number, Range
* Email, URL, Password
* WYSIWYG
* Select (single), Radio, Button Group, True/False
* Date Picker, Date Time Picker
* Color Picker

Supported structures:

* Group → scalar
* Repeater → scalar
* Nested Repeater → scalar (up to two Repeater levels)
* Flexible Content → scalar, and Flexible Content → Group → scalar
* Clone → scalar, and Clone → Group → scalar (seamless or group display)
* Clone inside Repeater or Flexible Content
* Repeater → Clone → scalar

Not supported in V1 (examples): Relationship, Post Object, Page Link, Taxonomy, User, Checkbox, Gallery, Image, File, Link, Google Map, oEmbed, multi-select Select, deeper than two Repeater levels, Repeater → Group, Repeater inside Flexible Content, nested Flexible Content, and Clone → Clone / Repeater / Flexible Content.

Requires Advanced Custom Fields 6.0 or higher (Free or Pro). Repeater, Flexible Content, and Clone require ACF Pro.

= Conditions (WHEN) =

* Equals / Not equals
* Empty / Not empty
* Contains / Does not contain
* Greater than / Greater than or equal
* Less than / Less than or equal

Contains and does not contain are WHEN conditions only. They are not THEN validators.

Numeric comparisons apply to number and range fields. Date fields support equals, not equals, empty, and not empty.

= Validations (THEN) =

* Required
* Minimum length
* Maximum length
* Allowed values

= Severity =

* **Blocking** — Prevents publishing or updating content when the rule fails. In V1, blocking applies to publish and private update flows. Drafts, autosaves, and revisions are not blocked.
* **Warning** — Shows an editor notice but does not prevent publishing.

= Editors and save paths =

* Classic Editor
* Block editor (Gutenberg)
* ACF save validation
* WordPress Core field validation

Blocking and warning behavior is available on these supported paths. ContentGuard does not add WooCommerce-specific rules, GraphQL support, or custom Gutenberg block-level rule building beyond the Core and ACF fields listed above.

= Content Audit =

Audit evaluates existing published and private content against your active rules.

* Results summarize content that passed, needs attention, or needs review.
* Findings identify the affected field and rule.
* Repeater findings retain row context.
* Completed audit runs can be reviewed historically.
* Large sites may take longer because audits process content in batches.

Audit reports issues. It does not change your content.

= Permissions =

Managing rules and running audits requires the ContentGuard management capability (granted to administrators on activation). Editors are validated when they save content they can edit. Blocking rules prevent non-compliant content from being published or updated on the supported save paths.

= Privacy =

ContentGuard does not send plugin data to external services. Rules, audit results, and validation run on your WordPress site.

= Uninstall / data removal =

Deleting (uninstalling) ContentGuard removes its stored plugin data from the site, including:

* All ContentGuard rules
* Audit runs and findings (custom database tables)
* The ContentGuard management capability

Deactivating the plugin without deleting it keeps this data.

== Installation ==

1. Install and activate Advanced Custom Fields 6.0 or higher (Free or Pro, as needed for your field types).
2. Install ContentGuard from the WordPress Plugins screen, or upload the plugin folder to `/wp-content/plugins/contentguard`.
3. Activate ContentGuard through the Plugins screen.
4. Open **ContentGuard** in the admin menu to create rules and run audits.

== Frequently Asked Questions ==

= Does ContentGuard require ACF Pro? =

ACF 6.0 or higher is required. Free ACF covers supported scalar fields and Groups. Repeater, Flexible Content, and Clone require ACF Pro.

= Can I create a rule with only a WHEN condition? =

Yes. When the condition matches, the rule becomes the finding. Use Blocking or Warning severity as needed.

= Does Audit change my content? =

No. Audit only reports findings against your active rules.

= What happens when I delete the plugin? =

Uninstalling removes ContentGuard rules, audit data, and the ContentGuard management capability. Deactivation alone does not remove that data.

= Does ContentGuard work with the block editor? =

Yes. ContentGuard validates supported Core and ACF fields in both the Classic Editor and the block editor on the supported save paths.

== Changelog ==

= 1.0.0 =
* Initial public release.
* Rule-based content validation for WordPress Core and ACF fields.
* WHEN conditions and THEN validations, including condition-only rules.
* Blocking and warning severity.
* Classic Editor and block editor validation.
* ACF Group, Repeater (including two-level nesting), Flexible Content, and Clone support as documented above.
* Site-wide content audits with historical review and Repeater row context.
* Uninstall removes ContentGuard rules, audit data, and capability.
