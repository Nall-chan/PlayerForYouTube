<?php

declare(strict_types=1);

/**
 * Texte der Kachel (YouTube Music Player)
 *
 * Werden in GetVisualizationTile übersetzt und als Konfiguration an die Kachel gegeben;
 * ein Unit-Test prüft, dass jeder Text in der locale.json des Players steht.
 *
 * @project       YouTubeMusic
 * @file          TileTexts.php
 * @package       YouTubeMusic
 * @author        Michael Tröger <nall4chan@gmail.com>
 * @copyright     2026 Michael Tröger
 * @license       Custom Non-Commercial Share-Alike License (see LICENSE)
 */

namespace YouTubeMusic {
    /**
     * Alle Texte der Kachel
     */
    class TileTexts
    {
        public const All = [
            'Nothing playing',
            'Play',
            'Pause',
            'Next',
            'Previous',
            'Volume',
            'Link or ID',
            'This device',
            'Not connected',
            'Close',
            'Not a playable YouTube link',
            'The video could not be played. Open the visualization via its host name (not an IP address).',
            'Playback is not allowed for this video in embedded players.',
            'Like',
            'Remove like',
            'Start',
            'Mute',
            'Unmute',
            'Devices',
            'Transfer playback here',
            'Nothing is playing on this device',
            'Search',
            'New',
            'Explore',
            'Library',
            'Queue',
            'Lyrics',
            'Back',
            'Load more',
            'Loading…',
            'Nothing found',
            'Search or paste a link',
            'All',
            'Songs',
            'Videos',
            'Albums',
            'Artists',
            'Playlists',
            'No lyrics for this title',
            'Charts',
            'Radio',
        ];
    }
}
