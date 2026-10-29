<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../libs/TileTexts.php';

/**
 * Jeder Text der Kachel steht in der locale.json des Players.
 */
class TileTextsTest extends TestCase
{
    public function testAllTextsAreTranslated(): void
    {
        $Locale = json_decode((string) file_get_contents(__DIR__ . '/../YouTube Music Player/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];
        foreach (\YouTubeMusic\TileTexts::All as $Text) {
            $this->assertArrayHasKey($Text, $Locale, 'Nicht übersetzt: ' . $Text);
        }
        $this->assertSame(\YouTubeMusic\TileTexts::All, array_values(array_unique(\YouTubeMusic\TileTexts::All)));
    }
}
