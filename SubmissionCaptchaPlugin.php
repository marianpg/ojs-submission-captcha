<?php

/**
 * @file plugins/generic/submissionCaptcha/SubmissionCaptchaPlugin.php
 *
 * Copyright (c) 2026 Marian P. Gajda
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class SubmissionCaptchaPlugin
 *
 * @brief Requires a captcha on the start page of the submission wizard,
 *   using the reCAPTCHA or ALTCHA settings from the [captcha] section of
 *   config.inc.php.
 */

namespace APP\plugins\generic\submissionCaptcha;

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\ChallengeOptions;
use AltchaOrg\Altcha\Hasher\Algorithm;
use APP\core\Application;
use APP\plugins\generic\submissionCaptcha\classes\FieldAltcha;
use APP\plugins\generic\submissionCaptcha\classes\FieldRecaptcha;
use APP\template\TemplateManager;
use DateTimeImmutable;
use Exception;
use Illuminate\Support\Facades\Cache;
use PKP\components\forms\FormComponent;
use PKP\components\forms\submission\StartSubmission;
use PKP\config\Config;
use PKP\facades\Locale;
use PKP\form\validation\FormValidatorAltcha;
use PKP\form\validation\FormValidatorReCaptcha;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class SubmissionCaptchaPlugin extends GenericPlugin
{
    /** Name of the form field and request parameter carrying the reCAPTCHA response token */
    public const RECAPTCHA_FIELD = 'recaptcha';

    /** Name of the form field and request parameter carrying the solved ALTCHA payload */
    public const ALTCHA_FIELD = 'altcha';

    /** Seconds an ALTCHA challenge stays valid after the start page was loaded */
    public const ALTCHA_LIFETIME = 3600;

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (Application::isUnderMaintenance()) {
            return $success;
        }
        if ($success && $this->getEnabled($mainContextId) && ($this->isRecaptchaConfigured() || $this->isAltchaConfigured())) {
            Hook::add('TemplateManager::display', $this->addScript(...));
            Hook::add('Form::config::before', $this->addFields(...));
            Hook::add('Submission::validate', $this->validateCaptcha(...));
        }
        return $success;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.submissionCaptcha.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.submissionCaptcha.description');
    }

    /**
     * Whether reCAPTCHA is switched on and both keys are set in config.inc.php
     */
    public function isRecaptchaConfigured(): bool
    {
        return Config::getVar('captcha', 'recaptcha')
            && Config::getVar('captcha', 'recaptcha_public_key')
            && Config::getVar('captcha', 'recaptcha_private_key');
    }

    /**
     * Whether ALTCHA is switched on and its HMAC key is set in config.inc.php
     */
    public function isAltchaConfigured(): bool
    {
        return Config::getVar('captcha', 'altcha')
            && Config::getVar('captcha', 'altcha_hmackey');
    }

    /**
     * Load the field components on the start page of the submission wizard
     *
     * @hook TemplateManager::display [[$templateMgr, &$template, &$output]]
     */
    public function addScript(string $hookName, array $args): bool
    {
        /** @var TemplateManager $templateMgr */
        [$templateMgr, $template] = $args;
        if ($template !== 'submission/start.tpl') {
            return Hook::CONTINUE;
        }

        // Must load after the UI library (build.js, STYLE_SEQUENCE_LATE),
        // which provides pkp.registry
        $request = Application::get()->getRequest();
        $templateMgr->addJavaScript(
            'submissionCaptchaFields',
            $request->getBaseUrl() . '/' . $this->getPluginPath() . '/js/CaptchaFields.js',
            [
                'priority' => TemplateManager::STYLE_SEQUENCE_LAST,
                'contexts' => 'backend',
            ]
        );

        return Hook::CONTINUE;
    }

    /**
     * Append the configured captcha fields to the form that starts a new submission
     *
     * @hook Form::config::before [[$form]]
     */
    public function addFields(string $hookName, FormComponent $form): bool
    {
        if (!$form instanceof StartSubmission) {
            return Hook::CONTINUE;
        }

        $fieldArgs = [
            'groupId' => StartSubmission::GROUP,
            'isRequired' => true,
            'value' => '',
        ];

        // With both captchas, name the type so the two fields can be told apart
        $withType = $this->isRecaptchaConfigured() && $this->isAltchaConfigured();
        $label = fn (string $type) => $withType
            ? __('plugins.generic.submissionCaptcha.field.labelWithType', ['type' => $type])
            : __('plugins.generic.submissionCaptcha.field.label');

        if ($this->isRecaptchaConfigured()) {
            $form->addField(new FieldRecaptcha(self::RECAPTCHA_FIELD, $fieldArgs + [
                'label' => $label('reCAPTCHA'),
                'siteKey' => Config::getVar('captcha', 'recaptcha_public_key'),
                'recaptchaLocale' => str_replace('_', '-', Locale::getLocale()),
            ]));
        }

        if ($this->isAltchaConfigured()) {
            $form->addField(new FieldAltcha(self::ALTCHA_FIELD, $fieldArgs + [
                'label' => $label('ALTCHA'),
                'challenge' => $this->createAltchaChallenge(),
                'scriptUrl' => Application::get()->getRequest()->getBaseUrl() . '/lib/pkp/js/lib/altcha/altcha.i18n.js',
            ]));
        }

        return Hook::CONTINUE;
    }

    /**
     * Verify the captcha responses before a new submission is created
     *
     * Only runs when a submission is added (no existing submission), so
     * editing a submission later in the wizard is not affected.
     *
     * @hook Submission::validate [[&$errors, $submission, $props, $allowedLocales, $primaryLocale]]
     */
    public function validateCaptcha(string $hookName, array $args): bool
    {
        $errors = &$args[0];
        $submission = $args[1];
        if ($submission !== null) {
            return Hook::CONTINUE;
        }

        $request = Application::get()->getRequest();

        if ($this->isRecaptchaConfigured()
            && !$this->isValidRecaptcha($request->getUserVar(self::RECAPTCHA_FIELD), $request->getRemoteAddr(), $request->getServerHost())
        ) {
            $errors[self::RECAPTCHA_FIELD] = [__('common.captcha.error.missing-input-response')];
        }

        if ($this->isAltchaConfigured()
            && !$this->isValidAltcha($request->getUserVar(self::ALTCHA_FIELD), $request->getRemoteAddr())
        ) {
            $errors[self::ALTCHA_FIELD] = [__('plugins.generic.submissionCaptcha.altcha.error')];
        }

        return Hook::CONTINUE;
    }

    /**
     * Verify a reCAPTCHA response token with the validator of OJS
     */
    public function isValidRecaptcha(mixed $response, string $ip, ?string $hostname): bool
    {
        try {
            FormValidatorReCaptcha::validateResponse(is_string($response) ? $response : null, $ip, $hostname);
            return true;
        } catch (Exception $exception) {
            return false;
        }
    }

    /**
     * Verify a solved ALTCHA payload with the validator of OJS
     *
     * OJS itself accepts a solution as often as it is sent. Here each
     * challenge counts only once, so a single solution cannot be reused to
     * create many submissions.
     */
    public function isValidAltcha(mixed $payload, string $ip): bool
    {
        if (!is_string($payload)) {
            return false;
        }

        try {
            FormValidatorAltcha::validateResponse($payload, $ip);
        } catch (Exception $exception) {
            return false;
        }

        $signature = ((array) json_decode(base64_decode($payload)))['signature'] ?? '';
        return Cache::add('submissionCaptcha-altcha-' . $signature, true, self::ALTCHA_LIFETIME);
    }

    /**
     * Create an ALTCHA challenge that expires after ALTCHA_LIFETIME seconds
     *
     * Uses the same key and difficulty as OJS (altcha_hmackey, altcha_encrypt_number).
     */
    public function createAltchaChallenge(): array
    {
        $altcha = new Altcha(Config::getVar('captcha', 'altcha_hmackey'));
        $challenge = $altcha->createChallenge(new ChallengeOptions(
            algorithm: Algorithm::SHA256,
            maxNumber: (int) (Config::getVar('captcha', 'altcha_encrypt_number') ?: 10000),
            expires: new DateTimeImmutable('+' . self::ALTCHA_LIFETIME . ' seconds'),
        ));

        return [
            'algorithm' => $challenge->algorithm,
            'challenge' => $challenge->challenge,
            'maxnumber' => $challenge->maxNumber,
            'salt' => $challenge->salt,
            'signature' => $challenge->signature,
        ];
    }
}
