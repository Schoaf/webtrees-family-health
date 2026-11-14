<?php

declare(strict_types=1);

namespace FamilyHealth;

use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;

use function array_chunk;
use function array_keys;
use function min;
use function preg_match;
use function preg_match_all;
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
     * @return list<array{individual:Individual,illness:string,date:string,degree:int}>
     */
    public static function visibleTo(Tree $tree, UserInterface $user, int $max_degree): array
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
                    $illnesses[] = ['individual' => $individual, 'illness' => $illness, 'date' => $date, 'degree' => $visible[$row->i_id]];
                }
            }
        }

        return $illnesses;
    }

    /**
     * @return list<array{0:string,1:string}> illness name and GEDCOM date
     */
    private static function illnessesIn(string $gedcom): array
    {
        $found = [];

        preg_match_all('/\n1 FACT (.*)((?:\n[2-9] .*)*)/', $gedcom, $facts, PREG_SET_ORDER);

        foreach ($facts as $fact) {
            if (preg_match('/\n2 TYPE (.*)/', $fact[2], $type) === 1 && preg_match(self::ILLNESS_TYPE, trim($type[1])) === 1) {
                $date    = preg_match('/\n2 DATE (.*)/', $fact[2], $match) === 1 ? trim($match[1]) : '';
                $found[] = [trim($fact[1]), $date];
            }
        }

        return $found;
    }
}
