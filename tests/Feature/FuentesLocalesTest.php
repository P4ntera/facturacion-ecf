<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * El sistema debe verse igual sin internet: ninguna fuente, hoja de estilos o script puede venir
 * de un CDN.
 */
class FuentesLocalesTest extends TestCase
{
    public function test_el_panel_enlaza_las_fuentes_locales_y_no_un_cdn(): void
    {
        $html = Filament::getPanel('admin')->getFontHtml()->toHtml();

        $this->assertStringContainsString('fonts/erp/fuentes.css', $html);
        $this->assertStringNotContainsString('bunny', $html);
        $this->assertStringNotContainsString('googleapis', $html);
    }

    public function test_cada_archivo_de_fuente_declarado_existe_en_el_repo(): void
    {
        $css = (string) file_get_contents(public_path('fonts/erp/fuentes.css'));

        preg_match_all('/url\(\.\/([^)]+)\)/', $css, $coincidencias);

        $this->assertCount(4, $coincidencias[1]);

        foreach ($coincidencias[1] as $archivo) {
            $this->assertFileExists(public_path('fonts/erp/'.$archivo));
        }

        $this->assertStringContainsString("font-family: 'Inter'", $css);
        $this->assertStringContainsString("font-family: 'Manrope'", $css);
    }

    public function test_ni_los_estilos_ni_las_vistas_cargan_recursos_de_un_cdn(): void
    {
        $patron = '/(?:@import\s+url\(|<link[^>]+href=|<script[^>]+src=|url\()\s*[\'"]?https?:\/\//i';
        $infractores = [];

        foreach ([resource_path('css'), resource_path('views'), resource_path('design-system')] as $carpeta) {
            if (! is_dir($carpeta)) {
                continue;
            }

            foreach (File::allFiles($carpeta) as $archivo) {
                if (preg_match($patron, $archivo->getContents()) === 1) {
                    $infractores[] = $archivo->getRelativePathname();
                }
            }
        }

        $this->assertSame([], $infractores, 'Estos archivos cargan recursos externos y fallarían sin internet.');
    }
}
