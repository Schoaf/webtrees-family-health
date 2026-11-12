# Family Health History for webtrees

[Deutsch](README.de.md)

Record illnesses in your family tree and sum them up for the doctor: *"Any cases of this in your family?"*

> Work in progress – version 0.1.0 is only the frame: the page "Family health" (menu *Lists*) and the settings.
> The plan is in [webtrees-mobile#54](https://github.com/Schoaf/webtrees-mobile/issues/54).

## How illnesses are stored

As plain GEDCOM facts, readable by any genealogy program:

```
1 FACT Diabetes mellitus Typ 2
2 TYPE Erkrankung
2 DATE ABT 1960
2 RESN confidential
```

`RESN confidential` keeps them away from webtrees' own pages for everyone but managers and admins.

## Who sees what

The module shows an illness only to

- blood relatives of the individual linked to your account, and
- blood relatives of your own children under 18 – so a parent sees the other parent's line.

Optionally limited to a degree of relationship (control panel → Modules → Family health).

## Installation

Copy this folder to `modules_v4/family-health` and enable the module. Requires webtrees 2.2 and PHP 8.3.

## Tests

`python3 tests/test_modul.py` sets up a fresh webtrees 2.2.6 with SQLite under `php -S` (127.0.0.1 only) and tests
against it.

## Licence

GPL-3.0, like webtrees.
