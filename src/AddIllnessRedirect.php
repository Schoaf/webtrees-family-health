<?php

declare(strict_types=1);

namespace FamilyHealth;

use Closure;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function redirect;

/**
 * Takes webtrees' page "Add a fact" (AddNewFact): for the entry "Illness" in its list it opens the module's own form
 * instead, every other fact goes to webtrees' page as before. webtrees has no hook for a form of its own per fact, so
 * the module puts this in front of AddNewFact in the container (see FamilyHealthModule::boot()).
 */
final class AddIllnessRedirect implements RequestHandlerInterface
{
    /**
     * @param Closure(Tree,string):string $record_url the module's form for an individual
     */
    public function __construct(
        private readonly RequestHandlerInterface $add_new_fact,
        private readonly Closure $record_url,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (Validator::attributes($request)->string('fact', '') !== FamilyHealthModule::ILLNESS_TAG) {
            return $this->add_new_fact->handle($request);
        }

        $tree = Validator::attributes($request)->tree();
        $xref = Validator::attributes($request)->isXref()->string('xref');

        return redirect(($this->record_url)($tree, $xref));
    }
}
