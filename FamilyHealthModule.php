<?php

declare(strict_types=1);

namespace FamilyHealth;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleListInterface;
use Fisharebest\Webtrees\Module\ModuleListTrait;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function explode;
use function in_array;
use function is_array;
use function is_file;
use function preg_match;
use function range;
use function redirect;
use function str_starts_with;

/**
 * The module: metadata, the page "Family health" (menu Lists) and the settings.
 *
 * Illnesses are plain GEDCOM facts in the tree (1 FACT <illness> / 2 TYPE Erkrankung / 2 RESN confidential). Who
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

    public const string DESCRIPTION = 'Record illnesses in the family and sum them up for the doctor – visible only to your own blood relatives.';

    // A text file with the latest version number; webtrees shows an update hint in the module list with it.
    private const string LATEST_VERSION_URL = 'https://raw.githubusercontent.com/Schoaf/webtrees-family-health/main/latest-version.txt';
    private const string SUPPORT_URL        = 'https://github.com/Schoaf/webtrees-family-health';

    // Module setting: up to which degree of relationship illnesses are visible. 0 = no limit.
    private const string MAX_DEGREE_SETTING = 'max_degree';
    private const int    MAX_DEGREE_LIMIT   = 10;

    // The page is for signed-in members; visitors don't see the menu item (changeable in the control panel).
    protected int $access_level = Auth::PRIV_USER;

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
            'title' => $this->title(),
            'tree'  => $tree,
        ]);
    }

    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';

        return $this->viewResponse($this->name() . '::admin', [
            'title'      => $this->title(),
            'max_degree' => $this->maxDegree(),
            'limit'      => self::MAX_DEGREE_LIMIT,
        ]);
    }

    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $max_degree = Validator::parsedBody($request)->integer('max_degree', 0);

        if (!in_array($max_degree, range(0, self::MAX_DEGREE_LIMIT), true)) {
            $max_degree = 0;
        }

        $this->setPreference(self::MAX_DEGREE_SETTING, (string) $max_degree);

        FlashMessages::addMessage(I18N::translate('The preferences for the module “%s” have been updated.', $this->title()), 'success');

        return redirect($this->getConfigLink());
    }

    /**
     * Up to which degree of relationship illnesses are visible; 0 = no limit.
     */
    public function maxDegree(): int
    {
        return (int) $this->getPreference(self::MAX_DEGREE_SETTING, '0');
    }
}
