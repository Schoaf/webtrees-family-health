# Family Health History for webtrees

[Deutsch](README.de.md)

Record illnesses in your family tree and sum them up for the doctor: *"Any cases of this in your family?"*

> Work in progress – so far the visibility rule and a plain list of the illnesses you may see (page "Family health",
> menu *Lists*, and JSON `Illnesses/{tree}` for apps) and the catalog. The entry form is still to come.
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

The degree is the genetic one a doctor asks about: parents, children and siblings 1st, grandparents, aunts, uncles,
nieces, nephews and half siblings 2nd, first cousins 3rd. Adoptive and foster links (`2 PEDI`) don't count. The rule
holds for admins too; an account without a linked individual sees none. Optionally limited to a degree of
relationship (control panel → Modules → Family health).

## Catalog and categories

Each illness name has a category and, optionally, an ICD-10 code – the name, not each entry, so it stays the same
everywhere. Categories: heart and circulation, cancer, diabetes and metabolism, autoimmune, nervous system and dementia,
mental health, lungs, kidneys, hereditary syndromes, other. An illness not in the catalog counts as *other*.

The catalog starts with about 40 common illnesses in the site's language (German or English) and is edited in the
module's settings, which also list names used in a tree but missing from the catalog. JSON `Catalog/{tree}` gives apps
the categories and the catalog, as suggestions when entering an illness. It holds only names, never who has them.

## Installation

Copy this folder to `modules_v4/family-health` and enable the module. Requires webtrees 2.2 and PHP 8.3.

## Tests

`python3 tests/test_modul.py` sets up a fresh webtrees 2.2.6 with SQLite under `php -S` (127.0.0.1 only) and tests
against it.

## Licence

GPL-3.0, like webtrees.
