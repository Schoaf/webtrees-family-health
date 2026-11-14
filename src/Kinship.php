<?php

declare(strict_types=1);

namespace FamilyHealth;

use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;

use function log;
use function preg_match;
use function preg_match_all;
use function round;

use const PREG_SET_ORDER;

/**
 * Blood relationship in a tree, read once from webtrees' link table: who descends from whom, ignoring adoptive,
 * foster and sealing parents (2 PEDI) - for illnesses only birth counts.
 *
 * The degree is the genetic one a doctor asks about: parents, children and full siblings are 1st degree, grandparents,
 * aunts, uncles and half siblings 2nd, first cousins 3rd. It follows from the share of genes the two have in common
 * (1/2, 1/4, 1/8 ...), summed over their most recent common ancestors.
 */
final class Kinship
{
    // How far up the search for common ancestors goes.
    private const int MAX_GENERATIONS = 12;

    // A child counts as a minor until this age; without a known birth date until it has children of its own.
    private const int ADULT_AGE = 18;

    // Pedigree values that are not a birth relationship.
    private const string NOT_BY_BIRTH = '/^(adopted|foster|sealing)$/i';

    /** @var array<string,list<string>> individual => birth parents */
    private array $parents = [];

    /** @var array<string,list<string>> individual => birth children */
    private array $children = [];

    public function __construct(private readonly Tree $tree)
    {
        $spouses     = [];
        $child_links = [];

        foreach (DB::table('link')->where('l_file', '=', $tree->id())->whereIn('l_type', ['FAMC', 'FAMS'])->get(['l_from', 'l_type', 'l_to']) as $link) {
            if ($link->l_type === 'FAMS') {
                $spouses[$link->l_to][] = $link->l_from;
            } else {
                $child_links[] = [$link->l_from, $link->l_to];
            }
        }

        $not_by_birth = $this->linksNotByBirth();

        foreach ($child_links as [$child, $family]) {
            if (isset($not_by_birth[$child][$family])) {
                continue;
            }

            foreach ($spouses[$family] ?? [] as $parent) {
                $this->parents[$child][]   = $parent;
                $this->children[$parent][] = $child;
            }
        }
    }

    /**
     * Everyone related by blood to $xref, with the degree: 0 for $xref itself.
     *
     * @return array<string,int>
     */
    public function bloodRelatives(string $xref): array
    {
        $up = $this->ancestors($xref);

        // For each relative: the common ancestors and the generations between them and the two.
        $paths = [];

        foreach ($up as $ancestor => $up_generations) {
            foreach ($this->descendants($ancestor) as $relative => $down_generations) {
                $paths[$relative][$ancestor] = $up_generations + $down_generations;
            }
        }

        $degrees = [];

        foreach ($paths as $relative => $common) {
            $share = 0.0;

            foreach ($common as $ancestor => $generations) {
                if (!$this->hasChildAmong($ancestor, $common)) {
                    $share += 0.5 ** $generations;
                }
            }

            $degrees[$relative] = (int) round(-log($share, 2));
        }

        return $degrees;
    }

    /**
     * Children of $xref who are under 18 - or, without a known birth date, have no children of their own.
     *
     * @return list<string>
     */
    public function minorChildren(string $xref): array
    {
        $minors   = [];
        $adult_jd = Registry::timestampFactory()->now()->subtractYears(self::ADULT_AGE)->julianDay();

        foreach ($this->children[$xref] ?? [] as $child) {
            $birth = $this->birthDate($child);

            if ($birth === null ? ($this->children[$child] ?? []) === [] : $birth->minimumJulianDay() > $adult_jd) {
                $minors[] = $child;
            }
        }

        return $minors;
    }

    /**
     * @return array<string,int> ancestor (and $xref itself) => generations up
     */
    private function ancestors(string $xref): array
    {
        $found = [$xref => 0];
        $next  = [$xref];

        for ($generation = 1; $next !== [] && $generation <= self::MAX_GENERATIONS; $generation++) {
            $current = $next;
            $next    = [];

            foreach ($current as $person) {
                foreach ($this->parents[$person] ?? [] as $parent) {
                    if (!isset($found[$parent])) {
                        $found[$parent] = $generation;
                        $next[]         = $parent;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * @return array<string,int> descendant (and $xref itself) => generations down
     */
    private function descendants(string $xref): array
    {
        $found = [$xref => 0];
        $next  = [$xref];

        for ($generation = 1; $next !== []; $generation++) {
            $current = $next;
            $next    = [];

            foreach ($current as $person) {
                foreach ($this->children[$person] ?? [] as $child) {
                    if (!isset($found[$child])) {
                        $found[$child] = $generation;
                        $next[]        = $child;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Whether a child of $ancestor is a common ancestor too - then $ancestor is not one of the most recent.
     *
     * @param array<string,int> $common
     */
    private function hasChildAmong(string $ancestor, array $common): bool
    {
        foreach ($this->children[$ancestor] ?? [] as $child) {
            if (isset($common[$child])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The birth date from the accepted record, regardless of who may see it - it only decides the visibility rule.
     */
    private function birthDate(string $xref): Date|null
    {
        $gedcom = (string) DB::table('individuals')->where('i_file', '=', $this->tree->id())->where('i_id', '=', $xref)->value('i_gedcom');

        if (preg_match('/\n1 BIRT(?:\n[2-9] .*)*?\n2 DATE (.+)/', $gedcom, $match) !== 1) {
            return null;
        }

        $date = new Date($match[1]);

        return $date->isOK() ? $date : null;
    }

    /**
     * @return array<string,array<string,true>> child => family links that are not by birth
     */
    private function linksNotByBirth(): array
    {
        $links = [];
        $rows  = DB::table('individuals')->where('i_file', '=', $this->tree->id())->where('i_gedcom', 'LIKE', '%2 PEDI%')->get(['i_id', 'i_gedcom']);

        foreach ($rows as $row) {
            preg_match_all('/\n1 FAMC @([^@]+)@((?:\n[2-9] .*)*)/', $row->i_gedcom, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                if (preg_match('/\n2 PEDI (.+)/', $match[2], $pedigree) === 1 && preg_match(self::NOT_BY_BIRTH, $pedigree[1]) === 1) {
                    $links[$row->i_id][$match[1]] = true;
                }
            }
        }

        return $links;
    }
}
