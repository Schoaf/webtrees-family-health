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

    def test_katalog(self):
        admin = U.sitzung("admin")
        seite = admin.get("Admin").text
        # Startliste in der Sprache des Servers, Namen aus dem Baum ohne Kategorie zum Aufnehmen angeboten
        self.assertIn('value="Brustkrebs"', seite)
        self.assertIn('value="Markermutter"', seite)
        katalog = U.sitzung("mitglied").get("Catalog", "testbaum").json
        self.assertIn({"id": "cancer", "name": "Krebs"}, katalog["categories"])
        self.assertIn({"name": "Brustkrebs", "category": "cancer", "icd": "C50"}, katalog["illnesses"])
        # Ohne Kategorie: "Sonstiges"
        self.assertEqual("other", self._kategorie("Markermutter"))
        try:
            felder = {"catalog_form": "1", "max_degree": "0",
                      "entries[0][name]": "Markermutter", "entries[0][category]": "metabolic", "entries[0][icd]": "e11",
                      "entries[1][name]": "Brustkrebs", "entries[1][category]": "cancer", "entries[1][icd]": "C50", "entries[1][delete]": "1",
                      "entries[2][name]": "markermutter", "entries[2][category]": "cancer",
                      "entries[3][name]": "Kaputt", "entries[3][category]": "unbekannt",
                      "entries[4][name]": "Ohne Kategorie", "entries[4][category]": ""}
            self.assertIn(admin.formular("Admin", felder).status, (302, 303))
            namen = {e["name"]: e for e in U.sitzung("mitglied").get("Catalog", "testbaum").json["illnesses"]}
            # Gross geschriebener ICD-Code, Doppelte (ohne Ruecksicht auf Gross/klein), unbekannte Kategorie und leere
            # Kategorie fallen weg; geloeschte Zeile auch.
            self.assertEqual({"Markermutter": {"name": "Markermutter", "category": "metabolic", "icd": "E11"}}, namen)
            self.assertEqual("metabolic", self._kategorie("Markermutter"))
            # Formular ohne Katalog (nur Grad) laesst den Katalog stehen
            admin.formular("Admin", {"max_degree": "0"})
            self.assertEqual(1, len(U.sitzung("mitglied").get("Catalog", "testbaum").json["illnesses"]))
        finally:
            umgebung.sql("DELETE FROM wt_module_setting WHERE module_name='_family-health_' AND setting_name='catalog'")

    def _kategorie(self, krankheit):
        for e in U.sitzung("mitglied").get("Illnesses", "testbaum").json["illnesses"]:
            if e["illness"] == krankheit:
                return e["category"]

    def test_katalog_nur_fuer_mitglieder(self):
        self.assertNotEqual(200, U.sitzung().get("Catalog", "testbaum").status)

    def test_eintragen(self):
        # Bearbeiterin Anna traegt bei ihrer Mutter eine neue Erkrankung ein: ausstehende Aenderung, noch nicht sichtbar
        anna = U.sitzung("bearbeiter")
        self.assertIn('name="illness"', anna.get("List", "testbaum").text)
        a = anna.formular("Add", {"xref": "I2", "illness": "Markerneu", "category": "cancer", "date": "ABT 1970"}, "testbaum")
        self.assertIn(a.status, (302, 303))
        neu = umgebung.sql("SELECT new_gedcom FROM wt_change WHERE xref='I2' AND status='pending'")
        self.assertEqual(1, len(neu))
        self.assertIn("1 FACT Markerneu\n2 TYPE Erkrankung\n2 DATE ABT 1970\n2 RESN confidential", neu[0][0])
        self.assertNotIn("Markerneu", U.sitzung("mitglied").get("Illnesses", "testbaum").text)
        # neuer Name kam mit seiner Kategorie in den Katalog
        katalog = {e["name"]: e["category"] for e in U.sitzung("mitglied").get("Catalog", "testbaum").json["illnesses"]}
        self.assertEqual("cancer", katalog["Markerneu"])

        # Admin (Aenderungen sofort angenommen) traegt per JSON bei Fritz ein; Fritz sieht es, der Admin selbst nicht
        admin = U.sitzung("admin")
        self.assertEqual({"ok": True}, admin.json_post("Illness", "testbaum", {"xref": "I12", "illness": "Markeradmin@x"}).json)
        self.assertIn("Markeradmin@x", U.sitzung("verwalter").get("Illnesses", "testbaum").text)
        self.assertNotIn("Markeradmin", admin.get("Illnesses", "testbaum").text)
        self.assertIn("1 FACT Markeradmin@@x\n2 TYPE Erkrankung\n2 RESN confidential",
                      umgebung.sql("SELECT i_gedcom FROM wt_individuals WHERE i_id='I12'")[0][0])

    def test_eintragen_abgelehnt(self):
        mitglied = U.sitzung("mitglied")
        self.assertNotIn('name="illness"', mitglied.get("List", "testbaum").text)
        a = mitglied.json_post("Illness", "testbaum", {"xref": "I3", "illness": "Markerverboten"})
        self.assertEqual((400, False), (a.status, a.json["ok"]))
        admin = U.sitzung("admin")
        for rumpf in ({"xref": "I3", "illness": ""}, {"xref": "I3", "illness": "X", "date": "kein Datum"},
                      {"xref": "I999", "illness": "X"}, {"xref": "I3", "illness": "y" * 121}):
            self.assertEqual(400, admin.json_post("Illness", "testbaum", rumpf).status, rumpf)
        self.assertEqual(403, U.sitzung().json_post("Illness", "testbaum", {"xref": "I3", "illness": "X"}).status)
        self.assertNotIn("Markerverboten", umgebung.sql("SELECT group_concat(i_gedcom) FROM wt_individuals")[0][0])

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
