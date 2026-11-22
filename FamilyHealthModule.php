<?php

declare(strict_types=1);

namespace FamilyHealth;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Elements\CustomFact;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\Http\Exceptions\HttpNotFoundException;
use Fisharebest\Webtrees\Http\RequestHandlers\AddNewFact;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleGlobalInterface;
use Fisharebest\Webtrees\Module\ModuleGlobalTrait;
use Fisharebest\Webtrees\Module\ModuleListInterface;
use Fisharebest\Webtrees\Module\ModuleListTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\RelationshipService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function array_column;
use function array_diff;
use function array_flip;
use function array_unique;
use function array_values;
use function addcslashes;
use function array_sum;
use function base64_encode;
use function count;
use function array_map;
use function explode;
use function in_array;
use function is_array;
use function is_bool;
use function is_file;
use function is_string;
use function json_decode;
use function mb_strtolower;
use function preg_match;
use function range;
use function redirect;
use function response;
use function route;
use function sprintf;
use function str_starts_with;
use function strip_tags;
use function trim;
use function usort;

/**
 * The module: metadata, the page "Family health" (menu Lists), the family history for the doctor and the settings.
 *
 * Illnesses are plain GEDCOM facts in the tree (1 FACT <illness> / 2 TYPE Illness / 2 RESN confidential). Who
 * sees which is decided by the module itself (blood relatives of your own individual and of your children under 18)
 * - see README.
 *
 * All pages and endpoints use webtrees' module route /module/_family-health_/<Action>[/<tree>].
 */
class FamilyHealthModule extends AbstractModule implements ModuleCustomInterface, ModuleConfigInterface, ModuleGlobalInterface, ModuleListInterface
{
    use ModuleConfigTrait;
    use ModuleCustomTrait;
    use ModuleGlobalTrait;
    use ModuleListTrait;

    // The entry "Illness" in webtrees' list "Add a fact". It only opens the module's form and never reaches the tree:
    // the illness is saved as 1 FACT / 2 TYPE Illness.
    public const string ILLNESS_TAG = '_FAMILY_HEALTH_ILLNESS';

    public const string DESCRIPTION = 'Record illnesses in the family and sum them up for the doctor – visible only to your own blood relatives.';

    // A text file with the latest version number; webtrees shows an update hint in the module list with it.
    private const string LATEST_VERSION_URL = 'https://raw.githubusercontent.com/Schoaf/webtrees-family-health/main/latest-version.txt';
    private const string SUPPORT_URL        = 'https://github.com/Schoaf/webtrees-family-health';

    // Module setting: up to which degree of relationship illnesses are visible. 0 = no limit.
    private const string MAX_DEGREE_SETTING = 'max_degree';
    private const int    MAX_DEGREE_LIMIT   = 10;

    // Module setting: an illness diagnosed below this age is marked as early onset in the family history. 0 = off.
    private const string EARLY_AGE_SETTING = 'early_age';
    private const string EARLY_AGE_DEFAULT = '50';
    private const int    EARLY_AGE_LIMIT   = 99;

    // The page is for signed-in members; visitors don't see the menu item (changeable in the control panel).
    protected int $access_level = Auth::PRIV_USER;

    private Catalog|null $catalog = null;

    public function title(): string
    {
        return I18N::translate('Family health');
    }

    public function description(): string
    {
        return I18N::translate(self::DESCRIPTION);
    }

    /**
     * Source language is English: nothing to translate there. Otherwise resources/lang/<language>.php - first the exact
     * tag ("de-AT"), then the language ("de").
     *
     * @return array<string,string>
     */
    public function customTranslations(string $language): array
    {
        if (str_starts_with($language, 'en')) {
            return [];
        }

        foreach ([$language, explode('-', $language)[0]] as $tag) {
            $file = $this->resourcesFolder() . 'lang/' . $tag . '.php';

            if (preg_match('/^[a-zA-Z-]+$/', $tag) === 1 && is_file($file)) {
                $translations = require $file;

                return is_array($translations) ? $translations : [];
            }
        }

        return [];
    }

    public function customModuleAuthorName(): string
    {
        return 'Andreas Scharf';
    }

    public function customModuleVersion(): string
    {
        return '0.1.0';
    }

    public function customModuleLatestVersionUrl(): string
    {
        return self::LATEST_VERSION_URL;
    }

