<?php

declare(strict_types=1);

namespace FamilyHealth;

use Fisharebest\Webtrees\Age;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;

use function array_chunk;
use function array_diff_key;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_keys;
use function array_map;
use function array_slice;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function mb_strlen;
use function mb_strtolower;
use function min;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function str_replace;
use function str_starts_with;
use function trim;

use const ARRAY_FILTER_USE_KEY;
use const PREG_SET_ORDER;

/**
 * The illnesses a user may see: facts "1 FACT <illness> / 2 TYPE Illness" (or Erkrankung, Krankheit) and causes of death (1 DEAT / 2 CAUS) of
 * their blood relatives and of the blood relatives of their children under 18. The rule holds for every role, admins too - who has no individual
 * linked to their account sees none.
 *
 * Read from the accepted records, past webtrees' own privacy (the facts carry RESN confidential): this rule replaces
 * it here. A relative whose name the user may not see is left out entirely, and so is anyone who does not take part
 * (see Participation).
 */
final class Illnesses
{
    // Values of 2 TYPE that mark a fact as an illness.
    private const string ILLNESS_TYPE = '/^(illness|erkrankung|krankheit)$/i';

    private const int CHUNK = 500;

    // How a new illness is marked in 2 TYPE: the same in every language, so a tree stays uniform in an export.
    // webtrees translates the type when it shows the fact (resources/lang).
    private const string TYPE = 'Illness';

    /**
     * Whose family history $user may look at: their own individual and their children under 18 - the people the
     * visibility rule starts from. Empty without a linked individual.
     *
     * @return list<string>
     */
    public static function subjects(Tree $tree, UserInterface $user, Kinship|null $kinship = null): array
    {
        $own = $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF);

        if ($own === '') {
            return [];
        }

        $kinship ??= new Kinship($tree);

