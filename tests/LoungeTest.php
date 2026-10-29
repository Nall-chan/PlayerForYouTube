<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../libs/Lounge.php';

/**
 * Antworten nach dem Format aus Spike a3 (Chromecast, 2026-10-29), Kennungen ersetzt.
 */
class LoungeTest extends TestCase
{
    public function testTokens(): void
    {
        $Body = '{"screens":[{"screenId":"5a51abc","refreshIntervalInMillis":1123200000,"loungeTokenLifespanMs":1209600000,"loungeToken":"AELpD4t","expiration":1794390039096}]}';
        $this->assertSame([['screenId' => '5a51abc', 'loungeToken' => 'AELpD4t', 'expiration' => 1794390039]], \YouTubeMusic\Lounge::ParseTokens(200, $Body));
        $this->assertSame([], \YouTubeMusic\Lounge::ParseTokens(500, $Body));
        $this->assertSame(['screen_ids' => 'a,b'], \YouTubeMusic\Lounge::TokenRequest(['a', 'b']));
    }

    public function testPairing(): void
    {
        $this->assertSame(['pairing_code' => '123456789012'], \YouTubeMusic\Lounge::PairingRequest('123 456 789 012'));
        $Screen = \YouTubeMusic\Lounge::ParsePairing(200, '{"screen":{"screenId":"s1","loungeToken":"t1","name":"Wohnzimmer TV","expiration":1794390039096}}');
        $this->assertSame('Wohnzimmer TV', $Screen['name']);
        $this->assertNull(\YouTubeMusic\Lounge::ParsePairing(404, ''));
    }

    public function testHandshakeChunks(): void
    {
        $Devices = json_encode([['app' => 'lb-v4', 'name' => 'Büro', 'type' => 'LOUNGE_SCREEN'], ['name' => 'Symcon', 'type' => 'REMOTE_CONTROL']]);
        $Buffer = self::Chunk([
            [0, ['c', '96C167E7DF658AC9', '', 8]],
            [1, ['S', 'TzeZNgmfjK8f']],
            [2, ['loungeStatus', ['devices' => $Devices]]],
            [3, ['playlistModified', ['listId' => 'RDAMVM4D7u5KF7SP8', 'videoId' => '4D7u5KF7SP8']]],
            [4, ['onAutoplayModeChanged', ['autoplayMode' => 'UNSUPPORTED']]],
            [5, ['onPlaylistModeChanged', ['shuffleEnabled' => 'false', 'loopEnabled' => 'true']]],
        ]);
        $Events = \YouTubeMusic\Lounge::ParseChunks($Buffer);
        $this->assertSame('', $Buffer);
        $this->assertCount(6, $Events);

        $Session = \YouTubeMusic\Lounge::NewSession('token', 'Symcon');
        $this->assertTrue(\YouTubeMusic\Lounge::ApplySession($Session, $Events));
        $this->assertSame('96C167E7DF658AC9', $Session['sid']);
        $this->assertSame('TzeZNgmfjK8f', $Session['gsid']);
        $this->assertSame(5, $Session['aid']);

        $State = [];
        \YouTubeMusic\Lounge::ApplyState($State, $Events, 100.0);
        $this->assertSame('Büro', $State['screenName']);
        $this->assertTrue($State['screenConnected']);

        // Nur die eigene Fernbedienung in der Lounge: App läuft nicht, Screen-ID veraltet
        $Remote = json_encode([['name' => 'Symcon', 'type' => 'REMOTE_CONTROL']]);
        \YouTubeMusic\Lounge::ApplyState($State, [[6, 'loungeStatus', ['devices' => $Remote]]], 101.0);
        $this->assertFalse($State['screenConnected']);
        $this->assertSame('RDAMVM4D7u5KF7SP8', $State['listId']);
        $this->assertTrue($State['loop']);
        $this->assertFalse($State['shuffle']);
    }

    public function testChunksSplitAcrossReads(): void
    {
        // Mehrbyte- und Nicht-BMP-Zeichen: Die Länge zählt UTF-16-Einheiten, nicht Bytes
        $Full = self::Chunk([[7, ['nowPlaying', ['videoId' => 'abcdefghijk', 'title' => 'Lied 🎵 für dich', 'currentTime' => '1.5', 'duration' => '200', 'state' => '1']]]])
            . self::Chunk([[8, ['noop']]]);
        $Buffer = substr($Full, 0, 30);
        $this->assertSame([], \YouTubeMusic\Lounge::ParseChunks($Buffer));
        $Buffer .= substr($Full, 30);
        $Events = \YouTubeMusic\Lounge::ParseChunks($Buffer);
        $this->assertSame('', $Buffer);
        $this->assertSame([7, 8], array_column($Events, 0));
        $this->assertSame('Lied 🎵 für dich', $Events[0][2]['title']);
        $this->assertSame('noop', $Events[1][1]);
    }

