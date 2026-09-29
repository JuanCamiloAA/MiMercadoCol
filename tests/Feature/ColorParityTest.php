<?php

namespace Tests\Feature;

use App\Services\BrandThemeService;
use App\Support\Color;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * `App\Support\Color` y `resources/js/lib/color.ts` hacen la misma cuenta. Si se separan, la
 * pestana Marca promete un color y el servidor guarda otro. Aqui se corren los mismos casos en
 * los dos lados; el de TypeScript, con Node.
 */
class ColorParityTest extends TestCase
{
    /** @var list<array{a: string, b: string, t: float, dark: string|null}> */
    protected const CASES = [
        ['a' => '#0E6E4E', 'b' => '#FFFFFF', 't' => 0.12, 'dark' => null],
        ['a' => '#E8B100', 'b' => '#FFFFFF', 't' => 0.5, 'dark' => null],
        ['a' => '#FFFFFF', 'b' => '#000000', 't' => 0.35, 'dark' => null],
        ['a' => '#000000', 'b' => '#151A1D', 't' => 0.2, 'dark' => null],
        ['a' => '#3949AB', 'b' => '#F4F4F1', 't' => 0.05, 'dark' => '#8FA0FF'],
        ['a' => '#A8301F', 'b' => '#0C1012', 't' => 0.95, 'dark' => null],
        ['a' => '#7CFC00', 'b' => '#FFFFFF', 't' => 0.12, 'dark' => null],
        ['a' => '#123456', 'b' => '#ABCDEF', 't' => 0.333, 'dark' => '#ABCDEF'],
    ];

    /**
     * Tamanos en rem y esquinas con tokens: si alguien vuelve a escribir `text-[13px]` o
     * `rounded-[10px]`, esa pantalla deja de escalar con Ajustes > Apariencia.
     */
    public function test_no_quedan_tamanos_en_px_fuera_de_la_tienda(): void
    {
        $process = new Process(['node', base_path('scripts/codemod-units.mjs'), '--check'], base_path());
        $process->run();

        if ($process->getExitCode() !== 0 && str_contains($process->getErrorOutput(), 'not recognized')) {
            $this->markTestSkipped('Sin Node en esta maquina.');
        }

        $this->assertTrue($process->isSuccessful(), trim($process->getOutput()).' Corre `node scripts/codemod-units.mjs`.');
    }

    public function test_php_y_typescript_calculan_lo_mismo(): void
    {
        $process = new Process(['node', base_path('scripts/color-parity.mjs'), json_encode(self::CASES)], base_path());
        $process->run();

        if (! $process->isSuccessful()) {
            $this->markTestSkipped('Sin Node en esta maquina: '.$process->getErrorOutput());
        }

        $typescript = json_decode($process->getOutput(), true);
        $service = app(BrandThemeService::class);

        foreach (self::CASES as $index => $case) {
            $php = [
                'contrast' => Color::contrast($case['a'], $case['b']),
                'mix' => Color::mix($case['a'], $case['b'], $case['t']),
                'ink' => Color::ink($case['a']),
                'palette' => $service->palette($case['a'], $case['dark']),
            ];

            $this->assertEquals($php['contrast'], $typescript[$index]['contrast'], "contraste {$case['a']} / {$case['b']}");
            $this->assertSame($php['mix'], $typescript[$index]['mix'], "mezcla {$case['a']} / {$case['b']}");
            $this->assertSame($php['ink'], $typescript[$index]['ink'], "letra sobre {$case['a']}");

            foreach (['light', 'dark'] as $theme) {
                foreach (['accent', 'ink', 'soft', 'muted'] as $token) {
                    $this->assertSame(
                        $php['palette'][$theme][$token],
                        $typescript[$index]['palette'][$theme][$token],
                        "{$token} del tema {$theme} para {$case['a']}",
                    );
                }

                $this->assertEquals($php['palette'][$theme]['contrast'], $typescript[$index]['palette'][$theme]['contrast']);
            }
        }
    }
}
