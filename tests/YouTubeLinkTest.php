<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../libs/YouTubeLink.php';

class YouTubeLinkTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array{videoId: string, listId: string, browseId: string, music: bool}|null}>
     */
    public static function links(): array
    {
        $E = static fn (string $v = '', string $l = '', string $b = '', bool $m = false): array => ['videoId' => $v, 'listId' => $l, 'browseId' => $b, 'music' => $m];
        return [
            'watch'                => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', $E('dQw4w9WgXcQ')],
            'watch with list'      => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PLx0sYbCqOb8TBPRdmBHs5Iftvv9TPboYG&index=2', $E('dQw4w9WgXcQ', 'PLx0sYbCqOb8TBPRdmBHs5Iftvv9TPboYG')],
            'music watch radio'    => ['https://music.youtube.com/watch?v=4D7u5KF7SP8&list=RDAMVM4D7u5KF7SP8', $E('4D7u5KF7SP8', 'RDAMVM4D7u5KF7SP8', '', true)],
            'music playlist'       => ['https://music.youtube.com/playlist?list=OLAK5uy_kRbd-h2WK1J0EvN2UMy1tfDXDg1OJ1wTo', $E('', 'OLAK5uy_kRbd-h2WK1J0EvN2UMy1tfDXDg1OJ1wTo', '', true)],
            'music album browse'   => ['https://music.youtube.com/browse/MPREb_4pL8gzRtw1p', $E('', '', 'MPREb_4pL8gzRtw1p', true)],
            'music artist channel' => ['https://music.youtube.com/channel/UC1ABUacK0j5sV6e4JixczQg', $E('', '', 'UC1ABUacK0j5sV6e4JixczQg', true)],
            'short link'           => ['https://youtu.be/dQw4w9WgXcQ?si=abc&t=42', $E('dQw4w9WgXcQ')],
            'shorts'               => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', $E('dQw4w9WgXcQ')],
            'embed'                => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $E('dQw4w9WgXcQ')],
            'mobile without https' => ['m.youtube.com/watch?v=dQw4w9WgXcQ', $E('dQw4w9WgXcQ')],
            'raw video id'         => ['dQw4w9WgXcQ', $E('dQw4w9WgXcQ')],
            'raw playlist id'      => ['PLx0sYbCqOb8TBPRdmBHs5Iftvv9TPboYG', $E('', 'PLx0sYbCqOb8TBPRdmBHs5Iftvv9TPboYG')],
            'raw VL playlist id'   => ['VLPLx0sYbCqOb8TBPRdmBHs5Iftvv9TPboYG', $E('', 'PLx0sYbCqOb8TBPRdmBHs5Iftvv9TPboYG')],
            'liked music'          => ['LM', $E('', 'LM')],
            'raw album browse id'  => ['MPREb_4pL8gzRtw1p', $E('', '', 'MPREb_4pL8gzRtw1p')],
            'foreign host'         => ['https://example.com/watch?v=dQw4w9WgXcQ', null],
            'invalid video id'     => ['https://www.youtube.com/watch?v=short', null],
            'empty'                => ['  ', null],
            'garbage'              => ['hello world', null],
        ];
    }

    #[DataProvider('links')]
    public function testParse(string $Input, ?array $Expected): void
    {
        $this->assertSame($Expected, \YouTubeMusic\YouTubeLink::Parse($Input));
    }

    public function testBuild(): void
    {
        $this->assertSame('https://music.youtube.com/watch?v=4D7u5KF7SP8&list=RDAMVM4D7u5KF7SP8', \YouTubeMusic\YouTubeLink::Build('4D7u5KF7SP8', 'RDAMVM4D7u5KF7SP8'));
        $this->assertSame('https://www.youtube.com/playlist?list=PLabc', \YouTubeMusic\YouTubeLink::Build('', 'PLabc', false));
    }
}
