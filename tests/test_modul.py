#!/usr/bin/env python3
"""Tests fuer family-health gegen ein echtes webtrees (siehe umgebung.py). Aufruf: python3 tests/test_modul.py"""
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


class ModulTest(unittest.TestCase):
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
