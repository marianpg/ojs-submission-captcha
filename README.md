# Submission Captcha

A generic plugin for OJS 3.5 that requires a captcha on the first page of the submission wizard (`/index.php/<journal>/submission`). No submission is created until the captcha has been solved, so automated accounts can no longer create draft submissions or upload files to them.

It supports both captchas that OJS 3.5 supports for login and registration, and uses their settings from the `[captcha]` section of `config.inc.php`:

- **reCAPTCHA v2** (Google), the "I'm not a robot" checkbox
- **ALTCHA**, open source and self-hosted, using the widget and library shipped with OJS

The plugin has no settings of its own.

## Requirements

- OJS 3.5
- For reCAPTCHA: **v2 checkbox** keys, the same type OJS uses for login and registration. v3 and invisible keys do not work.

## Configuration

In `config.inc.php`, one or both of:

```ini
[captcha]
recaptcha = On
recaptcha_public_key = <site key>
recaptcha_private_key = <secret key>

altcha = On
altcha_hmackey = <long random secret>
```

The plugin only takes effect when it is enabled in the journal (Website › Plugins › Generic Plugins) and at least one captcha is configured:

- reCAPTCHA: `recaptcha` is `On` and both keys are set. `recaptcha_enforce_hostname` is respected.
- ALTCHA: `altcha` is `On` and `altcha_hmackey` is set. `altcha_encrypt_number` sets the difficulty, as in OJS.

If both are configured, both have to be solved, as on the OJS registration form. `captcha_on_login`, `captcha_on_register` and the `altcha_on_*` settings do not matter. If nothing is configured, OJS behaves as if the plugin were not installed.

## Installation

Installing from the gallery or by upload requires the site administrator role.

- **Plugin gallery:** Website › Plugins › Plugin Gallery › Submission Captcha (once the plugin is listed).
- **Upload:** Website › Plugins › Upload A New Plugin, then select the release `.tar.gz`.
- **Manually:** extract the release into `plugins/generic/submissionCaptcha`, then run `php lib/pkp/tools/installPluginVersion.php plugins/generic/submissionCaptcha/version.xml`.

Then enable the plugin in each journal that should require the captcha.

## Behaviour

- The start form shows a required "Spam protection" field with the captcha below the other fields.
- The response is sent with the form to `POST /api/v1/submissions`. The server verifies it with OJS's own reCAPTCHA or ALTCHA validator before the submission is created. A missing or invalid response is rejected with an error on the field.
- reCAPTCHA: an expired or already used token is rejected and the checkbox is reset.
- ALTCHA: each challenge expires one hour after the page was loaded and is accepted only once (OJS itself accepts an ALTCHA solution repeatedly and without expiry). After a rejection the page has to be reloaded to get a new challenge. Used challenges are remembered in the OJS cache.
- Everyone has to solve the captcha, including editors and administrators. Creating submissions through the REST API with an API token is no longer possible while the plugin is active.
- With reCAPTCHA, if Google's verification service cannot be reached, new submissions are rejected (fail closed), just like OJS login with reCAPTCHA. ALTCHA does not depend on an external service.
- Only the start of a submission is protected. Later steps of the wizard and the final submit are not changed.

## Privacy

- **reCAPTCHA:** the widget is loaded from `www.recaptcha.net`, and Google receives data about the visitor. Journals should mention this in their privacy statement.
- **ALTCHA:** the widget is served by OJS itself, and no data is sent to third parties.

## Development

- `tests/conditions.php`: checks, in memory, when the plugin registers its hooks and how it validates a new submission, including solving, reusing, expired and tampered ALTCHA challenges. Run it from the OJS root: `php plugins/generic/submissionCaptcha/tests/conditions.php`.
- `tests/smoke.sh`: logs in as an author against a running OJS and checks that the start form contains the captcha fields and that the API rejects submissions without responses. See the comments in the script for the environment variables.
- `tools/package.sh`: builds the release `.tar.gz` for the plugin gallery from the committed state and prints its MD5.

For local testing, Google's test keys from the [reCAPTCHA FAQ](https://developers.google.com/recaptcha/docs/faq) accept every token.

## License

GNU General Public License v3, see [LICENSE](LICENSE).
