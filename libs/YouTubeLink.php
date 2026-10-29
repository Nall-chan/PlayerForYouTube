<?php

declare(strict_types=1);

/**
 * Zerlegt Links von YouTube und YouTube Music
 *
 * @project       YouTubeMusic
 * @file          YouTubeLink.php
 * @package       YouTubeMusic
 * @author        Michael Tröger <nall4chan@gmail.com>
 * @copyright     2026 Michael Tröger
 * @license       Custom Non-Commercial Share-Alike License (see LICENSE)
 */

namespace YouTubeMusic {
    /**
     * Parser für youtube.com-, music.youtube.com- und youtu.be-Links sowie reine IDs
     */
    class YouTubeLink
    {
        private const VideoIdPattern = '/^[A-Za-z0-9_-]{11}$/D';
        private const ListIdPattern = '/^[A-Za-z0-9_-]{2,80}$/D';
        private const BrowseIdPattern = '/^(MPRE|UC|MPLA|FE|VL)[A-Za-z0-9_-]{2,80}$/D';
        /**
         * Präfixe von Wiedergabelisten (Playlist, Radio/Mix, Album, Likes, Uploads)
         */
        private const ListPrefixes = ['PL', 'RD', 'OLAK5uy_', 'LM', 'LL', 'UU', 'FL', 'OL'];
        private const Hosts = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be', 'www.youtube-nocookie.com', 'youtube-nocookie.com'];

        /**
         * Zerlegt einen Link oder eine ID.
         *
         * @param  string $Input
         * @return array{videoId: string, listId: string, browseId: string, music: bool}|null null, wenn nichts Verwertbares enthalten ist
         */
        public static function Parse(string $Input): ?array
        {
            $Input = trim($Input);
            if ($Input === '') {
                return null;
            }
            if (!str_contains($Input, '/') && !str_contains($Input, '?')) {
                return self::ParseId($Input);
            }
            if (!preg_match('#^https?://#i', $Input)) {
                $Input = 'https://' . $Input;
            }
            $Url = parse_url($Input);
            $Host = strtolower($Url['host'] ?? '');
            if (!in_array($Host, self::Hosts, true)) {
                return null;
            }
            parse_str($Url['query'] ?? '', $Query);
            $Path = array_values(array_filter(explode('/', $Url['path'] ?? ''), static fn (string $Part): bool => $Part !== ''));
            $Result = ['videoId' => '', 'listId' => '', 'browseId' => '', 'music' => $Host === 'music.youtube.com'];

            if ($Host === 'youtu.be') {
                $Result['videoId'] = self::ValidVideoId($Path[0] ?? '');
            } elseif (in_array($Path[0] ?? '', ['shorts', 'embed', 'live', 'v'], true)) {
                $Result['videoId'] = self::ValidVideoId($Path[1] ?? '');
            } elseif (in_array($Path[0] ?? '', ['browse', 'channel'], true)) {
                $Result['browseId'] = self::ValidBrowseId($Path[1] ?? '');
            } else {
                $Result['videoId'] = self::ValidVideoId(is_string($Query['v'] ?? null) ? $Query['v'] : '');
            }
            $Result['listId'] = self::ValidListId(is_string($Query['list'] ?? null) ? $Query['list'] : '');
            if ($Result['videoId'] === '' && $Result['listId'] === '' && $Result['browseId'] === '') {
                return null;
            }
            return $Result;
        }

        /**
         * Prüft eine Video-ID.
         *
         * @param  string $Id
         * @return bool
         */
        public static function IsVideoId(string $Id): bool
        {
            return preg_match(self::VideoIdPattern, $Id) === 1;
        }

        /**
         * Erzeugt den Link zu einem Eintrag.
         *
         * @param  string $VideoId
         * @param  string $ListId
         * @param  bool $Music music.youtube.com statt www.youtube.com
         * @return string
         */
        public static function Build(string $VideoId, string $ListId = '', bool $Music = true): string
        {
            $Base = $Music ? 'https://music.youtube.com/' : 'https://www.youtube.com/';
            if ($VideoId === '') {
                return $Base . 'playlist?list=' . rawurlencode($ListId);
            }
            return $Base . 'watch?v=' . rawurlencode($VideoId) . ($ListId !== '' ? '&list=' . rawurlencode($ListId) : '');
        }

        /**
         * Reine ID: Video-ID (11 Zeichen), Listen-ID (bekanntes Präfix, `VL` wird entfernt) oder Browse-ID.
         *
         * @param  string $Id
         * @return array{videoId: string, listId: string, browseId: string, music: bool}|null
         */
        private static function ParseId(string $Id): ?array
        {
            $Result = ['videoId' => '', 'listId' => '', 'browseId' => '', 'music' => false];
            if (str_starts_with($Id, 'VL') && self::HasListPrefix(substr($Id, 2))) {
                $Id = substr($Id, 2);
            }
            if (self::HasListPrefix($Id) && preg_match(self::ListIdPattern, $Id) === 1) {
                $Result['listId'] = $Id;
            } elseif (self::IsVideoId($Id)) {
                $Result['videoId'] = $Id;
            } elseif (preg_match(self::BrowseIdPattern, $Id) === 1) {
                $Result['browseId'] = $Id;
            } else {
                return null;
            }
            return $Result;
        }

        private static function HasListPrefix(string $Id): bool
        {
            foreach (self::ListPrefixes as $Prefix) {
                if (str_starts_with($Id, $Prefix) && strlen($Id) > strlen($Prefix)) {
                    return true;
                }
            }
            // Likes und gespeicherte Titel haben feste Kürzel
            return in_array($Id, ['LM', 'LL', 'WL'], true);
        }

        private static function ValidVideoId(string $Id): string
        {
            return self::IsVideoId($Id) ? $Id : '';
        }

        private static function ValidListId(string $Id): string
        {
            return preg_match(self::ListIdPattern, $Id) === 1 ? $Id : '';
        }

        private static function ValidBrowseId(string $Id): string
        {
            return preg_match(self::BrowseIdPattern, $Id) === 1 ? $Id : '';
        }
    }
}