    public function customModuleSupportUrl(): string
    {
        return self::SUPPORT_URL;
    }

    public function boot(): void
    {
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');

        Registry::elementFactory()->registerTags(['INDI:' . self::ILLNESS_TAG => new CustomFact(I18N::translate('Illness'))]);
        Registry::elementFactory()->registerSubTags(['INDI' => [[self::ILLNESS_TAG, '0:M']]]);

        $container = Registry::container();
        $container->set(AddNewFact::class, new AddIllnessRedirect(
            $container->get(AddNewFact::class),
            fn (Tree $tree, string $xref): string => route('module', ['module' => $this->name(), 'action' => 'Record', 'tree' => $tree->name(), 'xref' => $xref]),
        ));
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/resources/';
    }

    // The icon of the menu item: a heart with a pulse line, in the size of the theme's own icons. The themes fab and minimal
    // show no icons in the menus, so neither does this one.
    private const string MENU_CLASS = 'menu-list-family-health';
    private const string ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="%1$d" height="%1$d">'
        . '<path fill="#d9534f" d="M12 21s-8.5-5.3-8.5-11.2C3.5 6.6 5.8 4.5 8.4 4.5c1.6 0 2.9.8 3.6 2 .7-1.2 2-2 3.6-2 2.6 0 4.9 2.1 4.9 5.3C20.5 15.7 12 21 12 21z"/>'
        . '<path fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" d="M5 11.5h3.5l1.5-3 2.5 6 1.5-3H19"/>'
        . '</svg>';
    private const array ICON_SIZE_BY_THEME = ['webtrees' => 24, 'clouds' => 22, 'colors' => 22, 'xenea' => 22];

    public function listMenuClass(): string
    {
        return self::MENU_CLASS;
    }

    public function headContent(): string
    {
        $css = '';
        foreach (self::ICON_SIZE_BY_THEME as $theme => $size) {
            $svg = sprintf(self::ICON, $size);
            $css .= '.wt-theme-' . $theme . ' .' . self::MENU_CLASS . ':before{content:url("data:image/svg+xml;base64,' . base64_encode($svg) . '")}';
        }

        return '<style>' . $css . '</style>';
    }

    /**
     * The page "Family health" (menu Lists).
     */
    public function getListAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();

        Auth::checkComponentAccess($this, ModuleListInterface::class, $tree, $user);