    public function testStateAndPosition(): void
    {
        $State = [];
        $Changed = \YouTubeMusic\Lounge::ApplyState($State, [
            [13, 'nowPlaying', ['videoId' => '4D7u5KF7SP8', 'listId' => 'RDAMVM4D7u5KF7SP8', 'currentTime' => '37.001', 'duration' => '370', 'state' => '1']],
            [9, 'onVolumeChanged', ['volume' => '20', 'muted' => 'false']],
            [7, 'onHasPreviousNextChanged', ['hasPrevious' => 'false', 'hasNext' => 'true']],
        ], 1000.0);
        $this->assertTrue($Changed);
        $this->assertSame(\YouTubeMusic\LoungeState::Playing, $State['state']);
        $this->assertSame(20, $State['volume']);
        $this->assertTrue($State['hasNext']);
        $this->assertEqualsWithDelta(47.001, \YouTubeMusic\Lounge::Position($State, 1010.0), 0.001);

        // Pause: Position bleibt stehen
        \YouTubeMusic\Lounge::ApplyState($State, [[16, 'onStateChange', ['currentTime' => '41.813', 'duration' => '370', 'state' => '2']]], 1005.0);
        $this->assertSame(\YouTubeMusic\LoungeState::Paused, $State['state']);
        $this->assertEqualsWithDelta(41.813, \YouTubeMusic\Lounge::Position($State, 2000.0), 0.001);

        // Nur noop: keine Änderung
        $this->assertFalse(\YouTubeMusic\Lounge::ApplyState($State, [[17, 'noop', []]], 1006.0));

        // Nächster Titel ohne Dauer in nowPlaying: Dauer und Position des vorherigen verwerfen
        \YouTubeMusic\Lounge::ApplyState($State, [[22, 'nowPlaying', ['videoId' => 'K0HSD_i2DvA', 'currentTime' => '0', 'duration' => '0', 'state' => '3']]], 1007.0);
        $this->assertSame(0.0, $State['duration']);
        $this->assertSame(\YouTubeMusic\LoungeState::Buffering, $State['state']);
    }

    public function testCommandCounters(): void
    {
        $Session = \YouTubeMusic\Lounge::NewSession('tok', 'Symcon');
        $Session['sid'] = 'SID1';
        $Session['gsid'] = 'G1';
        $Session['aid'] = 14;
        $Rid = $Session['rid'];
        [$Url, $Body] = \YouTubeMusic\Lounge::Command($Session, \YouTubeMusic\LoungeCommand::Pause);
        $this->assertSame(['count' => '1', 'ofs' => '0', 'req0__sc' => 'pause'], $Body);
        [$Url, $Body] = \YouTubeMusic\Lounge::Command($Session, \YouTubeMusic\LoungeCommand::SeekTo, ['newTime' => 60]);
        $this->assertSame(['count' => '1', 'ofs' => '1', 'req0__sc' => 'seekTo', 'req0_newTime' => '60'], $Body);
        $this->assertSame($Rid + 2, $Session['rid']);
        parse_str((string) parse_url($Url, PHP_URL_QUERY), $Query);
        $this->assertSame('SID1', $Query['SID']);
        $this->assertSame('14', $Query['AID']);
        $this->assertSame('m', $Query['theme']);
        $this->assertStringStartsWith(\YouTubeMusic\Lounge::MusicBase . \YouTubeMusic\Lounge::BindPath, $Url);

        // Neuer Handshake setzt die Sitzung zurück
        [$Url, $Body] = \YouTubeMusic\Lounge::Handshake($Session);
        $this->assertSame(['count' => '0'], $Body);
        $this->assertSame(0, $Session['ofs']);
        $this->assertSame('', $Session['sid']);
    }

    public function testPollUrl(): void
    {
        $Session = \YouTubeMusic\Lounge::NewSession('tok', 'Symcon', \YouTubeMusic\Lounge::YouTubeBase);
        $Session['sid'] = 'S';
        $Session['gsid'] = 'G';
        $Session['aid'] = 21;
        parse_str((string) parse_url(\YouTubeMusic\Lounge::Poll($Session), PHP_URL_QUERY), $Query);
        $this->assertSame(['rpc', '21', 'xmlhttp', 'cl'], [$Query['RID'], $Query['AID'], $Query['TYPE'], $Query['theme']]);
    }

    public function testDisconnectAndExpiry(): void
    {
        $Session = \YouTubeMusic\Lounge::NewSession('tok', 'Symcon');
        $this->assertFalse(\YouTubeMusic\Lounge::ApplySession($Session, [[3, 'loungeScreenDisconnected', ['reason' => 'disconnectedByUser']]]));
        $this->assertTrue(\YouTubeMusic\Lounge::SessionExpired(400, 'Unknown SID'));
        $this->assertFalse(\YouTubeMusic\Lounge::SessionExpired(200, '8\n[[0,14,0]]'));
    }

    /**
     * Baut einen Block wie der Server: Länge in UTF-16-Einheiten, Zeilenumbruch, JSON, Zeilenumbruch.
     */
    private static function Chunk(array $Entries): string
    {
        $Json = json_encode($Entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        return (strlen(mb_convert_encoding($Json, 'UTF-16LE', 'UTF-8')) / 2) . "\n" . $Json;
    }
}
