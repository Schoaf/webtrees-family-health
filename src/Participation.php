<?php

declare(strict_types=1);

namespace FamilyHealth;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Tree;

use function array_filter;
use function array_values;
use function explode;
use function implode;
use function in_array;
use function sort;

/**
 * Individuals who do not take part in family health: nobody sees their illnesses - not their relatives, not they
 * themselves, not an admin - and none can be recorded for them. The facts stay in the tree; taking part again shows
 * them again. Set by the individual's own account (for itself and its children under 18) or by a manager of the tree,
 * for instance when someone asks by e-mail.
 *
 * Stored per tree as a list of XREFs in a tree preference - not in the GEDCOM, so it is not exported with it.
 */
final class Participation
{
    private const string PREFERENCE = 'FAMILY_HEALTH_OPT_OUT';

    /**
     * @return list<string>
     */
    public static function optedOut(Tree $tree): array
    {
        return array_values(array_filter(explode(',', $tree->getPreference(self::PREFERENCE))));
    }

    public static function isOptedOut(Tree $tree, string $xref): bool
    {
        return in_array($xref, self::optedOut($tree), true);
    }

    public static function set(Tree $tree, string $xref, bool $is_opted_out): void
    {
        $xrefs = array_values(array_filter(self::optedOut($tree), static fn (string $x): bool => $x !== $xref));

        if ($is_opted_out) {
            $xrefs[] = $xref;
        }

        sort($xrefs);
        $tree->setPreference(self::PREFERENCE, implode(',', $xrefs));
    }

    /**
     * Whether $user may decide for $xref: their own individual, their children under 18, or anyone for a manager.
     */
    public static function maySet(Tree $tree, UserInterface $user, string $xref): bool
    {
        return Auth::isManager($tree, $user) || in_array($xref, Illnesses::subjects($tree, $user), true);
    }
}
