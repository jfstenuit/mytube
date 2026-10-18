//<![CDATA[
// Invisible reCAPTCHA: forms with data-recaptcha-action get a fresh token in
// their "recaptcha_token" field right before submission (tokens expire after 2 minutes).
document.addEventListener('DOMContentLoaded', function () {
    const meta = document.querySelector('meta[name="recaptcha-site-key"]');
    if (!meta) return;
    const siteKey = meta.content;
    const enterprise = meta.dataset.enterprise === '1';
    const TIMEOUT_MS = 8000;

    document.querySelectorAll('form[data-recaptcha-action]').forEach(function (form) {
        const field = form.querySelector('input[name="recaptcha_token"]');
        const button = form.querySelector('[type="submit"]');

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (button) button.disabled = true;

            let sent = false;
            // form.submit() doesn't fire "submit" again, so this runs once.
            // Without a token, the server decides (it may reject the request).
            const send = function (token) {
                if (sent) return;
                sent = true;
                field.value = token || '';
                form.submit();
            };
            setTimeout(function () { send(''); }, TIMEOUT_MS);

            const api = window.grecaptcha && (enterprise ? window.grecaptcha.enterprise : window.grecaptcha);
            if (!api) {
                send('');
                return;
            }
            api.ready(function () {
                api.execute(siteKey, { action: form.dataset.recaptchaAction }).then(send, function () { send(''); });
            });
        });
    });

    // Re-enable buttons when the page is restored from the back/forward cache
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[data-recaptcha-action] [type="submit"]').forEach(function (b) {
            b.disabled = false;
        });
    });
});
//]]>
