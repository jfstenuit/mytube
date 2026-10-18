"""Minimal Chrome DevTools Protocol client (Python standard library only).

Assumes Chrome answers on the DevTools endpoint http://127.0.0.1:9222 (override with
CHROME_DEVTOOLS=host:port). Every Context is an isolated browser context (like an
incognito window): its cookies and storage disappear when it is closed, so tests never
touch the user's own tabs or cookies.
"""
import base64
import json
import os
import socket
import struct
import time
import urllib.request

ENDPOINT = os.environ.get('CHROME_DEVTOOLS', '127.0.0.1:9222')


class WebSocket:
    def __init__(self, url, timeout=60):
        assert url.startswith('ws://'), url
        hostport, path = url[5:].split('/', 1)
        host, port = hostport.rsplit(':', 1)
        self.sock = socket.create_connection((host, int(port)), timeout=timeout)
        key = base64.b64encode(os.urandom(16)).decode()
        self.sock.sendall((f'GET /{path} HTTP/1.1\r\nHost: {hostport}\r\nUpgrade: websocket\r\n'
                           f'Connection: Upgrade\r\nSec-WebSocket-Key: {key}\r\n'
                           'Sec-WebSocket-Version: 13\r\n\r\n').encode())
        response = b''
        while b'\r\n\r\n' not in response:
            response += self.sock.recv(1)
        if b' 101 ' not in response.split(b'\r\n')[0]:
            raise ConnectionError(response.decode(errors='replace'))

    def _exact(self, n):
        data = b''
        while len(data) < n:
            chunk = self.sock.recv(n - len(data))
            if not chunk:
                raise ConnectionError('DevTools connection closed')
            data += chunk
        return data

    def send(self, text):
        payload = text.encode()
        header = bytes([0x81])
        n = len(payload)
        if n < 126:
            header += bytes([0x80 | n])
        elif n < 65536:
            header += bytes([0x80 | 126]) + struct.pack('>H', n)
        else:
            header += bytes([0x80 | 127]) + struct.pack('>Q', n)
        mask = os.urandom(4)
        self.sock.sendall(header + mask + bytes(b ^ mask[i % 4] for i, b in enumerate(payload)))

    def recv(self):
        message = b''
        while True:
            first, second = self._exact(2)
            n = second & 0x7F
            if n == 126:
                n = struct.unpack('>H', self._exact(2))[0]
            elif n == 127:
                n = struct.unpack('>Q', self._exact(8))[0]
            payload = self._exact(n)
            opcode = first & 0x0F
            if opcode == 0x8:
                raise ConnectionError('DevTools connection closed')
            if opcode == 0x9:
                continue
            message += payload
            if first & 0x80:
                return message.decode()

    def close(self):
        try:
            self.sock.close()
        except OSError:
            pass


class Session:
    """JSON-RPC over one WebSocket (the browser, or one page)"""

    def __init__(self, url):
        self.ws = WebSocket(url)
        self.next_id = 0
        self.events = []

    def call(self, method, **params):
        self.next_id += 1
        my_id = self.next_id
        self.ws.send(json.dumps({'id': my_id, 'method': method, 'params': params}))
        while True:
            message = json.loads(self.ws.recv())
            if message.get('id') == my_id:
                if 'error' in message:
                    raise RuntimeError(f'{method}: {message["error"]}')
                return message.get('result', {})
            self.events.append(message)

    def wait_event(self, name, timeout=30):
        deadline = time.time() + timeout
        while time.time() < deadline:
            for i, event in enumerate(self.events):
                if event.get('method') == name:
                    return self.events.pop(i)
            self.ws.sock.settimeout(max(0.1, deadline - time.time()))
            try:
                self.events.append(json.loads(self.ws.recv()))
            except socket.timeout:
                break
            finally:
                self.ws.sock.settimeout(60)
        raise TimeoutError(name)


def available():
    try:
        with urllib.request.urlopen(f'http://{ENDPOINT}/json/version', timeout=5) as response:
            return json.load(response)
    except OSError:
        return None


class Browser:
    def __init__(self):
        version = available()
        if version is None:
            raise ConnectionError(f'No Chrome DevTools endpoint at http://{ENDPOINT}')
        self.version = version['Browser']
        self.session = Session(version['webSocketDebuggerUrl'])

    def context(self):
        return Context(self)

    def close(self):
        self.session.ws.close()


