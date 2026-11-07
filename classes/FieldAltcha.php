<?php

/**
 * @file plugins/generic/submissionCaptcha/classes/FieldAltcha.php
 *
 * Copyright (c) 2026 Marian P. Gajda
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class FieldAltcha
 *
 * @brief A form field that renders the ALTCHA widget shipped with OJS. Its
 *   value is the solved payload, which is sent with the other form values.
 */

namespace APP\plugins\generic\submissionCaptcha\classes;

use PKP\components\forms\Field;

class FieldAltcha extends Field
{
    /** @copydoc Field::$component */
    public $component = 'field-altcha';

    /** The challenge to solve: algorithm, challenge, maxnumber, salt, signature */
    public array $challenge = [];

    /** URL of the ALTCHA web component (lib/pkp/js/lib/altcha) */
    public string $scriptUrl = '';

    /**
     * @copydoc Field::getConfig()
     */
    public function getConfig()
    {
        $config = parent::getConfig();
        $config['challenge'] = $this->challenge;
        $config['scriptUrl'] = $this->scriptUrl;
        return $config;
    }
}
