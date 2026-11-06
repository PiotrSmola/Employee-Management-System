<?php

namespace App\Support;

/**
 * Navbar items are Blade partials, one file per item (or group of items):
 *
 *   resources/views/layouts/nav/main/<NN>-<feature>.blade.php   links in the bar
 *   resources/views/layouts/nav/user/<NN>-<feature>.blade.php   entries of the user dropdown
 *
 * The navbar (view composer in AppServiceProvider) includes every partial of a
 * section in file name order, so a
 * feature adds its own file (with its own @can check) instead of editing the
 * navbar. See docs/EXTENDING.md.
 */
final class Navigation
{
    public const MAIN = 'main';

    public const USER = 'user';

    /** @var array<string, string[]> */
    private static array $partials = [];

    /**
     * View names of a section's partials, sorted by file name.
     *
     * @return string[]
     */
    public static function partials(string $section): array
    {
        return self::$partials[$section] ??= self::discover($section);
    }

    /**
     * @return string[]
     */
    private static function discover(string $section): array
    {
        $files = glob(resource_path('views/layouts/nav/'.$section.'/*.blade.php')) ?: [];
        sort($files);

        return array_map(
            fn (string $file) => 'layouts.nav.'.$section.'.'.basename($file, '.blade.php'),
            $files
        );
    }
}
