# Family Health History für webtrees

[English](README.md)

Erkrankungen im Stammbaum erfassen und für den Arzt zusammenfassen: *„Gab es das in Ihrer Familie schon?“*

> In Arbeit – bisher die Sichtbarkeitsregel und eine einfache Liste der Erkrankungen, die man sehen darf (Seite
> „Familiengesundheit“, Menü *Listen*, und JSON `Illnesses/{baum}` für Apps). Katalog, Kategorien und Eingabe folgen.
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

## Installation

Diesen Ordner nach `modules_v4/family-health` kopieren und das Modul einschalten. Braucht webtrees 2.2 und PHP 8.3.

## Tests

`python3 tests/test_modul.py` baut ein frisches webtrees 2.2.6 mit SQLite unter `php -S` auf (nur 127.0.0.1) und
testet dagegen.

## Lizenz

GPL-3.0, wie webtrees.
