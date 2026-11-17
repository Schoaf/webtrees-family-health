<?php

declare(strict_types=1);

namespace FamilyHealth;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Site;

use function array_key_exists;
use function explode;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_strlen;
use function mb_strtolower;
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
    public const array CATEGORIES = [
        'cardiovascular', 'cancer', 'metabolic', 'autoimmune', 'neurological', 'mental', 'lung', 'kidney', 'hereditary', 'other',
    ];

    private const string SETTING = 'catalog';

    public const int NAME_MAX_LENGTH = 120;

    // ICD-10 code: letter, two digits, optionally a point and up to four more characters (C50, E11.9, Q87.4).
    private const string ICD_PATTERN = '/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,4})?$/';

    /** @var array<string,array{category:string,icd:string}>|null */
    private array|null $entries = null;

    public function __construct(private readonly AbstractModule $module)
    {
    }

    /**
     * @return array<string,string> category => its name in the user's language
     */
    public static function categoryNames(): array
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
                $this->entries = self::clean($stored);
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
        $this->entries = self::clean($entries);
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
     * @param array<mixed> $entries
     *
     * @return array<string,array{category:string,icd:string}>
     */
    private static function clean(array $entries): array
    {
        $clean = [];
        $seen  = [];

        foreach ($entries as $name => $entry) {
            $name     = is_string($name) ? trim($name) : '';
            $category = is_array($entry) && is_string($entry['category'] ?? null) ? $entry['category'] : '';
            $icd      = is_array($entry) && is_string($entry['icd'] ?? null) ? strtoupper(trim($entry['icd'])) : '';
            $key      = mb_strtolower($name);

            if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH || array_key_exists($key, $seen) || !in_array($category, self::CATEGORIES, true)) {
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
