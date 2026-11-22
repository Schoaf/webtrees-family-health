<?php

declare(strict_types=1);

namespace FamilyHealth;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Site;

use function array_fill_keys;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_unique;
use function explode;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_strlen;
use function max;
use function mb_strtolower;
use function mb_substr;
use function preg_match;
use function strtoupper;
use function trim;
use function uksort;

use const JSON_UNESCAPED_UNICODE;

/**
 * The illness catalog: each illness name, as written in the tree, with its category and an optional ICD-10 code.
 * The category belongs to the name, not to each entry, so it stays the same everywhere and a change acts on all.
 *
 * Stored as JSON in a module setting, shared by all trees. On first use it starts from resources/catalog/<language>.php
 * in the site's language (English if there is none).
 */
final class Catalog
{
    // The categories to start with; an administrator renames, adds and deletes them (all but "other").
    private const array DEFAULT_CATEGORIES = [
        'cardiovascular', 'cancer', 'metabolic', 'autoimmune', 'neurological', 'mental', 'lung', 'kidney', 'hereditary',
        'natural-death', 'unnatural-death', 'other',
    ];

    // Where an illness without a category - or whose category was deleted - goes.
    public const string FALLBACK_CATEGORY = 'other';

    public const int CATEGORY_MAX_LENGTH = 60;

    private const string SETTING = 'catalog';

    private const string CATEGORY_SETTING = 'categories';

    /** @var array<string,string>|null category => the name an administrator gave it, '' for the built-in name */
    private array|null $categories = null;

    public const int NAME_MAX_LENGTH = 120;

    // ICD-10 code: letter, two digits, optionally a point and up to four more characters (C50, E11.9, Q87.4).
    private const string ICD_PATTERN = '/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,4})?$/';

    /** @var array<string,array{category:string,icd:string}>|null */
    private array|null $entries = null;

    public function __construct(private readonly AbstractModule $module)
    {
    }

