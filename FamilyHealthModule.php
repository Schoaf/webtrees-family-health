<?php

declare(strict_types=1);

namespace FamilyHealth;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Date;
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
use Fisharebest\Webtrees\Module\ModuleListInterface;
use Fisharebest\Webtrees\Module\ModuleListTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\RelationshipService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function array_column;
use function array_flip;
use function array_unique;
use function array_values;
use function array_map;
use function explode;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function preg_match;
use function range;
use function redirect;
use function response;
use function route;
use function str_starts_with;
use function strip_tags;
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
class FamilyHealthModule extends AbstractModule implements ModuleCustomInterface, ModuleConfigInterface, ModuleListInterface
{
    use ModuleConfigTrait;
    use ModuleCustomTrait;
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
            'can_add'    => Auth::isEditor($tree, $user),
            'own'        => Registry::individualFactory()->make($tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF), $tree),
            'catalog'    => $this->catalog()->entries(),
            'add_url'    => route('module', ['module' => $this->name(), 'action' => 'Add', 'tree' => $tree->name()]),
            'categories' => Catalog::categoryNames(),
            'history_url' => $this->historyUrl($tree, ''),
            'module_name' => $this->name(),
        ]);
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
            'categories'  => Catalog::categoryNames(),
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

        foreach (Catalog::CATEGORIES as $id) {
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
            'categories'   => Catalog::categoryNames(),
            'early_age'    => $this->earlyAge(),
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

        return response([
            'subjects'  => $subjects,
            'xref'      => $subject?->xref(),
            'earlyAge'  => $this->earlyAge(),
            'illnesses' => $illnesses,
        ]);
    }

    /**
     * The family history for the page and the JSON: the individuals to choose from, the chosen one and their
     * relatives' illnesses (with age at diagnosis and early onset), sorted by category, degree and name. A 404 for
     * an individual that is not one of the user's own.
     *
     * @return array{subjects:array<string,Individual>,subject:Individual|null,entries:list<array<string,mixed>>}
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
        $order     = array_flip(Catalog::CATEGORIES);

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

        return ['subjects' => $subjects, 'subject' => $subjects[$xref] ?? null, 'entries' => $entries];
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
        $body = json_decode((string) $request->getBody(), true);
        $body = is_array($body) ? $body : [];

        Auth::checkComponentAccess($this, ModuleListInterface::class, $tree, $user);

        $text  = static fn (string $key): string => is_string($body[$key] ?? null) ? $body[$key] : '';
        $error = $this->recordFor($tree, $user, $text('xref'), $text('illness'), $text('date'), $text('category'));

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

        return response(['illnesses' => $illnesses]);
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

        foreach (Catalog::categoryNames() as $id => $name) {
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
            'categories' => Catalog::categoryNames(),
            'catalog'    => $this->catalog()->entries(),
            'missing'    => $this->catalog()->namesNotInCatalog(),
            'max_length' => Catalog::NAME_MAX_LENGTH,
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
            $entries = [];

            foreach (Validator::parsedBody($request)->array('entries') as $row) {
                if (is_array($row) && ($row['delete'] ?? '') === '') {
                    $entries[(string) ($row['name'] ?? '')] = ['category' => (string) ($row['category'] ?? ''), 'icd' => (string) ($row['icd'] ?? '')];
                }
            }

            $this->catalog()->save($entries);
        }

        FlashMessages::addMessage(I18N::translate('The preferences for the module “%s” have been updated.', $this->title()), 'success');

        return redirect($this->getConfigLink());
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
