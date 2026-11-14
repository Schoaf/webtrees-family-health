<?php

declare(strict_types=1);

namespace FamilyHealth;

use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Site;
use Fisharebest\Webtrees\Tree;

use function array_chunk;
use function array_keys;
use function explode;
use function in_array;
use function mb_strlen;
use function min;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function str_replace;
use function trim;

use const PREG_SET_ORDER;

/**
 * The illnesses a user may see: facts "1 FACT <illness> / 2 TYPE Erkrankung" of their blood relatives and of the
 * blood relatives of their children under 18. The rule holds for every role, admins too - who has no individual
 * linked to their account sees none.
 *
 * Read from the accepted records, past webtrees' own privacy (the facts carry RESN confidential): this rule replaces
 * it here. A relative whose name the user may not see is left out entirely.
 */
final class Illnesses
{
    // Values of 2 TYPE that mark a fact as an illness.
    private const string ILLNESS_TYPE = '/^(illness|erkrankung|krankheit)$/i';

    private const int CHUNK = 500;

    // How a new illness is marked in 2 TYPE: in the site's language, so webtrees' own pages read naturally.
    private const array TYPE_BY_LANGUAGE = ['de' => 'Erkrankung'];
    private const string TYPE_DEFAULT    = 'Illness';

    /**
     * Individuals whose illnesses $user may see, with the degree from the nearest of the user's own individual and
     * the user's minor children.
     *
     * @return array<string,int>
     */
    public static function visibleIndividuals(Tree $tree, UserInterface $user, int $max_degree): array
    {
        $own = $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF);

        if ($own === '') {
            return [];
        }

        $kinship = new Kinship($tree);
        $visible = [];

        foreach ([$own, ...$kinship->minorChildren($own)] as $viewer) {
            foreach ($kinship->bloodRelatives($viewer) as $xref => $degree) {
                if ($max_degree === 0 || $degree <= $max_degree) {
                    $visible[$xref] = min($visible[$xref] ?? $degree, $degree);
                }
            }
        }

        return $visible;
    }

    /**
     * @return list<array{individual:Individual,illness:string,category:string,date:string,degree:int}>
     */
    public static function visibleTo(Tree $tree, UserInterface $user, int $max_degree, Catalog $catalog): array
    {
        $visible   = self::visibleIndividuals($tree, $user, $max_degree);
        $illnesses = [];

        foreach (array_chunk(array_keys($visible), self::CHUNK) as $chunk) {
            $rows = DB::table('individuals')->where('i_file', '=', $tree->id())->whereIn('i_id', $chunk)->get(['i_id', 'i_gedcom']);

            foreach ($rows as $row) {
                $found = self::illnessesIn($row->i_gedcom);

                if ($found === []) {
                    continue;
                }

                $individual = Registry::individualFactory()->make($row->i_id, $tree);

                if ($individual === null || !$individual->canShowName()) {
                    continue;
                }

                foreach ($found as [$illness, $date]) {
                    $illnesses[] = [
                        'individual' => $individual,
                        'illness'    => $illness,
                        'category'   => $catalog->find($illness)['category'] ?? 'other',
                        'date'       => $date,
                        'degree'     => $visible[$row->i_id],
                    ];
                }
            }
        }

        return $illnesses;
    }

    /**
     * Records an illness of $individual: 1 FACT <name> / 2 TYPE / 2 DATE / 2 RESN confidential. Goes through pending
     * changes like any edit. A name new to the catalog is added with $category, if one is given.
     *
     * @return string|null what is wrong, in the user's language - null when it is recorded
     */
    public static function record(Individual $individual, string $name, string $date, string $category, Catalog $catalog): string|null
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        $date = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $date));

        if (!$individual->canEdit()) {
            return I18N::translate('You may not change this individual.');
        }

        if ($name === '' || mb_strlen($name) > Catalog::NAME_MAX_LENGTH) {
            return I18N::translate('Enter the name of the illness.');
        }

        if ($date !== '' && !(new Date($date))->isOK()) {
            return I18N::translate('The date is not valid.');
        }

        $known = $catalog->find($name);

        if ($known === null && in_array($category, Catalog::CATEGORIES, true)) {
            $catalog->add($name, $category);
        }

        $language = explode('-', Site::getPreference('LANGUAGE'))[0];
        $gedcom   = '1 FACT ' . str_replace('@', '@@', $known['name'] ?? $name) . "\n2 TYPE " . (self::TYPE_BY_LANGUAGE[$language] ?? self::TYPE_DEFAULT);

        if ($date !== '') {
            $gedcom .= "\n2 DATE " . str_replace('@', '@@', $date);
        }

        $individual->createFact($gedcom . "\n2 RESN confidential", true);

        return null;
    }

    /**
     * @return list<array{0:string,1:string}> illness name and GEDCOM date
     */
    public static function illnessesIn(string $gedcom): array
    {
        $found = [];

        preg_match_all('/\n1 FACT (.*)((?:\n[2-9] .*)*)/', $gedcom, $facts, PREG_SET_ORDER);

        foreach ($facts as $fact) {
            if (preg_match('/\n2 TYPE (.*)/', $fact[2], $type) === 1 && preg_match(self::ILLNESS_TYPE, trim($type[1])) === 1) {
                // GEDCOM doubles an @ in text.
                $date    = preg_match('/\n2 DATE (.*)/', $fact[2], $match) === 1 ? str_replace('@@', '@', trim($match[1])) : '';
                $found[] = [str_replace('@@', '@', trim($fact[1])), $date];
            }
        }

        return $found;
    }
}
