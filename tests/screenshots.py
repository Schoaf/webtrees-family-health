#!/usr/bin/env python3
"""Screenshots for the README: the demo family (demo.ged) in a fresh webtrees (see umgebung.py), signed in as
Elisabeth Bergmann, shot with headless Chrome. Writes docs/*.png.

Needs Google Chrome and websocket-client (`pip install websocket-client`). Run: python3 tests/screenshots.py
"""
import base64
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.request

import websocket

import umgebung

CHROME = os.environ.get("CHROME", "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome")
DOCS = os.path.join(umgebung.MODUL, "docs")
BAUM = "demo"

# name -> (route, parameters, width) - the height follows the page.
SEITEN = {
    "history": (f"/module/_family-health_/History/{BAUM}", {}, 1100),
    "list": (f"/module/_family-health_/List/{BAUM}", {}, 1100),
    "record": (f"/module/_family-health_/Record/{BAUM}", {"xref": "I2"}, 1100),
}


class Chrome:
    """Just enough of the DevTools protocol: a cookie, a page, a screenshot."""

    def __init__(self):
        self.profil = tempfile.mkdtemp()
        port = umgebung._freier_port()
        self.prozess = subprocess.Popen(
            [CHROME, "--headless=new", f"--remote-debugging-port={port}", f"--user-data-dir={self.profil}",
             "--hide-scrollbars", "--no-first-run", "about:blank"],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        ende = time.time() + 20
        while True:
            try:
                ziele = json.load(urllib.request.urlopen(f"http://127.0.0.1:{port}/json/list", timeout=2))
                seite = next(z for z in ziele if z["type"] == "page")
                break
            except (OSError, StopIteration):
                if time.time() > ende:
                    raise RuntimeError("Chrome startet nicht")
                time.sleep(0.2)
        self.ws = websocket.create_connection(seite["webSocketDebuggerUrl"], timeout=60, suppress_origin=True)
        self.nummer = 0
        self.senden("Page.enable")

    def senden(self, methode, **params):
        self.nummer += 1
        self.ws.send(json.dumps({"id": self.nummer, "method": methode, "params": params}))
        while True:
            nachricht = json.loads(self.ws.recv())
            if nachricht.get("id") == self.nummer:
                if "error" in nachricht:
                    raise RuntimeError(f"{methode}: {nachricht['error']}")
                return nachricht.get("result", {})

    def warten_auf(self, ereignis):
        while True:
            if json.loads(self.ws.recv()).get("method") == ereignis:
                return

    def foto(self, url, breite, datei):
        self.senden("Emulation.setDeviceMetricsOverride", width=breite, height=800, deviceScaleFactor=2, mobile=False)
        self.senden("Page.navigate", url=url)
        self.warten_auf("Page.loadEventFired")
        time.sleep(0.5)
        hoehe = self.senden("Runtime.evaluate", expression="document.documentElement.scrollHeight",
                            returnByValue=True)["result"]["value"]
        self.senden("Emulation.setDeviceMetricsOverride", width=breite, height=hoehe, deviceScaleFactor=2,
                    mobile=False)
        bild = self.senden("Page.captureScreenshot", format="png")["data"]
        with open(datei, "wb") as f:
            f.write(base64.b64decode(bild))

    def beenden(self):
        self.ws.close()
        self.prozess.terminate()
        self.prozess.wait(5)
        shutil.rmtree(self.profil, ignore_errors=True)


def main():
    u = umgebung.Umgebung().aufbauen()
    chrome = None
    try:
        # English: the catalog starts in the site's language, the demo's illness names are English.
        umgebung.wt("site-setting", "LANGUAGE", "en-GB")
        umgebung.wt("tree", BAUM, "--create", "--title=Bergmann family")
        umgebung.wt("tree-import", BAUM, os.path.join(umgebung.HIER, "demo.ged"))
        umgebung.wt("tree-setting", BAUM, "HIDE_LIVE_PEOPLE", "1")
        umgebung.wt("user", "elisabeth", "--create", "--real-name=Elisabeth Bergmann",
                    "--email=elisabeth@example.invalid", f"--password={umgebung.PASSWORT}")
        for name, wert in (("verified", "1"), ("verified_by_admin", "1"), ("language", "en-GB")):
            umgebung.wt("user-setting", "elisabeth", name, wert)
        umgebung.wt("user-tree-setting", "elisabeth", BAUM, "canedit", "edit")
        umgebung.wt("user-tree-setting", "elisabeth", BAUM, "gedcomid", "I1")

        sitzung = u.sitzung("elisabeth")
        chrome = Chrome()
        cookies = next(h.cookiejar for h in sitzung.oeffner.handlers if isinstance(h, urllib.request.HTTPCookieProcessor))
        for cookie in cookies:
            chrome.senden("Network.setCookie", name=cookie.name, value=cookie.value, url=u.basis)
        os.makedirs(DOCS, exist_ok=True)
        for name, (route, params, breite) in SEITEN.items():
            url = sitzung.url(route, **params).replace("lang=de", "lang=en-GB")
            chrome.foto(url, breite, os.path.join(DOCS, f"{name}.png"))
            print(f"docs/{name}.png")
    finally:
        if chrome:
            chrome.beenden()
        u.abbauen()


if __name__ == "__main__":
    sys.exit(main())