        return $this->viewResponse($this->name() . '::list', [
            'title'      => $this->title(),
            'tree'       => $tree,
            'is_linked'  => $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF) !== '',
            'illnesses'  => Illnesses::visibleTo($tree, $user, $this->maxDegree(), $this->catalog()),
            'opted_out'  => $this->individualsWithDegree($tree, Illnesses::optedOutRelatives($tree, $user, $this->maxDegree())),
            'can_add'    => Auth::isEditor($tree, $user),
            'own'        => Registry::individualFactory()->make($tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF), $tree),
            'catalog'    => $this->catalog()->entries(),
            'add_url'    => route('module', ['module' => $this->name(), 'action' => 'Add', 'tree' => $tree->name()]),
            'categories' => $this->catalog()->categoryNames(),
            'history_url' => $this->historyUrl($tree, ''),
            'module_name' => $this->name(),
            'participation' => $this->participation($tree, $user),
            'participation_url' => route('module', ['module' => $this->name(), 'action' => 'ParticipationForm', 'tree' => $tree->name()]),
        ]);
    }

    /**
     * Take part or not - the form on the page "Family health". For the user's own individual and children under 18,
     * a manager for anyone.
     */
    public function postParticipationFormAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();
        $body = Validator::parsedBody($request);

        Auth::checkComponentAccess($this, ModuleListInterface::class, $tree, $user);

        $error = $this->setParticipation($tree, $user, $body->string('xref', ''), $body->string('opt_out', '') === '1');

        FlashMessages::addMessage($error ?? I18N::translate('Your choice has been saved.'), $error === null ? 'success' : 'danger');

        return redirect($this->listUrl($tree));
    }

    /**
     * JSON for apps: who the user decides for. {"subjects": [{xref, name, isOptedOut}], "canManage", "optedOut":
     * [{xref, name}]} - optedOut only for a manager, empty otherwise.
     */
    public function getParticipationAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();

        Auth::checkComponentAccess($this, ModuleListInterface::class, $tree, $user);

        $participation = $this->participation($tree, $user);
        $json          = static fn (Individual $individual): array => ['xref' => $individual->xref(), 'name' => strip_tags($individual->fullName())];

        return response([
            'subjects'  => array_map(static fn (array $entry): array => $json($entry['individual']) + ['isOptedOut' => $entry['is_opted_out']], $participation['subjects']),
            'canManage' => $participation['can_manage'],
            'optedOut'  => array_map($json, $participation['opted_out']),
        ]);
    }

    /**
     * JSON for apps: take part or not - {"xref", "isOptedOut": true|false}. Answers {"ok": true} or 400 with error.
     */
    public function postParticipationAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();
        $body = json_decode((string) $request->getBody(), true);
        $xref = is_array($body) && is_string($body['xref'] ?? null) ? $body['xref'] : '';

        Auth::checkComponentAccess($this, ModuleListInterface::class, $tree, $user);

        $error = is_array($body) && is_bool($body['isOptedOut'] ?? null)
            ? $this->setParticipation($tree, $user, $xref, $body['isOptedOut'])
            : I18N::translate('Choose an individual.');

        return response($error === null ? ['ok' => true] : ['ok' => false, 'error' => $error], $error === null ? 200 : 400);
    }

    /**
     * @return string|null what is wrong - null when saved
     */
    private function setParticipation(Tree $tree, UserInterface $user, string $xref, bool $is_opted_out): string|null
    {
        $individual = Registry::individualFactory()->make($xref, $tree);

        if ($individual === null || !$individual->canShowName()) {
            return I18N::translate('Choose an individual.');
        }

        if (!Participation::maySet($tree, $user, $individual->xref())) {
            return I18N::translate('You may not change this individual.');
        }

        Participation::set($tree, $individual->xref(), $is_opted_out);

        return null;
    }

    /**
     * Who the user decides for and, for a manager, everyone who does not take part.
     *
     * @return array{subjects:list<array{individual:Individual,is_opted_out:bool}>,can_manage:bool,opted_out:list<Individual>}
     */
    private function participation(Tree $tree, UserInterface $user): array
    {
        $subjects = [];

        foreach (Illnesses::subjects($tree, $user) as $xref) {
            $individual = Registry::individualFactory()->make($xref, $tree);

            if ($individual !== null && $individual->canShowName()) {
                $subjects[] = ['individual' => $individual, 'is_opted_out' => Participation::isOptedOut($tree, $xref)];
            }
        }

        $can_manage = Auth::isManager($tree, $user);
        $opted_out  = [];

        foreach ($can_manage ? Participation::optedOut($tree) : [] as $xref) {
            $individual = Registry::individualFactory()->make($xref, $tree);

            if ($individual !== null) {
                $opted_out[] = $individual;
            }
        }

        return ['subjects' => $subjects, 'can_manage' => $can_manage, 'opted_out' => $opted_out];
    }

    /**
     * The form to record an illness for one individual - where "Add a fact" → "Illness" leads. Editors only.
     */
    public function getRecordAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();
        $xref = Validator::queryParams($request)->isXref()->string('xref');

        Auth::checkComponentAccess($this, ModuleListInterface::class, $tree, $user);

        $individual = Auth::checkIndividualAccess(Registry::individualFactory()->make($xref, $tree));

        // checkIndividualAccess() lets a reader through, too.
        if (!$individual->canEdit()) {
            throw new HttpAccessDeniedException(I18N::translate('You may not change this individual.'));
        }

        return $this->viewResponse($this->name() . '::record', [
            'title'       => I18N::translate('Record an illness'),
            'tree'        => $tree,
            'individual'  => $individual,
            'catalog'     => $this->catalog()->entries(),
            'categories'  => $this->catalog()->categoryNames(),
            'add_url'     => route('module', ['module' => $this->name(), 'action' => 'Add', 'tree' => $tree->name()]),
            'module_name' => $this->name(),
        ]);
    }

    /**
     * The family history for the doctor: for your own individual or one of your children under 18, the illnesses of
     * their blood relatives, grouped by category. ?xref= chooses the individual, ?category= limits to one category.
     */
    public function getHistoryAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree     = Validator::attributes($request)->tree();
        $category = Validator::queryParams($request)->string('category', '');
        $history  = $this->history($request);
        $groups   = [];

        foreach ($this->catalog()->categoryIds() as $id) {
            foreach ($history['entries'] as $entry) {
                if ($entry['category'] === $id && ($category === '' || $category === $id)) {
                    $groups[$id][] = $entry;
                }
            }
        }

        return $this->viewResponse($this->name() . '::history', [
            'title'        => I18N::translate('Family health history'),
            'tree'         => $tree,
            'is_linked'    => $history['subjects'] !== [],
            'subject'      => $history['subject'],
            'subjects'     => $history['subjects'],
            'groups'       => $groups,
            'used'         => array_values(array_unique(array_column($history['entries'], 'category'))),
            'category'     => $category,
            'categories'   => $this->catalog()->categoryNames(),
            'early_age'    => $this->earlyAge(),
            'opted_out'    => $history['opted_out'],
            'url'          => fn (string $xref, string $category): string => $this->historyUrl($tree, $xref, $category),
            'relationship' => Registry::container()->get(RelationshipService::class),
            'list_url'     => $this->listUrl($tree),
        ]);
    }

    /**
     * JSON for apps: the family history as on the page - ?xref= chooses the individual (default your own).
     * {"subjects": [{xref, name}], "xref", "earlyAge", "illnesses": [{xref, name, relationship, degree, illness,
     * category, icd, date, dateText, age, isEarly}]} - sorted by category, degree and name.
     */
    public function getFamilyHistoryAction(ServerRequestInterface $request): ResponseInterface
    {
        $history      = $this->history($request);
        $relationship = Registry::container()->get(RelationshipService::class);
        $subject      = $history['subject'];
        $illnesses    = [];

        foreach ($history['entries'] as $entry) {
            $illnesses[] = [
                'xref'         => $entry['individual']->xref(),
                'name'         => strip_tags($entry['individual']->fullName()),
                'relationship' => $subject === null ? '' : strip_tags($relationship->getCloseRelationshipName($subject, $entry['individual'])),
                'degree'       => $entry['degree'],
                'illness'      => $entry['illness'],
                'category'     => $entry['category'],
                'icd'          => $entry['icd'],
                'date'         => $entry['date'],
                'dateText'     => $entry['date'] === '' ? '' : strip_tags((new Date($entry['date']))->display()),
                'age'          => $entry['age'],
                'isEarly'      => $entry['is_early'],
            ];
        }

        $subjects = [];

        foreach ($history['subjects'] as $xref => $individual) {
            $subjects[] = ['xref' => $xref, 'name' => strip_tags($individual->fullName())];
        }

        $opted_out = [];

        foreach ($history['opted_out'] as $entry) {
            $opted_out[] = [
                'xref'         => $entry['individual']->xref(),
                'name'         => strip_tags($entry['individual']->fullName()),
                'relationship' => $subject === null ? '' : strip_tags($relationship->getCloseRelationshipName($subject, $entry['individual'])),
                'degree'       => $entry['degree'],
            ];
        }

        return response([
            'subjects'  => $subjects,
            'xref'      => $subject?->xref(),
            'earlyAge'  => $this->earlyAge(),
            'illnesses' => $illnesses,
            // Relatives who do not take part: their illnesses are not known here.
            'optedOut'  => $opted_out,
        ]);
    }

    /**
     * The family history for the page and the JSON: the individuals to choose from, the chosen one and their
     * relatives' illnesses (with age at diagnosis and early onset), sorted by category, degree and name. A 404 for
     * an individual that is not one of the user's own.
     *
     * @return array{subjects:array<string,Individual>,subject:Individual|null,entries:list<array<string,mixed>>,opted_out:list<array{individual:Individual,degree:int}>}
     */
    private function history(ServerRequestInterface $request): array
    {
        $tree     = Validator::attributes($request)->tree();
        $user     = Validator::attributes($request)->user();

        Auth::checkComponentAccess($this, ModuleListInterface::class, $tree, $user);

        $xrefs    = Illnesses::subjects($tree, $user);
        $xref     = Validator::queryParams($request)->string('xref', $xrefs[0] ?? '');
        $entries  = $xrefs === [] ? [] : Illnesses::historyOf($tree, $user, $xref, $this->maxDegree(), $this->catalog());

        if ($entries === null) {
            throw new HttpNotFoundException(I18N::translate('Choose an individual.'));
        }

        $early_age = $this->earlyAge();
        $order     = array_flip($this->catalog()->categoryIds());

        foreach ($entries as $i => $entry) {
            $entries[$i]['age']      = Illnesses::ageAtDiagnosis($entry['individual'], $entry['date']);
            $entries[$i]['is_early'] = $early_age > 0 && $entries[$i]['age'] !== null && $entries[$i]['age'] < $early_age;
        }

        usort($entries, static fn (array $x, array $y): int => $order[$x['category']] <=> $order[$y['category']]
            ?: $x['degree'] <=> $y['degree']
            ?: I18N::comparator()($x['individual']->sortName(), $y['individual']->sortName()));

        $subjects = [];

        foreach ($xrefs as $subject) {
            $individual = Registry::individualFactory()->make($subject, $tree);

            if ($individual !== null) {
                $subjects[$subject] = $individual;
            }
        }

        return [
            'subjects'  => $subjects,
            'subject'   => $subjects[$xref] ?? null,
            'entries'   => $entries,
            'opted_out' => $xrefs === [] ? [] : $this->individualsWithDegree($tree, Illnesses::optedOutOf($tree, $user, $xref, $this->maxDegree())),
        ];
    }

    /**
     * @param array<string,int> $degrees individual => degree
     *
     * @return list<array{individual:Individual,degree:int}> by degree and name
     */
    private function individualsWithDegree(Tree $tree, array $degrees): array
    {
        $list = [];

        foreach ($degrees as $xref => $degree) {
            $individual = Registry::individualFactory()->make((string) $xref, $tree);

            if ($individual !== null) {
                $list[] = ['individual' => $individual, 'degree' => $degree];
            }
        }

        usort($list, static fn (array $x, array $y): int => $x['degree'] <=> $y['degree']
            ?: I18N::comparator()($x['individual']->sortName(), $y['individual']->sortName()));

        return $list;
    }

    private function historyUrl(Tree $tree, string $xref, string $category = ''): string
    {
        $parameters = ['module' => $this->name(), 'action' => 'History', 'tree' => $tree->name()];

        if ($xref !== '') {
            $parameters['xref'] = $xref;
        }

        if ($category !== '') {
            $parameters['category'] = $category;
        }

        return route('module', $parameters);
    }

    /**
     * The form on the page: record an illness. Editors only; goes through pending changes.
     */
    public function postAddAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();
        $body = Validator::parsedBody($request);

        Auth::checkComponentAccess($this, ModuleListInterface::class, $tree, $user);

        $xref  = $body->string('xref', '');
        $error = $this->recordFor($tree, $user, $xref, $body->string('illness', ''), $body->string('date', ''), $body->string('category', ''));

        FlashMessages::addMessage($error ?? I18N::translate('The illness has been recorded. It shows once the change is accepted.'), $error === null ? 'success' : 'danger');

        // From an individual's page: back there, or to the form again on an error.
        $individual = Registry::individualFactory()->make($xref, $tree);

        if ($body->string('from', '') === 'individual' && $individual !== null) {
            return redirect($error === null
                ? $individual->url()
                : route('module', ['module' => $this->name(), 'action' => 'Record', 'tree' => $tree->name(), 'xref' => $xref]));
        }

        return redirect($this->listUrl($tree));
    }

    /**
     * JSON for apps: record an illness - {"xref", "illness", "date"?, "category"?}. Answers {"ok": true} or
     * {"ok": false, "error": "..."}.
     */
    public function postIllnessAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();
        $body = $this->jsonBody($request);

        Auth::checkComponentAccess($this, ModuleListInterface::class, $tree, $user);

        $error = $this->recordFor($tree, $user, $body['xref'], $body['illness'], $body['date'], $body['category']);

        return response($error === null ? ['ok' => true] : ['ok' => false, 'error' => $error], $error === null ? 200 : 400);
    }

    /**
     * @return string|null what is wrong - null when recorded
     */
    private function recordFor(Tree $tree, UserInterface $user, string $xref, string $illness, string $date, string $category): string|null
    {
        if (!Auth::isEditor($tree, $user)) {
            return I18N::translate('You may not change this individual.');
        }

        $individual = Registry::individualFactory()->make($xref, $tree);

        if ($individual === null) {
            return I18N::translate('Choose an individual.');
        }

        return Illnesses::record($individual, $illness, $date, $category, $this->catalog());
    }

    /**
     * JSON for apps: the illnesses the signed-in user may see (same rule as the page).
     */
    public function getIllnessesAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();

        Auth::checkComponentAccess($this, ModuleListInterface::class, $tree, $user);

        $illnesses = array_map(static fn (array $entry): array => [
            'xref'    => $entry['individual']->xref(),
            'name'    => strip_tags($entry['individual']->fullName()),
            'illness' => $entry['illness'],
            'category' => $entry['category'],
            'date'    => $entry['date'],
            'degree'  => $entry['degree'],
        ], Illnesses::visibleTo($tree, $user, $this->maxDegree(), $this->catalog()));

        $opted_out = array_map(static fn (array $entry): array => [
            'xref'   => $entry['individual']->xref(),
            'name'   => strip_tags($entry['individual']->fullName()),
            'degree' => $entry['degree'],
        ], $this->individualsWithDegree($tree, Illnesses::optedOutRelatives($tree, $user, $this->maxDegree())));

        return response(['illnesses' => $illnesses, 'optedOut' => $opted_out]);
    }

    /**
     * JSON for apps: the illnesses of one individual (?xref=) for their page - only if the user may see them (same
     * rule as everywhere). {"illnesses": [{id, illness, category, icd, date, dateText, isPending}], "canEdit",
     * "isOptedOut"}; isOptedOut for a relative who does not take part. id is
     * webtrees' fact id for IllnessUpdate and IllnessDelete. Causes of death are left out: they show with the death.
     */
    public function getPersonIllnessesAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();
        $xref = Validator::queryParams($request)->isXref()->string('xref');

        Auth::checkComponentAccess($this, ModuleListInterface::class, $tree, $user);

        $individual = Registry::individualFactory()->make($xref, $tree);

        if (!$this->isVisible($tree, $user, $individual)) {
            // A relative who does not take part says so - to whoever would see their illnesses otherwise.
            $is_opted_out = $individual !== null && isset(Illnesses::optedOutRelatives($tree, $user, $this->maxDegree())[$individual->xref()]);

            return response(['illnesses' => [], 'canEdit' => false, 'isOptedOut' => $is_opted_out]);
        }

        $illnesses = [];

        foreach (Illnesses::factsOf($individual) as $entry) {
            $known       = $this->catalog()->find($entry['illness']);
            $illnesses[] = [
                'id'        => $entry['fact']->id(),
                'illness'   => $entry['illness'],
                'category'  => $known['category'] ?? 'other',
                'icd'       => $known['icd'] ?? '',
                'date'      => $entry['date'],
                'dateText'  => $entry['date'] === '' ? '' : strip_tags((new Date($entry['date']))->display()),
                'isPending' => $entry['fact']->isPendingAddition(),
            ];
        }

        return response(['illnesses' => $illnesses, 'canEdit' => Auth::isEditor($tree, $user) && $individual->canEdit(), 'isOptedOut' => false]);
    }

    /**
     * JSON for apps: change an illness - {"xref", "id", "illness", "date"?, "category"?}. Answers like Illness.
     */
    public function postIllnessUpdateAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->changeIllness($request, fn (Individual $individual, array $body): string|null => Illnesses::update(
            $individual,
            $body['id'],
            $body['illness'],
            $body['date'],
            $body['category'],
            $this->catalog(),
        ));
    }

    /**
     * JSON for apps: delete an illness - {"xref", "id"}. Answers like Illness.
     */
    public function postIllnessDeleteAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->changeIllness($request, static fn (Individual $individual, array $body): string|null => Illnesses::delete($individual, $body['id']));
    }

    /**
     * An editor changes an illness of an individual whose illnesses they may see.
     *
     * @param callable(Individual,array<string,string>):(string|null) $change what is wrong - null when done
     */
    private function changeIllness(ServerRequestInterface $request, callable $change): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();
        $body = $this->jsonBody($request);

        Auth::checkComponentAccess($this, ModuleListInterface::class, $tree, $user);

        $individual = Registry::individualFactory()->make($body['xref'], $tree);
        $error      = match (true) {
            !Auth::isEditor($tree, $user), !$this->isVisible($tree, $user, $individual) => I18N::translate('You may not change this individual.'),
            default => $change($individual, $body),
        };

        return response($error === null ? ['ok' => true] : ['ok' => false, 'error' => $error], $error === null ? 200 : 400);
    }

    /**
     * Whether the module's rule lets $user see the illnesses of $individual.
     *
     * @phpstan-assert-if-true Individual $individual
     */
    private function isVisible(Tree $tree, UserInterface $user, Individual|null $individual): bool
    {
        return $individual !== null
            && $individual->canShowName()
            && isset(Illnesses::visibleIndividuals($tree, $user, $this->maxDegree())[$individual->xref()]);
    }

    /**
     * The text fields of a JSON body - an empty string for a missing or non-text one.
     *
     * @return array<string,string>
     */
    private function jsonBody(ServerRequestInterface $request): array
    {
        $body = json_decode((string) $request->getBody(), true);
        $text = [];

        foreach (['xref', 'id', 'illness', 'date', 'category'] as $key) {
            $text[$key] = is_array($body) && is_string($body[$key] ?? null) ? $body[$key] : '';
        }

        return $text;
    }

    /**
     * JSON for apps: the categories and the catalog - the suggestions when entering an illness.
     */
    public function getCatalogAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();

        Auth::checkComponentAccess($this, ModuleListInterface::class, $tree, $user);

        $categories = [];

        foreach ($this->catalog()->categoryNames() as $id => $name) {
            $categories[] = ['id' => $id, 'name' => $name];
        }

        $entries = [];

        foreach ($this->catalog()->entries() as $name => $entry) {
            $entries[] = ['name' => $name] + $entry;
        }

        return response(['categories' => $categories, 'illnesses' => $entries]);
    }

    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';

        return $this->viewResponse($this->name() . '::admin', [
            'title'      => $this->title(),
            'max_degree' => $this->maxDegree(),
            'limit'      => self::MAX_DEGREE_LIMIT,
            'early_age'  => $this->earlyAge(),
            'early_limit' => self::EARLY_AGE_LIMIT,
            'categories' => $this->catalog()->categoryNames(),
            'catalog'    => $this->catalog()->entries(),
            'missing'    => $this->catalog()->namesNotInCatalog(),
            'max_length' => Catalog::NAME_MAX_LENGTH,
            'usage'      => $this->usageCounts(),
            'category_max_length' => Catalog::CATEGORY_MAX_LENGTH,
            'fallback_category'   => Catalog::FALLBACK_CATEGORY,
        ]);
    }

    /**
     * How many individuals carry each illness name, with a link to the page that lists them.
     *
     * @return array<string,array{count:int,url:string}> lower-case name => usage
     */
    private function usageCounts(): array
    {
        $usage = [];

        foreach ($this->catalog()->usage() as $name => $per_tree) {
            $usage[$name] = [
                'count' => array_sum(array_map(count(...), $per_tree)),
                'url'   => route('module', ['module' => $this->name(), 'action' => 'AdminIllness', 'name' => $name]),
            ];
        }

        return $usage;
    }

    /**
     * The individuals who have one illness (?name=, in any case), by tree - from the catalog's count. Administrators.
     */
    public function getAdminIllnessAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';

        $name   = Validator::queryParams($request)->string('name', '');
        $trees  = Registry::container()->get(TreeService::class)->all();
        $groups = [];

        foreach ($this->catalog()->usage()[mb_strtolower(trim($name))] ?? [] as $tree_name => $xrefs) {
            $tree = $trees->get($tree_name);

            if (!$tree instanceof Tree) {
                continue;
            }

            $individuals = [];

            foreach ($xrefs as $xref) {
                $individual = Registry::individualFactory()->make($xref, $tree);

                if ($individual !== null) {
                    $individuals[] = $individual;
                }
            }

            usort($individuals, static fn (Individual $x, Individual $y): int => I18N::comparator()($x->sortName(), $y->sortName()));
            $groups[] = ['tree' => $tree, 'individuals' => $individuals];
        }

        return $this->viewResponse($this->name() . '::admin-illness', [
            'title'        => $name,
            'module_title' => $this->title(),
            'groups'       => $groups,
            'back_url'     => $this->getConfigLink(),
        ]);
    }

    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $max_degree = Validator::parsedBody($request)->integer('max_degree', 0);

        if (!in_array($max_degree, range(0, self::MAX_DEGREE_LIMIT), true)) {
            $max_degree = 0;
        }

        $this->setPreference(self::MAX_DEGREE_SETTING, (string) $max_degree);

        $early_age = Validator::parsedBody($request)->integer('early_age', (int) self::EARLY_AGE_DEFAULT);

        if (in_array($early_age, range(0, self::EARLY_AGE_LIMIT), true)) {
            $this->setPreference(self::EARLY_AGE_SETTING, (string) $early_age);
        }

        // The catalog as rows of the form; a row without a category, or marked for deletion, is left out. Only when
        // the form had the catalog at all - a post without it keeps the catalog as it is.
        if (Validator::parsedBody($request)->string('catalog_form', '') === '1') {
            $rows = [];

            foreach (Validator::parsedBody($request)->array('categories') as $row) {
                if (is_array($row)) {
                    $rows[] = ['id' => (string) ($row['id'] ?? ''), 'name' => (string) ($row['name'] ?? ''), 'delete' => ($row['delete'] ?? '') !== ''];
                }
            }

            $before = $this->catalog()->categoryIds();

            if ($rows !== []) {
                $this->catalog()->saveCategories($rows);
            }

            $deleted = array_diff($before, $this->catalog()->categoryIds());
            $entries = [];
            $renamed = [];
            $renames = [];

            foreach (Validator::parsedBody($request)->array('entries') as $row) {
                if (!is_array($row) || ($row['delete'] ?? '') !== '') {
                    continue;
                }

                $name     = trim((string) ($row['name'] ?? ''));
                $original = trim((string) ($row['original'] ?? ''));
                $category = (string) ($row['category'] ?? '');
                // A category deleted in the same form: its illnesses go to "other", like the saved ones.
                $category = in_array($category, $deleted, true) ? Catalog::FALLBACK_CATEGORY : $category;
                $entry    = ['category' => $category, 'icd' => (string) ($row['icd'] ?? '')];

                if ($original !== '' && $name !== '' && $original !== $name) {
                    $renamed[$name]     = $entry;
                    $renames[$original] = $name;
                } else {
                    $entries[$name] = $entry;
                }
            }

            // Renamed to a name the catalog has already: that entry stays (the catalog drops the later duplicate).
            $this->catalog()->save($entries + $renamed);

            if (Validator::parsedBody($request)->string('rename_individuals', '') === '1') {
                foreach ($renames as $old => $new) {
                    FlashMessages::addMessage(I18N::translate('Renamed “%1$s” to “%2$s” – individuals: %3$s', e($old), e($new), I18N::number($this->renameEverywhere($old, $new))), 'info');
                }
            }
        }

        FlashMessages::addMessage(I18N::translate('The preferences for the module “%s” have been updated.', $this->title()), 'success');

        return redirect($this->getConfigLink());
    }

    /**
     * Renames an illness in the facts of every individual in every tree.
     *
     * @return int how many individuals were changed
     */
    private function renameEverywhere(string $old, string $new): int
    {
        $changed = 0;

        foreach (Registry::container()->get(TreeService::class)->all() as $tree) {
            $xrefs = DB::table('individuals')
                ->where('i_file', '=', $tree->id())
                ->where('i_gedcom', 'LIKE', '%' . addcslashes($old, '\\%_') . '%')
                ->pluck('i_id');

            foreach ($xrefs as $xref) {
                $individual = Registry::individualFactory()->make((string) $xref, $tree);

                if ($individual !== null && Illnesses::rename($individual, $old, $new) > 0) {
                    $changed++;
                }
            }
        }

        return $changed;
    }

    public function catalog(): Catalog
    {
        return $this->catalog ??= new Catalog($this);
    }

    /**
     * Up to which degree of relationship illnesses are visible; 0 = no limit.
     */
    public function maxDegree(): int
    {
        return (int) $this->getPreference(self::MAX_DEGREE_SETTING, '0');
    }

    /**
     * Below which age at diagnosis an illness counts as early onset; 0 = not marked.
     */
    public function earlyAge(): int
    {
        return (int) $this->getPreference(self::EARLY_AGE_SETTING, self::EARLY_AGE_DEFAULT);
    }
}
