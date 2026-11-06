<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Blade stays thin: logic belongs in controllers, services, components and view
 * composers, scripts in public/js. PDFs keep their styles: DomPDF needs them inline.
 */
class CleanViewsTest extends TestCase
{
    public function test_views_hold_no_php_blocks_or_inline_scripts(): void
    {
        foreach (File::allFiles(resource_path('views')) as $view) {
            $source = $view->getContents();

            $this->assertStringNotContainsString('@php', $source, $view->getPathname());
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/', $source, $view->getPathname());
            $this->assertStringNotContainsString('Carbon', $source, $view->getPathname());
        }
    }

    public function test_the_date_directive_formats_and_escapes(): void
    {
        $this->assertSame('01.02.1995', trim($this->rendered("@date('1995-02-01')")));
        $this->assertSame('Feb 01, 1995', trim($this->rendered("@date('1995-02-01', 'M d, Y')")));
        $this->assertSame('', trim($this->rendered('@date(null)')));
    }

    private function rendered(string $template): string
    {
        return Blade::render($template);
    }
}
