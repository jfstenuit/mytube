"""Browser tests through the Chrome DevTools Protocol (see tests/README.md).

Run from the project root:   python3 tests/browser/test_browser.py [-v] [TestClass[.test_method]]
Screenshots are written to tests/output/browser/.
"""
import json
import os
import subprocess
import sys
import time
import unittest

sys.path.insert(0, os.path.dirname(__file__))
import cdp  # noqa: E402

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
OUTPUT = os.path.join(ROOT, 'tests', 'output', 'browser')


def fixtures(*args):
    result = subprocess.run(['php', os.path.join(ROOT, 'tests', 'bin', 'fixtures.php'), *args],
                            capture_output=True, text=True, check=True, cwd=ROOT)
    return json.loads(result.stdout)


CONFIG = None
DATA = None
BROWSER = None


def setUpModule():
    global CONFIG, DATA, BROWSER
    if cdp.available() is None:
        raise unittest.SkipTest(f'No Chrome DevTools endpoint at http://{cdp.ENDPOINT}')
    CONFIG = fixtures('config')
    if CONFIG['env'] != 'development':
        raise unittest.SkipTest('Browser tests need APP_ENV=development (they create fixtures).')
    if CONFIG['e2e_override_present']:
        raise unittest.SkipTest('.env.e2e is present (an end-to-end run is in progress or was interrupted).')
    fixtures('cleanup')
    DATA = fixtures('setup')
    BROWSER = cdp.Browser()


def tearDownModule():
    if BROWSER is not None:
        BROWSER.close()
    fixtures('cleanup')


def url(path=''):
    return CONFIG['site_url'] + path


class BrowserTestCase(unittest.TestCase):
    """One isolated browser context per test"""

    def setUp(self):
        self.context = BROWSER.context()

    def tearDown(self):
        self.context.close()

    def page(self, **options):
        return self.context.page(**options)

    def login(self, page, role):
        user = DATA['users'][role]
        page.goto(url('login'), settle=1.5)
        if page.js('!document.getElementById("login-identifier")'):
            return  # already logged in in this context: the login page redirected away
        page.js(f'document.getElementById("login-identifier").value = {json.dumps(user["username"])};'
                f'document.getElementById("login-password").value = {json.dumps(user["password"])};'
                'document.querySelector("form.form [type=submit]").click()')
        page.wait_navigation()
        self.assertIsNotNone(page.js('document.querySelector(".user-menu-toggle")'), f'{role} logged in')

    def shot(self, page, name, full_page=True):
        page.screenshot(os.path.join(OUTPUT, f'{name}.png'), full_page=full_page)


class LayoutTest(BrowserTestCase):
    """Every page renders without JavaScript errors or horizontal overflow, in every layout"""

    LAYOUTS = {
        'desktop': {'width': 1280, 'height': 900},
        'mobile': {'width': 390, 'height': 844, 'mobile': True},
        'dark': {'width': 1280, 'height': 900, 'dark': True},
    }

    def check_pages(self, paths, role=None):
        for layout, options in self.LAYOUTS.items():
            page = self.page(**options)
            if role:
                self.login(page, role)
            for name, path in paths.items():
                with self.subTest(layout=layout, page=name):
                    page.goto(url(path), settle=1.0)
                    self.assertEqual(page.overflow(), 0, 'no horizontal scrolling')
                    self.assertEqual(page.js_errors(), [], 'no JavaScript errors')
                    self.assertIsNotNone(page.js('document.querySelector("header .brand")'))
                    self.shot(page, f'{role or "anonymous"}-{name}-{layout}')

    def test_public_pages(self):
        self.check_pages({
            'home': '',
            'watch': f'watch?v={DATA["videos"]["public"]}',
            'login': 'login',
            'register': 'register',
            'forgot-password': 'forgot-password',
        })

    def test_member_pages(self):
        self.check_pages({'account': 'account', 'watch': f'watch?v={DATA["videos"]["unlisted"]}'}, role='member')

    def test_admin_pages(self):
        self.check_pages({
            'admin-videos': 'admin/videos',
            'admin-media': 'admin/media',
            'admin-collections': 'admin/collections',
            'admin-groups': 'admin/groups',
            'admin-group-admin': 'admin/groups/edit?id=1',
            'admin-users': 'admin/users',
            'admin-user-new': 'admin/users/new',
        }, role='admin')


