<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../libs/RequestCounter.php';

class RequestCounterTest extends TestCase
{
    public function testCategories(): void
    {
        $C = static fn (string $Url): string => \YouTubeMusic\RequestCounter::Category($Url);
        $this->assertSame('oauth', $C('https://oauth2.googleapis.com/token'));
        $this->assertSame('dataApi', $C('https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true'));
        $this->assertSame('innerTubeMusic', $C('https://music.youtube.com/youtubei/v1/search?prettyPrint=false'));
        $this->assertSame('innerTubeMusic', $C('https://music.youtube.com/'));
        $this->assertSame('innerTubeTv', $C('https://www.youtube.com/youtubei/v1/browse?prettyPrint=false'));
        $this->assertSame('loungeToken', $C('https://music.youtube.com/api/lounge/pairing/get_lounge_token_batch'));
        $this->assertSame('loungePoll', $C('https://music.youtube.com/api/lounge/bc/bind?SID=x&RID=rpc&AID=4'));
        $this->assertSame('loungeCommand', $C('https://music.youtube.com/api/lounge/bc/bind?SID=x&RID=12345&AID=4'));
        $this->assertSame('other', $C('https://example.com/'));
    }

    public function testUnits(): void
    {
        $U = static fn (string $Method, string $Url): int => \YouTubeMusic\RequestCounter::Units($Method, $Url);
        $this->assertSame(1, $U('GET', 'https://www.googleapis.com/youtube/v3/playlists?mine=true'));
        $this->assertSame(100, $U('GET', 'https://www.googleapis.com/youtube/v3/search?q=x'));
        $this->assertSame(50, $U('POST', 'https://www.googleapis.com/youtube/v3/videos/rate?id=x&rating=like'));
        $this->assertSame(50, $U('DELETE', 'https://www.googleapis.com/youtube/v3/playlistItems?id=x'));
        $this->assertSame(0, $U('POST', 'https://music.youtube.com/youtubei/v1/search'));
    }

    public function testDayChangesAtMidnightPacific(): void
    {
        // 2026-10-29 06:59:59 UTC = 23:59:59 PDT am 28.
        $this->assertSame('2026-10-28', \YouTubeMusic\RequestCounter::Day(1793257199));
        $this->assertSame('2026-10-29', \YouTubeMusic\RequestCounter::Day(1793257200));
    }

    public function testCount(): void
    {
        $Stats = [];
        $this->assertTrue(\YouTubeMusic\RequestCounter::Count($Stats, 'GET', 'https://www.googleapis.com/youtube/v3/search?q=x', false, 1793257100));
        $this->assertFalse(\YouTubeMusic\RequestCounter::Count($Stats, 'GET', 'https://music.youtube.com/api/lounge/bc/bind?RID=rpc', true, 1793257150));
        $this->assertSame(100, $Stats['units']);
        $this->assertSame(1, $Stats['requests']['loungePoll']);
        $this->assertSame(1, $Stats['errors']['loungePoll']);

        // Neuer Tag: zurücksetzen, Vortag merken
        $this->assertTrue(\YouTubeMusic\RequestCounter::Count($Stats, 'GET', 'https://www.googleapis.com/youtube/v3/channels?mine=true', false, 1793257300));
        $this->assertSame('2026-10-29', $Stats['day']);
        $this->assertSame(1, $Stats['units']);
        $this->assertSame(100, $Stats['previous']['units']);
        $this->assertArrayNotHasKey('previous', $Stats['previous']);
    }
}
