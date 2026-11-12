<?php

/**
 * Family Health History - record illnesses in the family tree and sum them up as a family health history.
 *
 * Installation: copy this folder to modules_v4/family-health. The webtrees core stays unchanged.
 * The module class lies next to this file, its parts under src/.
 */

declare(strict_types=1);

namespace FamilyHealth;

use function is_file;
use function spl_autoload_register;
use function str_starts_with;
use function strlen;
use function substr;

spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';

    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/src/' . substr($class, strlen($prefix)) . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
});

require_once __DIR__ . '/FamilyHealthModule.php';

return new FamilyHealthModule();