class HeaderTest(BrowserTestCase):
    def test_banner_leads_home(self):
        page = self.page()
        page.goto(url('forgot-password'))
        page.js('document.querySelector(".brand").click()')
        page.wait_navigation()
        self.assertEqual(page.js('location.href'), url(''))

    def test_login_button_is_icon_only_on_phones(self):
        page = self.page(width=390, height=844, mobile=True)
        page.goto(url('login'))
        x, y, width, height = page.center('.btn-login')
        self.assertLessEqual(width, 48)
        self.assertGreaterEqual(height, 40)
        self.assertIn('Se connecter', page.js('document.querySelector(".btn-login").textContent'), 'label kept for screen readers')

    def test_user_menu_with_mouse_and_keyboard(self):
        page = self.page()
        self.login(page, 'member')
        menu = 'document.querySelector("#user-menu-list").hidden'
        page.js('document.querySelector(".user-menu-toggle").click()')
        self.assertFalse(page.js(menu))
        self.assertEqual(page.js('document.querySelector(".user-menu-toggle").getAttribute("aria-expanded")'), 'true')
        self.assertEqual(page.overflow(), 0, 'the open menu fits in the page')
        page.js('document.body.click()')
        self.assertTrue(page.js(menu), 'closes on outside click')

        page.js('document.querySelector(".user-menu-toggle").focus()')
        page.key('ArrowDown', keycode=40)
        self.assertFalse(page.js(menu))
        self.assertEqual(page.js('document.activeElement.getAttribute("role")'), 'menuitem')
        page.key('Escape', keycode=27)
        self.assertTrue(page.js(menu))
        self.assertTrue(page.js('document.activeElement.classList.contains("user-menu-toggle")'), 'focus returns to the button')
        self.assertIsNone(page.js('[...document.querySelectorAll("#user-menu-list a")].find(a => a.textContent.includes("Administration"))'),
                          'no admin entry for members')

    def test_logout_from_the_menu(self):
        page = self.page()
        self.login(page, 'member')
        page.js('document.querySelector(".user-menu-toggle").click()')
        page.js('document.querySelector("#user-menu-list form button").click()')
        page.wait_navigation()
        self.assertIsNotNone(page.js('document.querySelector(".btn-login")'))


class CollectionSwitcherTest(BrowserTestCase):
    def test_touch_interaction(self):
        page = self.page(width=390, height=844, mobile=True, touch=True)
        self.login(page, 'member')
        page.goto(url(f'watch?v={DATA["videos"]["public"]}'), settle=1.5)
        page.js('document.querySelector(".collection-section").scrollIntoView({block: "start"})')
        time.sleep(0.4)
        is_open = 'document.querySelector(".collection-switcher").open'

        x, y, width, height = page.center('.collection-switcher summary')
        self.assertGreaterEqual(height, 44, 'touch target')
        page.tap(x, y)
        self.assertTrue(page.js(is_open))
        self.shot(page, 'switcher-touch-open', full_page=False)
        page.tap(40, 800)
        self.assertFalse(page.js(is_open), 'closes on a tap elsewhere')
        page.tap(x, y)
        page.tap(x, y)
        self.assertFalse(page.js(is_open), 'second tap closes')

        page.tap(x, y)
        item_x, item_y, _, item_height = page.center('.collection-switcher li a')
        self.assertGreaterEqual(item_height, 44)
        self.assertEqual(page.js('document.querySelector(".collection-switcher li a").textContent'), 'zz Browser B')
        page.session.events.clear()
        page.tap(item_x, item_y)
        page.wait_navigation()
        self.assertEqual(page.js('document.querySelector(".collection-title h3").textContent'), 'zz Browser B')

    def test_escape_closes(self):
        page = self.page()
        self.login(page, 'member')
        page.goto(url(f'watch?v={DATA["videos"]["public"]}'))
        page.js('document.querySelector(".collection-switcher summary").click()')
        page.key('Escape', keycode=27)
        self.assertFalse(page.js('document.querySelector(".collection-switcher").open'))
        self.assertEqual(page.js('document.activeElement.tagName'), 'SUMMARY')