class Context:
    """Isolated browser context (separate cookies), disposed on close()"""

    def __init__(self, browser):
        self.browser = browser
        self.id = browser.session.call('Target.createBrowserContext', disposeOnDetach=True)['browserContextId']
        self.pages = []

    def page(self, width=1280, height=900, mobile=False, dark=False, touch=False):
        target = self.browser.session.call('Target.createTarget', url='about:blank', browserContextId=self.id)['targetId']
        page = Page(target, width, height, mobile, dark, touch)
        self.pages.append(page)
        return page

    def close(self):
        for page in self.pages:
            page.close(self.browser)
        try:
            self.browser.session.call('Target.disposeBrowserContext', browserContextId=self.id)
        except RuntimeError:
            pass


class Page:
    def __init__(self, target_id, width, height, mobile, dark, touch):
        self.target_id = target_id
        self.session = Session(f'ws://{ENDPOINT}/devtools/page/{target_id}')
        self.call('Page.enable')
        self.call('Runtime.enable')
        self.call('Network.enable')
        self.call('Network.setCacheDisabled', cacheDisabled=True)
        self.call('Emulation.setDeviceMetricsOverride', width=width, height=height, deviceScaleFactor=1, mobile=mobile)
        self.call('Emulation.setEmulatedMedia', features=[{'name': 'prefers-color-scheme', 'value': 'dark' if dark else 'light'}])
        if touch:
            self.call('Emulation.setTouchEmulationEnabled', enabled=True, maxTouchPoints=1)

    def call(self, method, **params):
        return self.session.call(method, **params)

    @property
    def events(self):
        return self.session.events

    def goto(self, url, settle=1.0):
        self.session.events.clear()
        self.call('Page.navigate', url=url)
        self.session.wait_event('Page.loadEventFired')
        time.sleep(settle)

    def wait_navigation(self, settle=0.8):
        self.session.wait_event('Page.loadEventFired')
        time.sleep(settle)

    def js(self, expression):
        result = self.call('Runtime.evaluate', expression=expression, returnByValue=True, awaitPromise=True)
        if 'exceptionDetails' in result:
            raise RuntimeError(result['exceptionDetails'].get('exception', {}).get('description', result['exceptionDetails']))
        return result['result'].get('value')

    def js_errors(self):
        self.js('0')
        return [e['params']['exceptionDetails'].get('exception', {}).get('description', e['params']['exceptionDetails'].get('text'))
                for e in self.session.events if e.get('method') == 'Runtime.exceptionThrown']

    def requests(self, suffix):
        self.js('0')  # events are read from the socket during calls: fetch the pending ones
        return [e['params']['request'] for e in self.session.events
                if e.get('method') == 'Network.requestWillBeSent' and e['params']['request']['url'].split('?')[0].endswith(suffix)]

    def tap(self, x, y):
        self.call('Input.dispatchTouchEvent', type='touchStart', touchPoints=[{'x': x, 'y': y}])
        self.call('Input.dispatchTouchEvent', type='touchEnd', touchPoints=[])
        time.sleep(0.4)

    def key(self, key, code=None, keycode=0):
        for kind in ('keyDown', 'keyUp'):
            self.call('Input.dispatchKeyEvent', type=kind, key=key, code=code or key, windowsVirtualKeyCode=keycode)
        time.sleep(0.2)

    def center(self, selector):
        return self.js(f'(() => {{ const r = document.querySelector({json.dumps(selector)}).getBoundingClientRect();'
                       f' return [r.x + r.width / 2, r.y + r.height / 2, r.width, r.height]; }})()')

    def overflow(self):
        """Horizontal page overflow in pixels (0 = none)"""
        return self.js('document.documentElement.scrollWidth - document.documentElement.clientWidth')

    def screenshot(self, path, full_page=False):
        params = {'format': 'png'}
        if full_page:
            height = self.js('document.documentElement.scrollHeight')
            width = self.js('document.documentElement.clientWidth')
            params.update(captureBeyondViewport=True, clip={'x': 0, 'y': 0, 'width': width, 'height': height, 'scale': 1})
        data = self.call('Page.captureScreenshot', **params)['data']
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, 'wb') as f:
            f.write(base64.b64decode(data))

    def close(self, browser):
        self.session.ws.close()
        try:
            browser.session.call('Target.closeTarget', targetId=self.target_id)
        except RuntimeError:
            pass