    /**
     * @return array<string,string> category => its name: the one an administrator gave it, else the built-in one in
     *                              the user's language - in the administrator's order
     */
    public function categoryNames(): array
    {
        $builtin = self::builtinNames();
        $names   = [];

        foreach ($this->categorySettings() as $id => $name) {
            $names[$id] = $name !== '' ? $name : ($builtin[$id] ?? $id);
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    public function categoryIds(): array
    {
        return array_keys($this->categorySettings());
    }

    /**
     * @return array<string,string> category => the name an administrator gave it, '' for the built-in name
     */
    public function categorySettings(): array
    {
        if ($this->categories === null) {
            $stored           = json_decode($this->module->getPreference(self::CATEGORY_SETTING), true);
            $this->categories = [];

            foreach (is_array($stored) ? $stored : array_fill_keys(self::DEFAULT_CATEGORIES, '') as $id => $name) {
                if (is_string($id) && $id !== '' && is_string($name)) {
                    $this->categories[$id] = $name;
                }
            }

            $this->categories += [self::FALLBACK_CATEGORY => ''];
        }

        return $this->categories;
    }

    /**
     * Saves the categories from the administrator's form: existing ones renamed or deleted (their illnesses go to
     * "other"), new ones added at the end. A name like the built-in one keeps following the user's language.
     *
     * @param list<array{id:string,name:string,delete:bool}> $rows
     */
    public function saveCategories(array $rows): void
    {
        $builtin = self::builtinNames();
        $old     = $this->categorySettings();
        $new     = [];
        $next    = 1;

        foreach (array_keys($old) as $id) {
            if (preg_match('/^custom-(\d+)$/', $id, $match) === 1) {
                $next = max($next, (int) $match[1] + 1);
            }
        }

        foreach ($rows as $row) {
            $name = mb_substr(trim($row['name']), 0, self::CATEGORY_MAX_LENGTH);
            $id   = $row['id'];

            if ($id === '') {
                if ($name !== '' && !$row['delete']) {
                    $new['custom-' . $next++] = $name;
                }
            } elseif (array_key_exists($id, $old) && ($id === self::FALLBACK_CATEGORY || !$row['delete'])) {
                $new[$id] = $name === ($builtin[$id] ?? '') ? '' : $name;
            }
        }

        // Without a name a custom category has nothing to show - it keeps the one it had.
        foreach ($new as $id => $name) {
            if ($name === '' && !array_key_exists($id, $builtin)) {
                $new[$id] = $old[$id];
            }
        }

        $new += [self::FALLBACK_CATEGORY => $old[self::FALLBACK_CATEGORY] ?? ''];

        // The illnesses of a deleted category go to "other" - before the catalog is cleaned against the new list.
        $entries          = $this->entries();
        $this->categories = $new;

        foreach ($entries as $name => $entry) {
            if (!array_key_exists($entry['category'], $new)) {
                $entries[$name]['category'] = self::FALLBACK_CATEGORY;
            }
        }

        $this->module->setPreference(self::CATEGORY_SETTING, (string) json_encode($new, JSON_UNESCAPED_UNICODE));
        $this->save($entries);
    }

    /**
     * @return array<string,string> category => its built-in name in the user's language
     */
    private static function builtinNames(): array
    {
        return [
            'cardiovascular' => I18N::translate('Heart and circulation'),
            'cancer'         => I18N::translate('Cancer'),
            'metabolic'      => I18N::translate('Diabetes and metabolism'),
            'autoimmune'     => I18N::translate('Autoimmune'),
            'neurological'   => I18N::translate('Nervous system and dementia'),
            'mental'         => I18N::translate('Mental health'),
            'lung'           => I18N::translate('Lungs'),
            'kidney'         => I18N::translate('Kidneys'),
            'hereditary'     => I18N::translate('Hereditary syndromes'),
            'natural-death'  => I18N::translate('Natural cause of death'),
            'unnatural-death' => I18N::translate('Unnatural cause of death'),
            'other'          => I18N::translate('Other'),
        ];
    }

    /**
     * @return array<string,array{category:string,icd:string}> name => category and ICD-10 code, sorted by name
     */
    public function entries(): array
    {
        if ($this->entries === null) {
            $stored = json_decode($this->module->getPreference(self::SETTING), true);

            if (is_array($stored)) {
                $this->entries = $this->clean($stored);
            } else {
                // Saved right away, so the list doesn't change with the language of whoever looks next.
                $this->save(self::starterList());
            }
        }

        return $this->entries ?? [];
    }

    /**
     * The entry for an illness name, ignoring case - null if it isn't in the catalog.
     *
     * @return array{name:string,category:string,icd:string}|null
     */
    public function find(string $name): array|null
    {
        $key = mb_strtolower(trim($name));

        foreach ($this->entries() as $entry_name => $entry) {
            if (mb_strtolower($entry_name) === $key) {
                return ['name' => $entry_name] + $entry;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $entries name => [category, icd], as from a form - invalid ones are dropped
     */
    public function save(array $entries): void
    {
        $this->entries = $this->clean($entries);
        $this->module->setPreference(self::SETTING, (string) json_encode($this->entries, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Adds one name with its category, keeping the rest.
     */
    public function add(string $name, string $category): void
    {
        $this->save([$name => ['category' => $category, 'icd' => '']] + $this->entries());
    }

    /**
     * Illness names used in any tree but missing from the catalog - for admins, to give them a category.
     *
     * @return list<string>
     */
    public function namesNotInCatalog(): array
    {
        $missing = [];

        foreach (DB::table('individuals')
            ->where(static fn ($query) => $query->where('i_gedcom', 'LIKE', '%1 FACT %')->orWhere('i_gedcom', 'LIKE', '%2 CAUS %'))
            ->pluck('i_gedcom') as $gedcom) {
            foreach (Illnesses::illnessesIn((string) $gedcom) as [$name]) {
                if ($this->find($name) === null && !in_array($name, $missing, true)) {
                    $missing[] = $name;
                }
            }
        }

        return $missing;
    }

    /**
     * Who carries each illness name, per tree - to count them, list them and rename a name for all of them.
     *
     * @return array<string,array<string,list<string>>> lower-case name => tree name => individuals
     */
    public function usage(): array
    {
        $usage = [];

        foreach (DB::table('individuals')
            ->join('gedcom', 'gedcom_id', '=', 'i_file')
            ->where(static fn ($query) => $query->where('i_gedcom', 'LIKE', '%1 FACT %')->orWhere('i_gedcom', 'LIKE', '%2 CAUS %'))
            ->select(['gedcom_name', 'i_id', 'i_gedcom'])
            ->get() as $row) {
            $names = array_unique(array_map(static fn (array $illness): string => mb_strtolower($illness[0]), Illnesses::illnessesIn((string) $row->i_gedcom)));

            foreach ($names as $name) {
                $usage[$name][(string) $row->gedcom_name][] = (string) $row->i_id;
            }
        }

        return $usage;
    }

    /**
     * @param array<mixed> $entries
     *
     * @return array<string,array{category:string,icd:string}>
     */
    private function clean(array $entries): array
    {
        $clean = [];
        $seen  = [];

        foreach ($entries as $name => $entry) {
            $name     = is_string($name) ? trim($name) : '';
            $category = is_array($entry) && is_string($entry['category'] ?? null) ? $entry['category'] : '';
            $icd      = is_array($entry) && is_string($entry['icd'] ?? null) ? strtoupper(trim($entry['icd'])) : '';
            $key      = mb_strtolower($name);

            if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH || array_key_exists($key, $seen) || !in_array($category, $this->categoryIds(), true)) {
                continue;
            }

            $seen[$key]   = true;
            $clean[$name] = ['category' => $category, 'icd' => preg_match(self::ICD_PATTERN, $icd) === 1 ? $icd : ''];
        }

        uksort($clean, static fn (string $a, string $b): int => I18N::comparator()($a, $b));

        return $clean;
    }

    /**
     * @return array<mixed>
     */
    private static function starterList(): array
    {
        $language = explode('-', Site::getPreference('LANGUAGE'))[0];

        foreach ([$language, 'en'] as $tag) {
            $file = __DIR__ . '/../resources/catalog/' . $tag . '.php';

            if (preg_match('/^[a-z]+$/', $tag) === 1 && is_file($file)) {
                $list = require $file;

                return is_array($list) ? $list : [];
            }
        }

        return [];
    }
}
