<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../libs/InnerTube.php';

/**
 * Testvektoren unter tests/vectors: gekürzte echte Antworten vom 2026-10-29
 * (ohne Tracking, Besucherkennung und Liedtext, Kontoname ersetzt).
 */
class InnerTubeTest extends TestCase
{
    public function testMusicHome(): void
    {
        $Result = \YouTubeMusic\InnerTubeParser::Shelves($this->Vector('music_home'));
        $this->assertCount(2, $Result['shelves']);
        $this->assertSame('Push dich aus dem Bett', $Result['shelves'][0]['title']);
        $Item = $Result['shelves'][0]['items'][0];
        $this->assertSame(\YouTubeMusic\ItemType::Playlist, $Item['type']);
        $this->assertSame('Gute Laune mit Pop und Rock', $Item['title']);
        $this->assertSame('VLRDCLAK5uy_m0wlRoNn5iCTTgBedfoOQ19Jq9P3XTLIA', $Item['browseId']);
        $this->assertSame('RDCLAK5uy_m0wlRoNn5iCTTgBedfoOQ19Jq9P3XTLIA', $Item['playlistId']);
        $this->assertStringStartsWith('https://', $Item['thumbnail']);
        $this->assertNotSame('', $Result['continuation']);
    }

    public function testMusicCharts(): void
    {
        $Shelves = \YouTubeMusic\InnerTubeParser::Shelves($this->Vector('music_charts'))['shelves'];
        $this->assertSame('Top-Künstler', $Shelves[1]['title']);
        $this->assertSame(\YouTubeMusic\ItemType::Artist, $Shelves[1]['items'][0]['type']);
        $this->assertStringStartsWith('UC', $Shelves[1]['items'][0]['browseId']);
    }

    public function testMusicMoods(): void
    {
        $Shelves = \YouTubeMusic\InnerTubeParser::Shelves($this->Vector('music_moods'))['shelves'];
        $this->assertSame('Stimmungen & Momente', $Shelves[0]['title']);
        $Item = $Shelves[0]['items'][0];
        $this->assertSame(\YouTubeMusic\ItemType::Category, $Item['type']);
        $this->assertSame('FEmusic_moods_and_genres_category', $Item['browseId']);
        $this->assertNotSame('', $Item['params']);
    }

    public function testMusicSearchSongs(): void
    {
        $Result = \YouTubeMusic\InnerTubeParser::Shelves($this->Vector('music_search'));
        $Item = $Result['shelves'][0]['items'][0];
        $this->assertSame('Titel', $Result['shelves'][0]['title']);
        $this->assertSame(\YouTubeMusic\ItemType::Song, $Item['type']);
        $this->assertSame('4D7u5KF7SP8', $Item['videoId']);
        $this->assertSame(['Daft Punk', 'Pharrell Williams', 'Nile Rodgers'], $Item['artists']);
        $this->assertSame('Random Access Memories', $Item['album']);
        $this->assertSame('6:10', $Item['duration']);
    }

    public function testMusicQueue(): void
    {
        $Queue = \YouTubeMusic\InnerTubeParser::Queue($this->Vector('music_next'), '4D7u5KF7SP8');
        $this->assertSame('RDAMVM4D7u5KF7SP8', $Queue['playlistId']);
        $this->assertSame('MPLYt_K8qWMWVqXGi-8', $Queue['lyricsBrowseId']);
        $this->assertSame('4D7u5KF7SP8', $Queue['items'][0]['videoId']);
        $this->assertSame('6:10', $Queue['items'][0]['duration']);
        $this->assertSame('I Feel It Coming (feat. Daft Punk)', $Queue['items'][1]['title']);
        $this->assertSame(['The Weeknd'], $Queue['items'][1]['artists']);
        $this->assertSame('Starboy', $Queue['items'][1]['album']);
    }

    public function testLyrics(): void
    {
        $Lyrics = \YouTubeMusic\InnerTubeParser::Lyrics($this->Vector('music_lyrics'));
        $this->assertSame("Zeile eins\nZeile zwei\n\nZeile drei", $Lyrics['text']);
        $this->assertSame('Quelle: LyricFind', $Lyrics['source']);
    }

