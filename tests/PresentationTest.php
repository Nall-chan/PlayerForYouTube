<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

include_once __DIR__ . '/stubs/ConstantStubs.php';
require_once __DIR__ . '/../libs/Lounge.php';
require_once __DIR__ . '/../libs/Variables.php';

/**
 * Prüft alle Statusvariablen gegen die Parameterlisten aus IPS_GetPresentations (tests/presentations.json)
 * und ob jeder Name in der locale.json des Players übersetzt ist.
 */
class PresentationTest extends TestCase
{
    public function testParametersAreKnownToPresentation(): void
    {
        $Presentations = json_decode((string) file_get_contents(__DIR__ . '/presentations.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (\YouTubeMusic\VariablePresentation::Definitions() as $Definition) {
            $Where = $Definition['ident'];
            $Parameters = $Definition['presentation'];
            $Presentation = $Presentations[$Parameters['PRESENTATION']] ?? null;
            $this->assertNotNull($Presentation, $Where . ': unbekannte Darstellung');
            $this->assertContains($Definition['type'], $Presentation['variableTypes'], $Where . ': Variablentyp passt nicht');
            if ($Presentation['requestAction'] === true) {
                $this->assertTrue($Definition['action'], $Where . ': Darstellung braucht eine Aktion');
            }
            if ($Presentation['requestAction'] === false) {
                $this->assertFalse($Definition['action'], $Where . ': Darstellung ohne Aktion');
            }
            unset($Parameters['PRESENTATION']);
            foreach ($Parameters as $Name => $Value) {
                $this->assertArrayHasKey($Name, $Presentation['parameters'], $Where . ': Parameter ' . $Name . ' unbekannt');
                $Spec = $Presentation['parameters'][$Name];
                if (isset($Spec['variableTypes'])) {
                    $this->assertContains($Definition['type'], $Spec['variableTypes'], $Where . ': ' . $Name . ' nicht für diesen Typ');
                }
                $Type = $Spec['type'] === 'variableType' ? ($Definition['type'] === \YouTubeMusic\VariablePresentation::TypeFloat ? 'float' : 'int') : $Spec['type'];
                match ($Type) {
                    'bool'   => $this->assertIsBool($Value, $Where . ':' . $Name),
                    'int'    => $this->assertIsInt($Value, $Where . ':' . $Name),
                    'float'  => $this->assertIsFloat($Value, $Where . ':' . $Name),
                    'string' => $this->assertIsString($Value, $Where . ':' . $Name),
                };
                if (isset($Spec['values'])) {
                    $this->assertContains($Value, $Spec['values'], $Where . ':' . $Name . ' Wert unzulässig');
                }
            }
        }
    }

    public function testIdentsAreUniqueAndOrdered(): void
    {
        $Definitions = \YouTubeMusic\VariablePresentation::Definitions();
        $Idents = array_column($Definitions, 'ident');
        $this->assertSame($Idents, array_values(array_unique($Idents)));
        $Positions = array_column($Definitions, 'position');
        $Sorted = $Positions;
        sort($Sorted);
        $this->assertSame($Sorted, $Positions);
    }

    public function testAllNamesAreTranslated(): void
    {
        $Locale = json_decode((string) file_get_contents(__DIR__ . '/../YouTube Music Player/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];
        foreach (\YouTubeMusic\VariablePresentation::Definitions() as $Definition) {
            $this->assertArrayHasKey($Definition['name'], $Locale, 'Nicht übersetzt: ' . $Definition['name']);
        }
    }

    public function testPlaybackFromLoungeState(): void
    {
        $this->assertSame(\YouTubeMusic\Playback::Play, \YouTubeMusic\Playback::FromLoungeState(\YouTubeMusic\LoungeState::Playing));
        $this->assertSame(\YouTubeMusic\Playback::Play, \YouTubeMusic\Playback::FromLoungeState(\YouTubeMusic\LoungeState::Buffering));
        $this->assertSame(\YouTubeMusic\Playback::Pause, \YouTubeMusic\Playback::FromLoungeState(\YouTubeMusic\LoungeState::Paused));
        $this->assertSame(\YouTubeMusic\Playback::Stop, \YouTubeMusic\Playback::FromLoungeState(\YouTubeMusic\LoungeState::Unknown));
        $this->assertSame(\YouTubeMusic\Playback::Stop, \YouTubeMusic\Playback::FromLoungeState(\YouTubeMusic\LoungeState::Stopped));
    }
}
