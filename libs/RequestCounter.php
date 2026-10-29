<?php

declare(strict_types=1);

/**
 * Zähler der Anfragen an Google/YouTube je Tag
 *
 * Ohne Symcon-Abhängigkeit. Der Tag wechselt um 0 Uhr Pacific Time, wie das Tageskontingent
 * der YouTube Data API (https://developers.google.com/youtube/v3/getting-started#quota).
 *
 * @project       YouTubeMusic
 * @file          RequestCounter.php
 * @package       YouTubeMusic
 * @author        Michael Tröger <nall4chan@gmail.com>
 * @copyright     2026 Michael Tröger
 * @license       Custom Non-Commercial Share-Alike License (see LICENSE)
 */

namespace YouTubeMusic {
    /**
     * Kategorien der Anfragen
     */
    class RequestCategory
    {
        public const OAuth = 'oauth';
        public const DataApi = 'dataApi';
        public const InnerTubeMusic = 'innerTubeMusic';
        public const InnerTubeTv = 'innerTubeTv';
        public const LoungeToken = 'loungeToken';
        public const LoungeCommand = 'loungeCommand';
        public const LoungePoll = 'loungePoll';
        public const Other = 'other';
        public const All = [self::OAuth, self::DataApi, self::InnerTubeMusic, self::InnerTubeTv, self::LoungeToken, self::LoungeCommand, self::LoungePoll, self::Other];
    }

    /**
     * Zählt Anfragen und Kontingent-Einheiten der Data API
     */
    class RequestCounter
    {
        public const TimeZone = 'America/Los_Angeles';

        /**
         * Kontingent je Tag und Projekt (Standard von Google)
         */
        public const DailyQuota = 10000;

        /**
         * Kategorie einer Anfrage aus der URL.
         *
         * @param  string $Url
         * @return string
         */
        public static function Category(string $Url): string
        {
            $Host = (string) parse_url($Url, PHP_URL_HOST);
            $Path = (string) parse_url($Url, PHP_URL_PATH);
            if ($Host === 'oauth2.googleapis.com') {
                return RequestCategory::OAuth;
            }
            if (($Host === 'www.googleapis.com' || $Host === 'youtube.googleapis.com') && str_starts_with($Path, '/youtube/v3/')) {
                return RequestCategory::DataApi;
            }
            if (str_starts_with($Path, '/api/lounge/pairing/')) {
                return RequestCategory::LoungeToken;
            }
            if (str_starts_with($Path, '/api/lounge/bc/')) {
                parse_str((string) parse_url($Url, PHP_URL_QUERY), $Query);
                return ($Query['RID'] ?? '') === 'rpc' ? RequestCategory::LoungePoll : RequestCategory::LoungeCommand;
            }
            if ($Host === 'music.youtube.com') {
                return RequestCategory::InnerTubeMusic;
            }
            if ($Host === 'www.youtube.com' && str_starts_with($Path, '/youtubei/')) {
                return RequestCategory::InnerTubeTv;
            }
            return RequestCategory::Other;
        }

        /**
         * Kontingent-Einheiten einer Anfrage an die Data API.
         *
         * Lesen (list, getRating) 1, search.list 100, Schreiben (insert, update, delete, rate) 50.
         *
         * @param  string $Method
         * @param  string $Url
         * @return int 0 für Anfragen außerhalb der Data API
         */
        public static function Units(string $Method, string $Url): int
        {
            if (self::Category($Url) !== RequestCategory::DataApi) {
                return 0;
            }
            $Resource = explode('/', substr((string) parse_url($Url, PHP_URL_PATH), strlen('/youtube/v3/')))[0];
            if (strtoupper($Method) === 'GET') {
                return $Resource === 'search' ? 100 : 1;
            }
            return 50;
        }

        /**
         * Tag des Kontingents (Pacific Time).
         *
         * @param  int $Now Unix-Zeit
         * @return string Y-m-d
         */
        public static function Day(int $Now): string
        {
            return (new \DateTimeImmutable('@' . $Now))->setTimezone(new \DateTimeZone(self::TimeZone))->format('Y-m-d');
        }

        /**
         * Leere Statistik für einen Tag.
         *
         * @param  string $Day
         * @return array{day: string, requests: array<string, int>, errors: array<string, int>, units: int}
         */
        public static function Empty(string $Day): array
        {
            $Zero = array_fill_keys(RequestCategory::All, 0);
            return ['day' => $Day, 'requests' => $Zero, 'errors' => $Zero, 'units' => 0];
        }

        /**
         * Zählt eine Anfrage.
         *
         * @param  array $Stats Statistik (wird am neuen Tag zurückgesetzt, der alte Tag steht dann in previous)
         * @param  string $Method
         * @param  string $Url
         * @param  bool $Failed Anfrage ohne Erfolg (cURL-Fehler oder HTTP-Fehler)
         * @param  int $Now Unix-Zeit
         * @return bool true, wenn ein neuer Tag begonnen hat
         */
        public static function Count(array &$Stats, string $Method, string $Url, bool $Failed, int $Now): bool
        {
            $Day = self::Day($Now);
            $NewDay = ($Stats['day'] ?? '') !== $Day;
            if ($NewDay) {
                $Previous = isset($Stats['day']) ? array_diff_key($Stats, ['previous' => 0]) : null;
                $Stats = self::Empty($Day) + ($Previous !== null ? ['previous' => $Previous] : []);
            }
            $Category = self::Category($Url);
            $Stats['requests'][$Category] = ($Stats['requests'][$Category] ?? 0) + 1;
            if ($Failed) {
                $Stats['errors'][$Category] = ($Stats['errors'][$Category] ?? 0) + 1;
            }
            $Stats['units'] += self::Units($Method, $Url);
            return $NewDay;
        }
    }
}
