# Changelog

## Unreleased

- Who sees which illness: blood relatives of your own individual and of your children under 18, by genetic degree,
  optionally limited; for every role, admins too. Page "Family health" lists them, JSON `Illnesses/{tree}` for apps.
  Leak test for every account.
- Illness catalog: name → category and ICD-10 code, ten categories, starter list in German and English, edited in the
  settings (with the names used in a tree but missing); JSON `Catalog/{tree}`; the category in the list and in
  `Illnesses`.

## 0.1.0

- Frame of the module: page "Family health" in the menu *Lists* (members only), setting for the degree of
  relationship, English with German translation, tests against a fresh webtrees 2.2.6.