    // Struktur einer echten Antwort des Android-Clients, Liedtext durch Platzhalter ersetzt (Urheberrecht)
    public function testTimedLyrics(): void
    {
        $Lyrics = \YouTubeMusic\InnerTubeParser::TimedLyrics($this->Vector('android_lyrics_timed'));
        $this->assertSame('Quelle: LyricFind', $Lyrics['source']);
        $this->assertCount(3, $Lyrics['lines']);
        $this->assertSame(['text' => 'Zeile eins', 'start' => 8540, 'end' => 12540], $Lyrics['lines'][1]);
    }

    public function testTimedLyricsWithoutTimes(): void
    {
        $this->assertSame([], \YouTubeMusic\InnerTubeParser::TimedLyrics($this->Vector('android_lyrics_untimed'))['lines']);
    }

    public function testTvLibrary(): void
    {
        $Items = \YouTubeMusic\InnerTubeParser::Shelves($this->Vector('tv_library'))['shelves'][0]['items'];
        $this->assertSame('Musik, die ich mag', $Items[0]['title']);
        $this->assertSame(\YouTubeMusic\ItemType::Playlist, $Items[0]['type']);
        $this->assertSame('LM', $Items[0]['playlistId']);
        $this->assertSame('TestUser • Playlist • 10 Titel', $Items[1]['subtitle']);
    }

    public function testTvQueue(): void
    {
        $Queue = \YouTubeMusic\InnerTubeParser::Queue($this->Vector('tv_next'), '4D7u5KF7SP8');
        $this->assertSame('RDAMVM4D7u5KF7SP8', $Queue['playlistId']);
        $this->assertSame('INDIFFERENT', $Queue['likeStatus']);
        $this->assertSame('4D7u5KF7SP8', $Queue['items'][0]['videoId']);
        // ohne TV-Gegenstück (Get Lucky als Video) und ohne Empfehlungen anderer Regale
        $this->assertSame('Holding Out for a Hero', $Queue['items'][1]['title']);
        foreach ($Queue['items'] as $Item) {
            $this->assertSame('RDAMVM4D7u5KF7SP8', $Item['playlistId']);
        }
        // keine doppelten Titel
        $Ids = array_column($Queue['items'], 'videoId');
        $this->assertSame($Ids, array_values(array_unique($Ids)));
    }

    public function testRequests(): void
    {
        $Context = \YouTubeMusic\InnerTubeClient::Context('WEB_REMIX', '1.2', 'de', 'DE', 'vd');
        $Body = json_decode(\YouTubeMusic\InnerTubeClient::Body($Context, \YouTubeMusic\InnerTubeClient::Search('Daft Punk', \YouTubeMusic\ItemType::Song)), true);
        $this->assertSame('WEB_REMIX', $Body['context']['client']['clientName']);
        $this->assertSame('vd', $Body['context']['client']['visitorData']);
        $this->assertSame('Daft Punk', $Body['query']);
        $this->assertSame(\YouTubeMusic\InnerTubeClient::SearchFilter['song'], $Body['params']);
        $this->assertSame('RDAMVMabcdefghijk', \YouTubeMusic\InnerTubeClient::Next('abcdefghijk')['playlistId']);
        $this->assertSame(['browseId' => 'FEmusic_home'], \YouTubeMusic\InnerTubeClient::Browse(\YouTubeMusic\InnerTubeClient::Home));
    }

    public function testLargeThumbnail(): void
    {
        $P = static fn (string $Url): string => \YouTubeMusic\InnerTubeParser::LargeThumbnail($Url);
        $this->assertSame('https://yt3.googleusercontent.com/abc=w544-h544-l90-rj', $P('https://yt3.googleusercontent.com/abc=w120-h120-l90-rj'));
        $this->assertSame('https://yt3.ggpht.com/abc=s544', $P('https://yt3.ggpht.com/abc=s576'));
        $this->assertSame('https://i.ytimg.com/vi/x/hq720.jpg?sqp=a', $P('https://i.ytimg.com/vi/x/hq720.jpg?sqp=a'));
    }

    public function testParseConfig(): void
    {
        $Html = '<script>ytcfg.set({"INNERTUBE_CLIENT_VERSION":"1.20261027.10.00","VISITOR_DATA":"CgtXYZ%3D%3D"});</script>';
        $this->assertSame(['clientVersion' => '1.20261027.10.00', 'visitorData' => 'CgtXYZ%3D%3D'], \YouTubeMusic\InnerTubeClient::ParseConfig($Html));
    }

    private function Vector(string $Name): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/vectors/' . $Name . '.json'), true);
    }
}