        return [$own, ...$kinship->minorChildren($own)];
    }

    /**
     * Individuals whose illnesses $user may see, with the degree from the nearest of the user's own individual and
     * the user's minor children. Whoever does not take part is left out for everybody.
     *
     * @return array<string,int>
     */
    public static function visibleIndividuals(Tree $tree, UserInterface $user, int $max_degree, Kinship|null $kinship = null): array
    {
        return array_diff_key(self::relatives($tree, $user, $max_degree, $kinship), array_flip(Participation::optedOut($tree)));
    }

    /**
     * The relatives whose illnesses $user would see but who do not take part - so they show as such, not as healthy.
     * Only those whose name $user may see.
     *
     * @return array<string,int> individual => degree
     */
    public static function optedOutRelatives(Tree $tree, UserInterface $user, int $max_degree, Kinship|null $kinship = null): array
    {
        return self::named($tree, array_intersect_key(self::relatives($tree, $user, $max_degree, $kinship), array_flip(Participation::optedOut($tree))));
    }

    /**
     * The blood relatives of $user's own individual and minor children, up to $max_degree - before Participation.
     *
     * @return array<string,int>
     */
    private static function relatives(Tree $tree, UserInterface $user, int $max_degree, Kinship|null $kinship): array
    {
        $kinship ??= new Kinship($tree);
        $relatives = [];

        foreach (self::subjects($tree, $user, $kinship) as $viewer) {
            foreach ($kinship->bloodRelatives($viewer) as $xref => $degree) {
                if ($max_degree === 0 || $degree <= $max_degree) {
                    $relatives[$xref] = min($relatives[$xref] ?? $degree, $degree);
                }
            }
        }

        return $relatives;
    }

    /**
     * @param array<string,int> $degrees
     *
     * @return array<string,int> the ones whose name may be shown
     */
    private static function named(Tree $tree, array $degrees): array
    {
        return array_filter($degrees, static fn (string $xref): bool => Registry::individualFactory()->make($xref, $tree)?->canShowName() === true, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return list<array{individual:Individual,illness:string,category:string,icd:string,date:string,degree:int}>
     */
    public static function visibleTo(Tree $tree, UserInterface $user, int $max_degree, Catalog $catalog): array
    {
        return self::read($tree, self::visibleIndividuals($tree, $user, $max_degree), $catalog);
    }

    /**
     * The family history of $subject: illnesses of their blood relatives (not their own), with the degree to
     * $subject. Only for a subject from subjects() and only what $user may see - null for anyone else.
     *
     * @return list<array{individual:Individual,illness:string,category:string,icd:string,date:string,degree:int}>|null
     */
    public static function historyOf(Tree $tree, UserInterface $user, string $subject, int $max_degree, Catalog $catalog): array|null
    {
        $kinship = new Kinship($tree);

        if (!in_array($subject, self::subjects($tree, $user, $kinship), true)) {
            return null;
        }

        return self::read($tree, self::degreesFrom($subject, $max_degree, $kinship, self::visibleIndividuals($tree, $user, $max_degree, $kinship)), $catalog);
    }

    /**
     * The relatives of $subject (from subjects()) who do not take part, with the degree to $subject - for the family
     * history, so a gap shows as one.
     *
     * @return array<string,int>
     */
    public static function optedOutOf(Tree $tree, UserInterface $user, string $subject, int $max_degree): array
    {
        $kinship = new Kinship($tree);

        return self::degreesFrom($subject, $max_degree, $kinship, self::optedOutRelatives($tree, $user, $max_degree, $kinship));
    }

    /**
     * The relatives of $subject among $among, with the degree to $subject - not $subject itself.
     *
     * @param array<string,int> $among
     *
     * @return array<string,int>
     */
    private static function degreesFrom(string $subject, int $max_degree, Kinship $kinship, array $among): array
    {
        $degrees = [];

        foreach ($kinship->bloodRelatives($subject) as $xref => $degree) {
            if ($degree > 0 && isset($among[$xref]) && ($max_degree === 0 || $degree <= $max_degree)) {
                $degrees[$xref] = $degree;
            }
        }

        return $degrees;
    }

    /**
     * Age in whole years at the date of diagnosis - null when either date is missing or the age makes no sense.
     */
    public static function ageAtDiagnosis(Individual $individual, string $date): int|null
    {
        if ($date === '') {
            return null;
        }

        $age = (new Age($individual->getBirthDate(), new Date($date)))->ageYears();

        return $age >= 0 && $age <= 120 ? $age : null;
    }

    /**
     * The illnesses in the accepted records of these individuals.
     *
     * @param array<string,int> $degrees individual => degree
     *
     * @return list<array{individual:Individual,illness:string,category:string,icd:string,date:string,degree:int}>
     */
    private static function read(Tree $tree, array $degrees, Catalog $catalog): array
    {
        $illnesses = [];

        foreach (array_chunk(array_keys($degrees), self::CHUNK) as $chunk) {
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
                    $entry       = $catalog->find($illness);
                    $illnesses[] = [
                        'individual' => $individual,
                        'illness'    => $illness,
                        'category'   => $entry['category'] ?? 'other',
                        'icd'        => $entry['icd'] ?? '',
                        'date'       => $date,
                        'degree'     => $degrees[$row->i_id],
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
        if (!$individual->canEdit()) {
            return I18N::translate('You may not change this individual.');
        }

        if (Participation::isOptedOut($individual->tree(), $individual->xref())) {
            return I18N::translate('This individual does not take part in family health.');
        }

        [$name, $date, $error] = self::checked($name, $date);

        if ($error !== null) {
            return $error;
        }

        $gedcom = '1 FACT ' . str_replace('@', '@@', self::known($name, $category, $catalog)) . "\n2 TYPE " . self::TYPE;

        if ($date !== '') {
            $gedcom .= "\n2 DATE " . str_replace('@', '@@', $date);
        }

        $individual->createFact($gedcom . "\n2 RESN confidential", true);

        return null;
    }

    /**
     * The illness facts of $individual, pending additions included (only editors get those from webtrees), pending
     * deletions left out. Past webtrees' privacy like read() - the caller checks the module's rule.
     *
     * @return list<array{fact:Fact,illness:string,date:string}>
     */
    public static function factsOf(Individual $individual): array
    {
        $found = [];

        foreach ($individual->facts(['FACT'], false, Auth::PRIV_HIDE, true) as $fact) {
            if (!$fact->isPendingDeletion() && self::isIllness($fact)) {
                $found[] = ['fact' => $fact, 'illness' => self::text($fact->value()), 'date' => self::text($fact->attribute('DATE'))];
            }
        }

        return $found;
    }

    /**
     * Changes the name and the date of an illness of $individual. Everything else under the fact (type, notes,
     * sources) stays; RESN confidential is added if it got lost. Goes through pending changes like any edit.
     *
     * @return string|null what is wrong, in the user's language - null when it is changed
     */
    public static function update(Individual $individual, string $fact_id, string $name, string $date, string $category, Catalog $catalog): string|null
    {
        $fact = self::editableFact($individual, $fact_id);

        if (is_string($fact)) {
            return $fact;
        }

        [$name, $date, $error] = self::checked($name, $date);

        if ($error !== null) {
            return $error;
        }

        $known = self::known($name, $category, $catalog);
        $lines = ['1 FACT ' . str_replace('@', '@@', $known)];
        $level = 0;

        // Keep the lines under the fact, minus the old date and whatever sits under it.
        foreach (array_slice(explode("\n", $fact->gedcom()), 1) as $line) {
            $depth = (int) $line;

            if ($level > 0 && $depth > $level) {
                continue;
            }

            $level = 0;

            if (str_starts_with($line, '2 DATE')) {
                $level = 2;
                continue;
            }

            $lines[] = $line;

            if (str_starts_with($line, '2 TYPE') && $date !== '') {
                $lines[] = '2 DATE ' . str_replace('@', '@@', $date);
            }
        }

        if (!in_array('2 RESN confidential', $lines, true)) {
            $lines[] = '2 RESN confidential';
        }

        $individual->updateFact($fact_id, implode("\n", $lines), true);

        return null;
    }

    /**
     * Deletes an illness of $individual - through pending changes like any edit.
     *
     * @return string|null what is wrong, in the user's language - null when it is deleted
     */
    public static function delete(Individual $individual, string $fact_id): string|null
    {
        $fact = self::editableFact($individual, $fact_id);

        if (is_string($fact)) {
            return $fact;
        }

        $individual->deleteFact($fact_id, true);

        return null;
    }

    /**
     * The illness $fact_id of $individual - or what is wrong.
     */
    private static function editableFact(Individual $individual, string $fact_id): Fact|string
    {
        if (!$individual->canEdit()) {
            return I18N::translate('You may not change this individual.');
        }

        foreach (self::factsOf($individual) as $entry) {
            if ($entry['fact']->id() === $fact_id) {
                return $entry['fact'];
            }
        }

        return I18N::translate('This illness does not exist any more.');
    }

    /**
     * The name and the date without control characters, and what is wrong with them.
     *
     * @return array{0:string,1:string,2:string|null}
     */
    private static function checked(string $name, string $date): array
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        $date = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $date));

        if ($name === '' || mb_strlen($name) > Catalog::NAME_MAX_LENGTH) {
            return [$name, $date, I18N::translate('Enter the name of the illness.')];
        }

        if ($date !== '' && !(new Date($date))->isOK()) {
            return [$name, $date, I18N::translate('The date is not valid.')];
        }

        return [$name, $date, null];
    }

    /**
     * The name as the catalog spells it. A name new to the catalog is added with $category, if one is given.
     */
    private static function known(string $name, string $category, Catalog $catalog): string
    {
        $known = $catalog->find($name);

        if ($known === null && in_array($category, Catalog::CATEGORIES, true)) {
            $catalog->add($name, $category);
        }

        return $known['name'] ?? $name;
    }

    private static function isIllness(Fact $fact): bool
    {
        return preg_match(self::ILLNESS_TYPE, trim($fact->attribute('TYPE'))) === 1;
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
                $found[] = [self::text($fact[1]), self::date($fact[2])];
            }
        }

        // The cause of death counts as an illness at the date of death - once, if it is not recorded as one already.
        preg_match_all('/\n1 DEAT\b.*((?:\n[2-9] .*)*)/', $gedcom, $deaths, PREG_SET_ORDER);

        foreach ($deaths as $death) {
            if (preg_match('/\n2 CAUS (.*)/', $death[1], $cause) !== 1 || trim($cause[1]) === '') {
                continue;
            }

            $name  = self::text($cause[1]);
            $names = array_map(static fn (array $illness): string => mb_strtolower($illness[0]), $found);

            if (!in_array(mb_strtolower($name), $names, true)) {
                $found[] = [$name, self::date($death[1])];
            }
        }

        return $found;
    }

    // GEDCOM doubles an @ in text.
    private static function text(string $value): string
    {
        return str_replace('@@', '@', trim($value));
    }

    private static function date(string $lines): string
    {
        return preg_match('/\n2 DATE (.*)/', $lines, $match) === 1 ? self::text($match[1]) : '';
    }
}
