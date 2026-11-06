<?php

namespace Tests\Feature\Http;

use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The PDF exports render with config/dompdf.php as it is, so these options are
 * what protects them.
 */
class PdfRenderingOptionsTest extends TestCase
{
    public function test_pdfs_cannot_run_code_or_reach_remote_files(): void
    {
        $options = Pdf::loadHTML('<p>x</p>')->getDomPDF()->getOptions();

        $this->assertFalse($options->getIsPhpEnabled());
        $this->assertFalse($options->getIsJavascriptEnabled());
        $this->assertFalse($options->getIsRemoteEnabled());
        $this->assertSame(['file://'], array_keys($options->getAllowedProtocols()));
    }

    public function test_local_files_are_limited_to_the_dompdf_package(): void
    {
        $options = Pdf::loadHTML('<p>x</p>')->getDomPDF()->getOptions();
        $package = dirname((new \ReflectionClass(Dompdf::class))->getFileName(), 2);

        $this->assertSame([$package], $options->getChroot());
        $this->assertNotEmpty($options->getAllowedProtocols()['file://']['rules']);
    }

    /**
     * setOptions() replaces the whole configuration (including the options
     * checked above); setOption() changes a single value.
     */
    public function test_the_application_does_not_replace_the_pdf_configuration(): void
    {
        foreach (File::allFiles(app_path()) as $file) {
            $this->assertStringNotContainsString('->setOptions(', $file->getContents(), $file->getPathname());
        }
    }
}
