<?php

/**
 * @file plugins/generic/submissionCaptcha/classes/FieldRecaptcha.php
 *
 * Copyright (c) 2026 Marian P. Gajda
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class FieldRecaptcha
 *
 * @brief A form field that renders a reCAPTCHA v2 checkbox. Its value is the
 *   response token, which is sent with the other form values.
 */

namespace APP\plugins\generic\submissionCaptcha\classes;

use PKP\components\forms\Field;

class FieldRecaptcha extends Field
{
    /** @copydoc Field::$component */
    public $component = 'field-recaptcha';

    /** The public site key from config.inc.php */
    public string $siteKey = '';

    /** Language of the widget, see https://developers.google.com/recaptcha/docs/language */
    public string $recaptchaLocale = 'en';

    /**
     * @copydoc Field::getConfig()
     */
    public function getConfig()
    {
        $config = parent::getConfig();
        $config['siteKey'] = $this->siteKey;
        $config['recaptchaLocale'] = $this->recaptchaLocale;
        return $config;
    }
}
