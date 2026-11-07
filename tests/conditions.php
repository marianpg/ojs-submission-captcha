<?php

/**
 * @file plugins/generic/submissionCaptcha/tests/conditions.php
 *
 * Copyright (c) 2026 Marian P. Gajda
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Checks when the plugin registers its hooks and how it validates a new
 * submission. Configuration and the enabled state are changed in memory
 * only; nothing is written to config.inc.php or the database. The ALTCHA
 * checks mark the challenges they solve as used in the OJS cache.
 *
 * Run from the root of an OJS 3.5 installation that has the plugin in
 * plugins/generic/submissionCaptcha:
 *
 *   php plugins/generic/submissionCaptcha/tests/conditions.php
 */

require dirname(__FILE__, 5) . '/tools/bootstrap.php';

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\ChallengeOptions;
use AltchaOrg\Altcha\Hasher\Algorithm;
use APP\plugins\generic\submissionCaptcha\SubmissionCaptchaPlugin;
use PKP\config\Config;
use PKP\plugins\Hook;

const HOOKS = ['TemplateManager::display', 'Form::config::before', 'Submission::validate'];

$failures = 0;

function check(string $name, bool $passed): void
{
    global $failures;
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$passed) {
        $failures++;
    }
}

function setCaptchaConfig(array $captcha): void
{
    $config = &Config::getData();
    $config['captcha'] = $captcha;
}

/**
 * Register a fresh plugin instance with the given enabled state and
 * captcha settings, and return the number of plugin hooks registered.
 */
function registeredHooks(bool $enabled, array $captcha): int
{
    setCaptchaConfig($captcha);

    $hooks = &Hook::getHooks();
    foreach (HOOKS as $hookName) {
        unset($hooks[$hookName]);
    }

    $plugin = new class ($enabled) extends SubmissionCaptchaPlugin {
        public function __construct(private bool $enabledForTest)
        {
            parent::__construct();
        }

        public function getEnabled($contextId = null)
        {
            return $this->enabledForTest;
        }
    };
    $plugin->register('generic', 'plugins/generic/submissionCaptcha');

    return count(array_filter(HOOKS, fn (string $hookName) => !empty($hooks[$hookName])));
}

/**
 * Solve an ALTCHA challenge the way the widget does and return its payload
 */
function solveAltcha(array $challenge): string
{
    $altcha = new Altcha(Config::getVar('captcha', 'altcha_hmackey'));
    $solution = $altcha->solveChallenge($challenge['challenge'], $challenge['salt'], Algorithm::SHA256, $challenge['maxnumber']);
    return base64_encode(json_encode([
        'algorithm' => $challenge['algorithm'],
        'challenge' => $challenge['challenge'],
        'number' => $solution->number,
        'salt' => $challenge['salt'],
        'signature' => $challenge['signature'],
    ]));
}

$recaptcha = [
    'recaptcha' => true,
    'recaptcha_public_key' => 'public-key-for-test',
    'recaptcha_private_key' => 'private-key-for-test',
];
$altcha = [
    'altcha' => true,
    'altcha_hmackey' => 'hmac-key-for-test',
    'altcha_encrypt_number' => 1000,
];

check('reCAPTCHA configured: hooks registered', registeredHooks(true, $recaptcha) === count(HOOKS));
check('ALTCHA configured: hooks registered', registeredHooks(true, $altcha) === count(HOOKS));
check('both configured: hooks registered', registeredHooks(true, $recaptcha + $altcha) === count(HOOKS));
check('plugin disabled: no hooks', registeredHooks(false, $recaptcha + $altcha) === 0);
check('nothing configured: no hooks', registeredHooks(true, []) === 0);
check('recaptcha off: no hooks', registeredHooks(true, ['recaptcha' => false] + $recaptcha) === 0);
check('reCAPTCHA public key empty: no hooks', registeredHooks(true, ['recaptcha_public_key' => ''] + $recaptcha) === 0);
check('reCAPTCHA private key empty: no hooks', registeredHooks(true, ['recaptcha_private_key' => ''] + $recaptcha) === 0);
check('altcha off: no hooks', registeredHooks(true, ['altcha' => false] + $altcha) === 0);
check('ALTCHA HMAC key empty: no hooks', registeredHooks(true, ['altcha_hmackey' => ''] + $altcha) === 0);

$plugin = new SubmissionCaptchaPlugin();

setCaptchaConfig($recaptcha + $altcha);
$errors = [];
$plugin->validateCaptcha('Submission::validate', [&$errors, null, [], [], 'en']);
check('both configured, new submission without responses: error on both fields',
    isset($errors[SubmissionCaptchaPlugin::RECAPTCHA_FIELD], $errors[SubmissionCaptchaPlugin::ALTCHA_FIELD]));

setCaptchaConfig($altcha);
$errors = [];
$plugin->validateCaptcha('Submission::validate', [&$errors, null, [], [], 'en']);
check('ALTCHA only: error only on the ALTCHA field', array_keys($errors) === [SubmissionCaptchaPlugin::ALTCHA_FIELD]);

$errors = [];
$plugin->validateCaptcha('Submission::validate', [&$errors, new \APP\submission\Submission(), [], [], 'en']);
check('existing submission: not checked', $errors === []);

$payload = solveAltcha($plugin->createAltchaChallenge());
check('ALTCHA: solved challenge accepted', $plugin->isValidAltcha($payload, ''));
check('ALTCHA: same solution rejected the second time', !$plugin->isValidAltcha($payload, ''));

$tampered = json_decode(base64_decode(solveAltcha($plugin->createAltchaChallenge())), true);
$tampered['number']++;
check('ALTCHA: wrong number rejected', !$plugin->isValidAltcha(base64_encode(json_encode($tampered)), ''));

$expired = (new Altcha($altcha['altcha_hmackey']))->createChallenge(new ChallengeOptions(
    algorithm: Algorithm::SHA256,
    maxNumber: 1000,
    expires: new DateTimeImmutable('-1 minute'),
));
check('ALTCHA: expired challenge rejected', !$plugin->isValidAltcha(solveAltcha([
    'algorithm' => $expired->algorithm,
    'challenge' => $expired->challenge,
    'maxnumber' => $expired->maxNumber,
    'salt' => $expired->salt,
    'signature' => $expired->signature,
]), ''));

$challenge = $plugin->createAltchaChallenge();
setCaptchaConfig(['altcha_hmackey' => 'another-key'] + $altcha);
check('ALTCHA: challenge signed with another key rejected', !$plugin->isValidAltcha(solveAltcha($challenge), ''));

exit($failures ? 1 : 0);