class EngagementTest(BrowserTestCase):
    def test_like_without_reloading(self):
        page = self.page()
        self.login(page, 'member')
        page.goto(url(f'watch?v={DATA["videos"]["public"]}'))
        page.js('window.__sameDocument = true')
        page.js('document.querySelector(".like-btn").click()')
        time.sleep(1.5)
        self.assertEqual(page.js('document.querySelector(".like-btn").getAttribute("aria-pressed")'), 'true')
        self.assertEqual(page.js('document.querySelector(".like-count").textContent'), '1')
        self.assertTrue(page.js('window.__sameDocument === true'), 'no page reload')
        page.js('document.querySelector(".like-btn").click()')
        time.sleep(1.5)
        self.assertEqual(page.js('document.querySelector(".like-count").textContent'), '0')

    def test_view_is_reported_after_actual_playback(self):
        page = self.page()
        video = DATA['videos']['public']
        page.goto(url(f'watch?v={video}'), settle=1.5)
        self.assertEqual(page.js('document.getElementById("video-player").dataset.viewThreshold'), '2')
        before = fixtures('view-count', video)['count']
        page.js('const p = videojs.getPlayer("video-player"); p.muted(true); p.play();')
        time.sleep(5)
        self.assertEqual(len(page.requests('/view')), 1, 'one beacon per page load')
        self.assertEqual(fixtures('view-count', video)['count'], before + 1)
        self.assertEqual(page.js_errors(), [])

    def test_share_uses_the_video_url(self):
        page = self.page()
        video = DATA['videos']['public']
        page.goto(url(f'watch?v={video}'))
        shared = page.js('''(async () => {
            let got = null;
            navigator.share = async (data) => { got = data.url; };
            document.getElementById("share-btn").click();
            await new Promise(r => setTimeout(r, 300));
            return got;
        })()''')
        self.assertEqual(shared, url(f'watch?v={video}'))


class RecaptchaTest(BrowserTestCase):
    def setUp(self):
        if not CONFIG['recaptcha_enabled']:
            self.skipTest('reCAPTCHA is disabled (RECAPTCHA_SITE_KEY empty)')
        super().setUp()

    def test_real_token_is_accepted_by_google(self):
        page = self.page()
        page.goto(url('forgot-password'), settle=3)
        self.assertTrue(page.js('!!(window.grecaptcha && (grecaptcha.enterprise || grecaptcha.execute))'), 'script loaded')
        self.assertEqual(page.js('document.querySelector(".recaptcha-notice").textContent.trim()'), 'Ce site est protégé par reCAPTCHA.')
        token = page.js('''(async () => {
            const meta = document.querySelector('meta[name="recaptcha-site-key"]');
            const api = meta.dataset.enterprise === "1" ? grecaptcha.enterprise : grecaptcha;
            return await new Promise(ok => api.ready(() => api.execute(meta.content, {action: "forgot_password"}).then(ok, () => ok(""))));
        })()''')
        self.assertTrue(token, 'token obtained')
        self.assertEqual(fixtures('recaptcha', token, 'forgot_password')['status'], 'ok')
        badge = page.js('(() => { const b = document.querySelector(".grecaptcha-badge"); return b ? getComputedStyle(b).visibility : "hidden"; })()')
        self.assertEqual(badge, 'hidden')

    def test_protected_form_submits_with_a_token(self):
        page = self.page()
        page.goto(url('forgot-password'), settle=3)
        page.js('document.getElementById("forgot-email").value = "zz_browser_nobody@example.com";'
                'document.querySelector("form.form [type=submit]").click()')
        page.wait_navigation()
        posted = [r for r in page.requests('/forgot-password') if r['method'] == 'POST']
        self.assertEqual(len(posted), 1)
        if 'postData' in posted[0]:
            self.assertRegex(posted[0]['postData'], r'recaptcha_token=[^&]{20,}', 'the form carried a token')
        self.assertEqual(page.js('location.pathname').rstrip('/').split('/')[-1], 'login', 'accepted, back to the login page')
        self.assertIn('Si un compte correspond', page.js('document.querySelector(".alert").textContent'))


if __name__ == '__main__':
    unittest.main()
