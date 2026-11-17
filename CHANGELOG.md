# Changelog

## Unreleased

- Who sees which illness: blood relatives of your own individual and of your children under 18, by genetic degree,
  optionally limited; for every role, admins too. Page "Family health" lists them, JSON `Illnesses/{tree}` for apps.
  Leak test for every account.
- Illness catalog: name → category and ICD-10 code, ten categories, starter list in German and English, edited in the
  settings (with the names used in a tree but missing); JSON `Catalog/{tree}`; the category in the list and in
  `Illnesses`.
- Record an illness: form on the page for editors and JSON `Illness` (POST); the module sets `2 TYPE` and
  `2 RESN confidential` itself, the change goes through pending changes, a new name joins the catalog with its category.
- Family health history for the doctor: for your own individual or a child under 18, the relatives' illnesses by
  category with relationship, degree, ICD-10 code and age at diagnosis; early onset marked (age set in the settings);
  filter by category; print view. The same as JSON `FamilyHistory/{tree}` for apps.
- The cause of death (`1 DEAT / 2 CAUS`) counts as an illness at the date of death, once if it is recorded as an
  illness too; its category comes from the catalog like any other.
- New illnesses get `2 TYPE Illness` in every language (webtrees shows it translated); *Erkrankung* and *Krankheit*
  are still read.
- Release workflow: a tag `v<version>` runs the tests, builds `family-health-<version>.zip` and publishes the release.

## 0.1.0

- Frame of the module: page "Family health" in the menu *Lists* (members only), setting for the degree of
  relationship, English with German translation, tests against a fresh webtrees 2.2.6.
