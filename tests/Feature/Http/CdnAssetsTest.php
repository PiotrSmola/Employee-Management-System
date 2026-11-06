<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Everything loaded from a CDN is pinned to an exact version and protected by a
 * Subresource Integrity hash, so the browser refuses a file changed on the CDN.
 */
class CdnAssetsTest extends TestCase
{
    public function test_cdn_assets_are_pinned_and_have_an_integrity_hash(): void
    {
        foreach (File::allFiles(resource_path('views')) as $view) {
            preg_match_all('/<(?:script|link)\b[^>]*https:\/\/[^>]*>/s', $view->getContents(), $tags);

            foreach ($tags[0] as $tag) {
                $this->assertMatchesRegularExpression('/@\d+\.\d+\.\d+\//', $tag, $view->getPathname());
                $this->assertMatchesRegularExpression('/integrity="sha384-[^"]+"/', $tag, $view->getPathname());
                $this->assertStringContainsString('crossorigin="anonymous"', $tag, $view->getPathname());
            }
        }
    }
}
