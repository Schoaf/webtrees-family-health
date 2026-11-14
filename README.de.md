# Family Health History für webtrees

[English](README.md)

Erkrankungen im Stammbaum erfassen und für den Arzt zusammenfassen: *„Gab es das in Ihrer Familie schon?“*

> In Arbeit – bisher die Sichtbarkeitsregel und eine einfache Liste der Erkrankungen, die man sehen darf (Seite
> „Familiengesundheit“, Menü *Listen*, und JSON `Illnesses/{baum}` für Apps) und der Katalog. Die Eingabe folgt.
> Der Plan steht in [webtrees-mobile#54](https://github.com/Schoaf/webtrees-mobile/issues/54).

## Wie Erkrankungen gespeichert werden

Als gewöhnliche GEDCOM-Fakten, lesbar für jedes Genealogie-Programm:

```
1 FACT Diabetes mellitus Typ 2
2 TYPE Erkrankung
2 DATE ABT 1960
2 RESN confidential
```

`RESN confidential` hält sie auf den webtrees-Seiten von allen fern außer Managern und Admins.

## Wer was sieht

Das Modul zeigt eine Erkrankung nur

- Blutsverwandten der Person, die dem eigenen Konto zugeordnet ist, und
- Blutsverwandten der eigenen Kinder unter 18 – so sieht ein Elternteil auch die Linie des anderen.

Der Grad ist der genetische, nach dem Ärzte fragen: Eltern, Kinder und Geschwister 1., Großeltern, Tanten, Onkel,
Nichten, Neffen und Halbgeschwister 2., Cousins und Cousinen 3. Adoptiv- und Pflegeverhältnisse (`2 PEDI`) zählen
nicht. Die Regel gilt auch für Admins; ein Konto ohne zugeordnete Person sieht keine. Auf Wunsch begrenzt auf einen
Verwandtschaftsgrad (Verwaltung → Module → Familiengesundheit).

## Katalog und Kategorien

Jede Erkrankung hat eine Kategorie und auf Wunsch einen ICD-10-Code – am Namen, nicht an jedem Eintrag, damit sie
überall gleich bleibt. Kategorien: Herz und Kreislauf, Krebs, Diabetes und Stoffwechsel, Autoimmun, Nervensystem und
Demenz, Psychische Gesundheit, Lunge, Niere, Erbliche Syndrome, Sonstiges. Eine Erkrankung, die nicht im Katalog
steht, zählt als *Sonstiges*.

Der Katalog beginnt mit rund 40 häufigen Erkrankungen in der Sprache der Website (Deutsch oder Englisch) und wird in den
Einstellungen des Moduls gepflegt. Dort stehen auch Namen, die in einem Stammbaum vorkommen, aber im Katalog fehlen.
JSON `Catalog/{baum}` liefert Apps die Kategorien und den Katalog als Vorschläge beim Eintragen. Er enthält nur Namen,
nie, wer betroffen ist.

## Installation

Diesen Ordner nach `modules_v4/family-health` kopieren und das Modul einschalten. Braucht webtrees 2.2 und PHP 8.3.

## Tests

`python3 tests/test_modul.py` baut ein frisches webtrees 2.2.6 mit SQLite unter `php -S` auf (nur 127.0.0.1) und
testet dagegen.

## Lizenz

GPL-3.0, wie webtrees.
