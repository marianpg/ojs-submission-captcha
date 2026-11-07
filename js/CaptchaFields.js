/**
 * @file plugins/generic/submissionCaptcha/js/CaptchaFields.js
 *
 * Copyright (c) 2026 Marian P. Gajda
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Form fields that render a reCAPTCHA v2 checkbox or the ALTCHA
 *   widget and use the response as their value.
 */
(function () {
	const RECAPTCHA_API_URL = 'https://www.recaptcha.net/recaptcha/api.js';
	const RECAPTCHA_ONLOAD_CALLBACK = 'submissionCaptchaRecaptchaOnLoad';

	const FieldBase = pkp.registry.getComponent('PkpFieldBase');

	const template = `
		<div class="pkpFormField pkpFormField--captcha">
			<div class="pkpFormField__heading">
				<form-field-label
					:control-id="controlId"
					:label="label"
					:is-required="showRequired"
					:required-label="t('common.required')"
				/>
			</div>
			<div
				v-if="description"
				:id="describedByDescriptionId"
				class="pkpFormField__description semantic-defaults"
				v-strip-unsafe-html="description"
			/>
			<div class="pkpFormField__control">
				<div :id="controlId" ref="widget" :aria-describedby="describedByIds" />
				<field-error
					v-if="loadFailed"
					:id="describedByErrorId"
					:messages="[t('common.unknownError')]"
				/>
				<field-error
					v-else-if="errors.length"
					:id="describedByErrorId"
					:messages="errors"
				/>
			</div>
		</div>
	`;

	let recaptchaApi = null;
	let altchaWidget = null;

	/**
	 * Load the reCAPTCHA API once, rendering widgets explicitly
	 */
	function loadRecaptcha(locale) {
		if (!recaptchaApi) {
			recaptchaApi = new Promise((resolve, reject) => {
				window[RECAPTCHA_ONLOAD_CALLBACK] = () => resolve(window.grecaptcha);
				const script = document.createElement('script');
				script.src =
					RECAPTCHA_API_URL +
					'?render=explicit&onload=' +
					RECAPTCHA_ONLOAD_CALLBACK +
					'&hl=' +
					encodeURIComponent(locale);
				script.async = true;
				script.onerror = reject;
				document.head.appendChild(script);
			});
		}
		return recaptchaApi;
	}

	/**
	 * Load the ALTCHA web component shipped with OJS once
	 */
	function loadAltcha(scriptUrl) {
		if (!altchaWidget) {
			altchaWidget = new Promise((resolve, reject) => {
				const script = document.createElement('script');
				script.type = 'module';
				script.src = scriptUrl;
				script.onerror = reject;
				document.head.appendChild(script);
				customElements.whenDefined('altcha-widget').then(resolve);
			});
		}
		return altchaWidget;
	}

	pkp.registry.registerComponent('FieldRecaptcha', {
		name: 'FieldRecaptcha',
		extends: FieldBase,
		props: {
			siteKey: {
				type: String,
				required: true,
			},
			recaptchaLocale: {
				type: String,
				default: 'en',
			},
		},
		data() {
			return {
				widgetId: null,
				loadFailed: false,
			};
		},
		watch: {
			/**
			 * A token can only be verified once. When it is rejected, show
			 * an unticked checkbox again. The value is kept so that the
			 * error stays visible until a new token replaces it.
			 */
			errors(newErrors) {
				if (newErrors.length && this.currentValue && this.widgetId !== null) {
					window.grecaptcha.reset(this.widgetId);
				}
			},
		},
		mounted() {
			loadRecaptcha(this.recaptchaLocale)
				.then((grecaptcha) => {
					this.widgetId = grecaptcha.render(this.$refs.widget, {
						sitekey: this.siteKey,
						callback: (token) => {
							this.currentValue = token;
						},
						'expired-callback': () => {
							this.currentValue = '';
						},
						'error-callback': () => {
							this.currentValue = '';
						},
					});
				})
				.catch(() => {
					this.loadFailed = true;
				});
		},
		template,
	});

	/**
	 * A challenge can only be used once and the widget cannot fetch a new
	 * one, so after a rejection the error asks to reload the page.
	 */
	pkp.registry.registerComponent('FieldAltcha', {
		name: 'FieldAltcha',
		extends: FieldBase,
		props: {
			challenge: {
				type: Object,
				required: true,
			},
			scriptUrl: {
				type: String,
				required: true,
			},
		},
		data() {
			return {
				loadFailed: false,
			};
		},
		mounted() {
			loadAltcha(this.scriptUrl)
				.then(() => {
					const widget = document.createElement('altcha-widget');
					widget.setAttribute('challengejson', JSON.stringify(this.challenge));
					widget.addEventListener('statechange', (event) => {
						this.currentValue =
							event.detail.state === 'verified' ? event.detail.payload : '';
					});
					this.$refs.widget.appendChild(widget);
				})
				.catch(() => {
					this.loadFailed = true;
				});
		},
		template,
	});
})();
