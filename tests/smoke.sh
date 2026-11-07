#!/usr/bin/env bash
# Smoke test for the submissionCaptcha plugin against a running OJS 3.5.
#
# Logs in as an author, checks that the start form contains a captcha field
# and that the API refuses to create a submission without a captcha
# response. The plugin must be enabled in the journal and reCAPTCHA and/or
# ALTCHA configured.
#
# Usage:
#   OJS_URL=http://localhost:8095 OJS_JOURNAL=testj OJS_SECTION_ID=1 \
#   OJS_USER=author OJS_PASSWORD=... tests/smoke.sh
#
# With OJS_TEST_KEYS=1 (only with reCAPTCHA alone and Google's public test
# keys, which accept any token) it also checks that a submission is created
# when a token is sent. That check leaves a new draft submission behind.
set -euo pipefail

: "${OJS_URL:?}" "${OJS_JOURNAL:?}" "${OJS_SECTION_ID:?}" "${OJS_USER:?}" "${OJS_PASSWORD:?}"

base="$OJS_URL/index.php/$OJS_JOURNAL"
jar=$(mktemp)
trap 'rm -f "$jar"' EXIT
failures=0

pass() { echo "PASS $1"; }
fail() { echo "FAIL $1"; failures=$((failures + 1)); }

# Log in through the regular login form
loginCsrf=$(curl -sS -c "$jar" -b "$jar" "$base/login" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)
curl -sS -o /dev/null -c "$jar" -b "$jar" \
	--data-urlencode "username=$OJS_USER" \
	--data-urlencode "password=$OJS_PASSWORD" \
	--data-urlencode "csrfToken=$loginCsrf" \
	"$base/login/signIn"

page=$(curl -sS -c "$jar" -b "$jar" "$base/submission")
apiCsrf=$(printf '%s' "$page" | sed -n 's/.*"csrfToken":"\([^"]*\)".*/\1/p' | head -1)
if [ -z "$apiCsrf" ]; then
	echo "Login failed or start page not reachable" >&2
	exit 2
fi

# Captcha fields on the start form, by their field name
fields=()
grep -q '"component":"field-recaptcha"' <<<"$page" && fields+=(recaptcha)
grep -q '"component":"field-altcha"' <<<"$page" && fields+=(altcha)

if [ ${#fields[@]} -gt 0 ]; then
	pass "start form contains captcha fields: ${fields[*]}"
else
	fail "start form contains a captcha field"
fi

# POST to the submissions API like the start form does; prints body and status
createSubmission() {
	curl -sS -w '\n%{http_code}' -c "$jar" -b "$jar" \
		-H "X-Csrf-Token: $apiCsrf" \
		--data "sectionId=$OJS_SECTION_ID&submissionRequirements=true&privacyConsent=true$1" \
		"$base/api/v1/submissions"
}

expectRejected() {
	local name=$1 response status field
	response=$(createSubmission "$2")
	status=$(tail -n1 <<<"$response")
	if [ "$status" != 400 ]; then
		fail "$name (HTTP $status: $(head -n1 <<<"$response" | cut -c1-200))"
		return
	fi
	for field in ${fields[@]+"${fields[@]}"}; do
		if ! grep -q "\"$field\"" <<<"$response"; then
			fail "$name (no error on $field)"
			return
		fi
	done
	pass "$name"
}

expectRejected "no submission without responses" ""
expectRejected "no submission with empty responses" "&recaptcha=&altcha="

if [ "${OJS_TEST_KEYS:-0}" = 1 ]; then
	response=$(createSubmission "&recaptcha=smoke-test-token")
	status=$(tail -n1 <<<"$response")
	if [ "$status" = 200 ]; then
		pass "submission created with token (test keys)"
	else
		fail "submission created with token (HTTP $status: $(head -n1 <<<"$response" | cut -c1-200))"
	fi
fi

[ "$failures" = 0 ]
