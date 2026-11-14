#!/usr/bin/env python3
"""Tests fuer family-health gegen ein echtes webtrees (siehe umgebung.py). Aufruf: python3 tests/test_modul.py

Kern ist der Leck-Test: jede Erkrankung in testbaum.ged traegt ein Markerwort. Jedes Konto ruft Seite und JSON ab -
genau die Marker seiner Blutsverwandten (und der seiner minderjaehrigen Kinder) duerfen darin stehen, kein anderer.
"""
import sys
import unittest

import umgebung

U = None


def setUpModule():
    global U
    U = umgebung.Umgebung().aufbauen()


def tearDownModule():
    if U:
        U.abbauen()


MARKER = ["Markermutter", "Markerschwager", "Markernichte", "Markerpartnervater", "Markerfremd", "Markeradoptiert"]

# Konto -> sichtbare Marker. Paul (I3): Mutter, Nichte (Annas Tochter), ueber sein Kind unter 18 der Vater seiner Partnerin; nicht der
# Schwager, nicht das Adoptivkind, nicht der Fremde. Anna (I4): Mutter, ihre (erwachsene) Tochter; ihr Mann nicht.
# Fritz (I12) nur sich selbst. admin hat keine Person - auch er sieht nichts.
SICHTBAR = {
    "mitglied": {"Markermutter", "Markernichte", "Markerpartnervater"},
    "bearbeiter": {"Markermutter", "Markernichte"},
    "verwalter": {"Markerfremd"},
    "admin": set(),
}


def grade(antwort):
    return {e["illness"]: e["degree"] for e in antwort.json["illnesses"]}


class ModulTest(unittest.TestCase):
    def test_kein_leck(self):
        for konto, sichtbar in SICHTBAR.items():
            s = U.sitzung(konto)
            for antwort in (s.get("List", "testbaum"), s.get("Illnesses", "testbaum")):
                self.assertEqual(200, antwort.status, (konto, antwort))
                for marker in MARKER:
                    if marker in sichtbar:
                        self.assertIn(marker, antwort.text, (konto, marker))
                    else:
                        self.assertNotIn(marker, antwort.text, (konto, marker))

    def test_gast_sieht_nichts(self):
        for antwort in (U.sitzung().get("List", "testbaum"), U.sitzung().get("Illnesses", "testbaum")):
            self.assertNotEqual(200, antwort.status)
            for marker in MARKER:
                self.assertNotIn(marker, antwort.text)

    def test_webtrees_selbst_zeigt_es_mitgliedern_nicht(self):
        seite = U.sitzung("mitglied")._senden(umgebung.urllib.request.Request(U.sitzung().url("/tree/testbaum/individual/I2")))
        self.assertNotIn("Markermutter", seite.text)

    def test_grade(self):
        # Mutter 1, Nichte 2; Vater der Partnerin: 2. Grad zum Kind (Grossvater)
        self.assertEqual({"Markermutter": 1, "Markernichte": 2, "Markerpartnervater": 2},
                         grade(U.sitzung("mitglied").get("Illnesses", "testbaum")))
        # Anna: Mutter 1, Tochter 1
        self.assertEqual({"Markermutter": 1, "Markernichte": 1}, grade(U.sitzung("bearbeiter").get("Illnesses", "testbaum")))

    def test_grenze_nach_grad(self):
        admin = U.sitzung("admin")
        try:
            admin.formular("Admin", {"max_degree": "1"})
            self.assertEqual({"Markermutter": 1},
                             grade(U.sitzung("mitglied").get("Illnesses", "testbaum")))
        finally:
            admin.formular("Admin", {"max_degree": "0"})

    def test_ohne_person_ein_hinweis(self):
        self.assertIn("keiner Person", U.sitzung("admin").get("List", "testbaum").text)

    def test_seite_nur_fuer_mitglieder(self):
        gast = U.sitzung().get("List", "testbaum")
        self.assertNotEqual(200, gast.status, gast)
        mitglied = U.sitzung("mitglied").get("List", "testbaum")
        self.assertEqual(200, mitglied.status, mitglied)
        self.assertIn("Familiengesundheit", mitglied.text)

    def test_einstellungen(self):
        s = U.sitzung("admin")
        self.assertIn('name="max_degree"', s.get("Admin").text)
        self.assertIn(s.formular("Admin", {"max_degree": "3"}).status, (302, 303))
        self.assertIn('value="3" selected', s.get("Admin").text)
        s.formular("Admin", {"max_degree": "99"})
        self.assertIn('value="0" selected', s.get("Admin").text)

    def test_ohne_admin_keine_einstellungen(self):
        self.assertNotEqual(200, U.sitzung("verwalter").get("Admin").status)


if __name__ == "__main__":
    sys.path.insert(0, umgebung.HIER)
    unittest.main(verbosity=2)
