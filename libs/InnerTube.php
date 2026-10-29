<?php

declare(strict_types=1);

/**
 * InnerTube (inoffizielle interne API von YouTube und YouTube Music)
 *
 * Ohne Symcon-Abhängigkeit: baut die Anfragen und bringt die verschachtelten Renderer-Antworten
 * in flache Listen. Zwei Clients (siehe STATUS.md, Spike a2):
 * - WEB_REMIX (music.youtube.com) ohne Anmeldung: Startseite, Charts, Neu, Stimmungen, Suche, Radio, Lyrics
 * - TVHTML5 (www.youtube.com) mit dem Token des eigenen Cloud-Projekts: Mediathek, YouTube-Startseite,
 *   personalisiertes Radio, Like-Status
 *
 * Protokolldetails (Endpunkte, Filter-Parameter der Suche) stammen aus der Analyse der Webseite und
 * aus ytmusicapi (https://github.com/sigma67/ytmusicapi); es wurde kein Code übernommen.
 *
 * @project       YouTubeMusic
 * @file          InnerTube.php
 * @package       YouTubeMusic
 * @author        Michael Tröger <nall4chan@gmail.com>
 * @copyright     2026 Michael Tröger
 * @license       Custom Non-Commercial Share-Alike License (see LICENSE)
 */

namespace YouTubeMusic {
    /**
     * Typen der Einträge
     */
    class ItemType
    {
        public const Song = 'song';
        public const Video = 'video';
        public const Album = 'album';
        public const Playlist = 'playlist';
        public const Artist = 'artist';
        public const Category = 'category';
        public const Podcast = 'podcast';
        public const Episode = 'episode';
        public const Unknown = 'unknown';
    }

    /**
     * Anfragen an InnerTube
     */
    class InnerTubeClient
    {
        public const MusicUrl = 'https://music.youtube.com/youtubei/v1/';
        public const TvUrl = 'https://www.youtube.com/youtubei/v1/';
        public const MusicClientName = 'WEB_REMIX';
        public const TvClientName = 'TVHTML5';
        /**
         * Rückfallwert, falls die Version nicht von der Startseite gelesen werden kann (Stand 2026-10-29)
         */
        public const MusicClientVersion = '1.20261027.10.00';
        public const TvClientVersion = '7.20261027.00.00';

        /**
         * Filter der Musiksuche (Parameter `params`)
         */
        public const SearchFilter = [
            ItemType::Song     => 'EgWKAQIIAWoKEAkQBRAKEAMQBA==',
            ItemType::Video    => 'EgWKAQIQAWoKEAkQChAFEAMQBA==',
            ItemType::Album    => 'EgWKAQIYAWoKEAkQChAFEAMQBA==',
            ItemType::Artist   => 'EgWKAQIgAWoKEAkQChAFEAMQBA==',
            ItemType::Playlist => 'EgeKAQQoAEABagoQAxAEEAoQCRAF',
        ];

        /**
         * Feste Browse-IDs
         */
        public const Home = 'FEmusic_home';
        public const Charts = 'FEmusic_charts';
        public const NewReleases = 'FEmusic_new_releases';
        public const MoodsAndGenres = 'FEmusic_moods_and_genres';
        public const Library = 'FEmusic_liked_playlists';
        public const TvHome = 'default';

        /**
         * Liest Client-Version und Besucherkennung aus dem HTML der Startseite (ytcfg).
         *
         * @param  string $Html
         * @return array{clientVersion: string, visitorData: string}
         */
        public static function ParseConfig(string $Html): array
        {
            $Version = preg_match('/"INNERTUBE_CLIENT_VERSION":"([0-9.]+)"/', $Html, $Match) ? $Match[1] : '';
            $Visitor = preg_match('/"VISITOR_DATA":"([A-Za-z0-9_%=-]+)"/', $Html, $Match) ? $Match[1] : '';
            return ['clientVersion' => $Version, 'visitorData' => $Visitor];
        }

        /**
         * Kontext einer Anfrage.
         *
         * @param  string $ClientName
         * @param  string $ClientVersion
         * @param  string $Language z. B. de
         * @param  string $Country z. B. DE
         * @param  string $VisitorData
         * @return array
         */
        public static function Context(string $ClientName, string $ClientVersion, string $Language, string $Country, string $VisitorData = ''): array
        {
            $Client = ['clientName' => $ClientName, 'clientVersion' => $ClientVersion, 'hl' => $Language, 'gl' => $Country];
            if ($VisitorData !== '') {
                $Client['visitorData'] = $VisitorData;
            }
            return ['client' => $Client];
        }

        /**
         * JSON-Body aus Kontext und Nutzdaten.
         *
         * @param  array $Context
         * @param  array $Payload
         * @return string
         */
        public static function Body(array $Context, array $Payload): string
        {
            return json_encode(['context' => $Context] + $Payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        /**
         * Nutzdaten für browse.
         *
         * @param  string $BrowseId
         * @param  string $Params
         * @return array
         */
        public static function Browse(string $BrowseId, string $Params = ''): array
        {
            return ['browseId' => $BrowseId] + ($Params !== '' ? ['params' => $Params] : []);
        }

        /**
         * Nutzdaten für die Fortsetzung einer Liste (browse oder search).
         *
         * @param  string $Token
         * @return array
         */
        public static function Continuation(string $Token): array
        {
            return ['continuation' => $Token];
        }

        /**
         * Nutzdaten für search.
         *
         * @param  string $Query
         * @param  string $Type Typ aus ItemType für den Filter, leer für alle
         * @return array
         */
        public static function Search(string $Query, string $Type = ''): array
        {
            return ['query' => $Query] + (isset(self::SearchFilter[$Type]) ? ['params' => self::SearchFilter[$Type]] : []);
        }

        /**
         * Nutzdaten für next (Warteschlange, Radio).
         *
         * @param  string $VideoId
         * @param  string $PlaylistId Leer: Radio zum Titel (`RDAMVM<videoId>`)
         * @param  string $Params
         * @return array
         */
        public static function Next(string $VideoId, string $PlaylistId = '', string $Params = ''): array
        {
            $Payload = [];
            if ($VideoId !== '') {
                $Payload['videoId'] = $VideoId;
            }
            $Payload['playlistId'] = $PlaylistId !== '' ? $PlaylistId : 'RDAMVM' . $VideoId;
            $Payload['isAudioOnly'] = true;
            $Payload['enablePersistentPlaylistPanel'] = true;
            if ($Params !== '') {
                $Payload['params'] = $Params;
            }
            return $Payload;
        }
    }

    /**
     * Wandelt InnerTube-Antworten in flache Listen.
     *
     * Ein Eintrag: type, title, subtitle, artists (Namen), album, videoId, playlistId, browseId, params, thumbnail, duration.
     */
    class InnerTubeParser
    {
        /**
         * Renderer, die einen Eintrag darstellen
         */
        private const ItemRenderers = [
            'musicTwoRowItemRenderer', 'musicResponsiveListItemRenderer', 'playlistPanelVideoRenderer',
            'musicNavigationButtonRenderer', 'tileRenderer', 'musicMultiRowListItemRenderer',
        ];

        /**
         * Renderer, die ein Regal (Überschrift und Einträge) darstellen
         */
        private const ShelfRenderers = [
            'musicCarouselShelfRenderer', 'musicImmersiveCarouselShelfRenderer', 'musicShelfRenderer',
            'gridRenderer', 'shelfRenderer', 'musicPlaylistShelfRenderer', 'playlistPanelRenderer',
        ];

        /**
         * Regale einer browse- oder search-Antwort.
         *
         * @param  array $Response
         * @return array{shelves: list<array{title: string, items: list<array>}>, continuation: string}
         */
        public static function Shelves(array $Response): array
        {
            $Shelves = [];
            self::CollectShelves($Response, $Shelves);
            return ['shelves' => $Shelves, 'continuation' => self::ContinuationToken($Response)];
        }

        /**
         * Alle Einträge einer Antwort ohne Regale (z. B. für Suchergebnisse eines Filters).
         *
         * @param  array $Node
         * @return list<array>
         */
        public static function Items(array $Node): array
        {
            $Items = [];
            self::CollectItems($Node, $Items);
            return $Items;
        }

        /**
         * Warteschlange einer next-Antwort.
         *
         * @param  array $Response
         * @param  string $VideoId Aktueller Titel (für den Like-Status)
         * @return array{items: list<array>, playlistId: string, lyricsBrowseId: string, likeStatus: string}
         */
        public static function Queue(array $Response, string $VideoId = ''): array
        {
            $Items = [];
            $Seen = [];
            $PlaylistId = '';
            $LyricsId = '';
            $LikeStatus = '';
            self::Walk($Response, function (string $Key, array $Value) use (&$Items, &$Seen, &$PlaylistId, &$LyricsId, &$LikeStatus, $VideoId): bool
            {
                switch ($Key) {
                    case 'playlistPanelRenderer':
                    case 'playlist':
                        // Music: playlistPanelRenderer, TV: singleColumnWatchNextResults.playlist.playlist
                        $PlaylistId = $PlaylistId !== '' ? $PlaylistId : (string) ($Value['playlistId'] ?? '');
                        return true;
                    case 'counterpart':
                        // TV: Gegenstück (Video zum Titel bzw. umgekehrt) nicht zusätzlich aufnehmen
                        return false;
                    case 'playlistPanelVideoRenderer':
                    case 'tileRenderer':
                        $Item = self::Item($Key, $Value);
                        if ($Item !== null && $Item['videoId'] !== '' && !isset($Seen[$Item['videoId']])) {
                            $Seen[$Item['videoId']] = true;
                            $Items[] = $Item;
                        }
                        return false;
                    case 'browseEndpoint':
                        if ($LyricsId === '' && str_starts_with((string) ($Value['browseId'] ?? ''), 'MPLY')) {
                            $LyricsId = (string) $Value['browseId'];
                        }
                        return false;
                    case 'likeButtonRenderer':
                        $Target = (string) ($Value['target']['videoId'] ?? '');
                        if ($LikeStatus === '' && isset($Value['likeStatus']) && ($VideoId === '' || $Target === $VideoId)) {
                            $LikeStatus = (string) $Value['likeStatus'];
                        }
                        return false;
                }
                return true;
            });
            // TV: Empfehlungen aus anderen Regalen gehören nicht zur Liste
            if ($PlaylistId !== '') {
                $InList = array_values(array_filter($Items, static fn (array $Item): bool => $Item['playlistId'] === $PlaylistId));
                if (count($InList) > 0) {
                    $Items = $InList;
                }
            }
            return ['items' => $Items, 'playlistId' => $PlaylistId, 'lyricsBrowseId' => $LyricsId, 'likeStatus' => $LikeStatus];
        }

        /**
         * Liedtext einer browse-Antwort zur Lyrics-ID.
         *
         * @param  array $Response
         * @return array{text: string, source: string}
         */
        public static function Lyrics(array $Response): array
        {
            $Result = ['text' => '', 'source' => ''];
            self::Walk($Response, function (string $Key, array $Value) use (&$Result): bool
            {
                if ($Key !== 'musicDescriptionShelfRenderer') {
                    return true;
                }
                $Result['text'] = str_replace("\r\n", "\n", self::RunsText($Value['description'] ?? []));
                $Result['source'] = self::RunsText($Value['footer'] ?? []);
                return false;
            });
            return $Result;
        }

        /**
         * Vorschaubild in größerer Auflösung: Bei Bildern von googleusercontent/ggpht steht die Größe in der URL
         * (=w120-h120…, =s576); andere Adressen (i.ytimg.com) bleiben unverändert.
         *
         * @param  string $Url
         * @param  int $Size Kantenlänge in Pixel
         * @return string
         */
        public static function LargeThumbnail(string $Url, int $Size = 544): string
        {
            $Host = (string) parse_url($Url, PHP_URL_HOST);
            if (!str_ends_with($Host, 'googleusercontent.com') && !str_ends_with($Host, 'ggpht.com')) {
                return $Url;
            }
            $Result = preg_replace('/=w\d+-h\d+/', '=w' . $Size . '-h' . $Size, $Url, 1, $Count);
            if ($Count === 0) {
                $Result = preg_replace('/=s\d+/', '=s' . $Size, $Url, 1);
            }
            return (string) $Result;
        }

        /**
         * Normalisiert einen Eintrag.
         *
         * @param  string $Renderer Name des Renderers
         * @param  array $Data Inhalt des Renderers
         * @return array|null null, wenn weder Titel noch ID vorhanden sind
         */
        public static function Item(string $Renderer, array $Data): ?array
        {
            $Title = self::FirstText([
                $Data['title'] ?? null,
                $Data['flexColumns'][0]['musicResponsiveListItemFlexColumnRenderer']['text'] ?? null,
                $Data['metadata']['tileMetadataRenderer']['title'] ?? null,
                $Data['buttonText'] ?? null,
            ]);
            $SubtitleRuns = self::SubtitleRuns($Data);
            $Endpoints = self::Endpoints($Data);
            $Watch = $Endpoints['watchEndpoint'] ?? [];
            $Browse = $Endpoints['browseEndpoint'] ?? [];
            $WatchPlaylist = $Endpoints['watchPlaylistEndpoint'] ?? [];

            $VideoId = (string) ($Watch['videoId'] ?? $Data['videoId'] ?? $Data['playlistItemData']['videoId'] ?? '');
            $PlaylistId = (string) ($Watch['playlistId'] ?? $WatchPlaylist['playlistId'] ?? '');
            $BrowseId = (string) ($Browse['browseId'] ?? '');
            if ($Title === '' || ($VideoId === '' && $PlaylistId === '' && $BrowseId === '')) {
                return null;
            }
            // Playlist-Seiten (VL<Playlist-ID>) sind direkt abspielbar
            if ($PlaylistId === '' && str_starts_with($BrowseId, 'VL')) {
                $PlaylistId = substr($BrowseId, 2);
            }
            $Artists = [];
            $Album = '';
            foreach ($SubtitleRuns as $Run) {
                $PageType = self::PageType($Run['navigationEndpoint']['browseEndpoint'] ?? []);
                if (in_array($PageType, ['MUSIC_PAGE_TYPE_ARTIST', 'MUSIC_PAGE_TYPE_USER_CHANNEL'], true)) {
                    $Artists[] = (string) $Run['text'];
                } elseif ($PageType === 'MUSIC_PAGE_TYPE_ALBUM') {
                    $Album = (string) $Run['text'];
                }
            }
            return [
                'type'       => self::Type($Renderer, $Data, $Watch, $Browse, $VideoId),
                'title'      => $Title,
                'subtitle'   => self::Clean(trim(implode('', array_map(static fn (array $Run): string => (string) ($Run['text'] ?? ''), $SubtitleRuns)))),
                'artists'    => $Artists,
                'album'      => $Album,
                'videoId'    => $VideoId,
                'playlistId' => $PlaylistId,
                'browseId'   => $BrowseId,
                'params'     => (string) ($Browse['params'] ?? $Watch['params'] ?? ''),
                'thumbnail'  => self::Thumbnail($Data),
                'duration'   => self::Duration($Data, $SubtitleRuns),
            ];
        }

        /**
         * Sammelt Regale; Einträge außerhalb eines Regals landen in einem Regal ohne Titel.
         *
         * @param  mixed $Node
         * @param  array $Shelves
         * @return void
         */
        private static function CollectShelves(mixed $Node, array &$Shelves): void
        {
            if (!is_array($Node)) {
                return;
            }
            foreach ($Node as $Key => $Value) {
                if (!is_array($Value)) {
                    continue;
                }
                if (is_string($Key) && in_array($Key, self::ShelfRenderers, true)) {
                    $Items = self::Items($Value);
                    if (count($Items) > 0) {
                        $Shelves[] = ['title' => self::ShelfTitle($Value), 'items' => $Items];
                    }
                    continue;
                }
                if (is_string($Key) && in_array($Key, self::ItemRenderers, true)) {
                    $Item = self::Item($Key, $Value);
                    if ($Item !== null) {
                        $Last = count($Shelves) - 1;
                        if ($Last < 0 || $Shelves[$Last]['title'] !== '') {
                            $Shelves[] = ['title' => '', 'items' => []];
                            $Last++;
                        }
                        $Shelves[$Last]['items'][] = $Item;
                    }
                    continue;
                }
                self::CollectShelves($Value, $Shelves);
            }
        }

        /**
         * Sammelt Einträge (ohne in Einträge hinabzusteigen).
         *
         * @param  mixed $Node
         * @param  array $Items
         * @return void
         */
        private static function CollectItems(mixed $Node, array &$Items): void
        {
            if (!is_array($Node)) {
                return;
            }
            foreach ($Node as $Key => $Value) {
                if (!is_array($Value)) {
                    continue;
                }
                if (is_string($Key) && in_array($Key, self::ItemRenderers, true)) {
                    $Item = self::Item($Key, $Value);
                    if ($Item !== null) {
                        $Items[] = $Item;
                    }
                    continue;
                }
                if ($Key === 'playlistPanelVideoWrapperRenderer') {
                    // TV: Warteschlange mit Haupt- und Gegenstück (Video/Audio), nur das Hauptelement
                    self::CollectItems(['x' => $Value['primaryRenderer'] ?? []], $Items);
                    continue;
                }
                self::CollectItems($Value, $Items);
            }
        }

        /**
         * Überschrift eines Regals.
         *
         * @param  array $Shelf
         * @return string
         */
        private static function ShelfTitle(array $Shelf): string
        {
            return self::FirstText([
                $Shelf['header']['musicCarouselShelfBasicHeaderRenderer']['title'] ?? null,
                $Shelf['header']['musicImmersiveHeaderRenderer']['title'] ?? null,
                $Shelf['header']['gridHeaderRenderer']['title'] ?? null,
                $Shelf['headerRenderer']['shelfHeaderRenderer']['avatarLockup']['avatarLockupRenderer']['title'] ?? null,
                $Shelf['title'] ?? null,
            ]);
        }

        /**
         * Token der nächsten Seite (älteres und neueres Format).
         *
         * @param  array $Response
         * @return string
         */
        private static function ContinuationToken(array $Response): string
        {
            $Token = '';
            self::Walk($Response, function (string $Key, array $Value) use (&$Token): bool
            {
                if ($Token !== '') {
                    return false;
                }
                if ($Key === 'nextContinuationData' || $Key === 'reloadContinuationData') {
                    $Token = (string) ($Value['continuation'] ?? '');
                    return false;
                }
                if ($Key === 'continuationCommand') {
                    $Token = (string) ($Value['token'] ?? '');
                    return false;
                }
                return true;
            });
            return $Token;
        }

        /**
         * Läufe der Unterzeile (Interpret, Album, Jahr …).
         *
         * @param  array $Data
         * @return list<array>
         */
        private static function SubtitleRuns(array $Data): array
        {
            if (isset($Data['subtitle']['runs'])) {
                return $Data['subtitle']['runs'];
            }
            if (isset($Data['longBylineText']['runs'])) {
                return $Data['longBylineText']['runs'];
            }
            if (isset($Data['flexColumns']) && is_array($Data['flexColumns'])) {
                $Runs = [];
                foreach (array_slice($Data['flexColumns'], 1) as $Column) {
                    foreach ($Column['musicResponsiveListItemFlexColumnRenderer']['text']['runs'] ?? [] as $Run) {
                        $Runs[] = $Run;
                    }
                    if (count($Runs) > 0) {
                        $Runs[] = ['text' => ' • '];
                    }
                }
                array_pop($Runs);
                return $Runs;
            }
            // TV: Zeilen aus mehreren Teilen ohne eigene Trenner
            $Runs = [];
            foreach ($Data['metadata']['tileMetadataRenderer']['lines'] ?? [] as $Line) {
                foreach ($Line['lineRenderer']['items'] ?? [] as $LineItem) {
                    $Text = $LineItem['lineItemRenderer']['text'] ?? [];
                    $Part = $Text['runs'] ?? (isset($Text['simpleText']) ? [['text' => $Text['simpleText']]] : []);
                    if (count($Part) === 0 || trim((string) ($Part[0]['text'] ?? '')) === '•') {
                        continue;
                    }
                    if (count($Runs) > 0) {
                        $Runs[] = ['text' => ' • '];
                    }
                    array_push($Runs, ...$Part);
                }
            }
            return $Runs;
        }

        /**
         * Erste Endpunkte je Art aus den üblichen Stellen eines Renderers.
         *
         * @param  array $Data
         * @return array<string, array>
         */
        private static function Endpoints(array $Data): array
        {
            $Candidates = [
                $Data['navigationEndpoint'] ?? null,
                $Data['onSelectCommand'] ?? null,
                $Data['clickCommand'] ?? null,
                $Data['title']['runs'][0]['navigationEndpoint'] ?? null,
                $Data['flexColumns'][0]['musicResponsiveListItemFlexColumnRenderer']['text']['runs'][0]['navigationEndpoint'] ?? null,
                $Data['overlay']['musicItemThumbnailOverlayRenderer']['content']['musicPlayButtonRenderer']['playNavigationEndpoint'] ?? null,
                $Data['thumbnailOverlay']['musicItemThumbnailOverlayRenderer']['content']['musicPlayButtonRenderer']['playNavigationEndpoint'] ?? null,
                $Data['metadata']['tileMetadataRenderer']['title']['runs'][0]['navigationEndpoint'] ?? null,
            ];
            $Result = [];
            foreach ($Candidates as $Candidate) {
                if (!is_array($Candidate)) {
                    continue;
                }
                foreach (['watchEndpoint', 'browseEndpoint', 'watchPlaylistEndpoint'] as $Kind) {
                    if (!isset($Result[$Kind]) && isset($Candidate[$Kind]) && is_array($Candidate[$Kind])) {
                        $Result[$Kind] = $Candidate[$Kind];
                    }
                }
            }
            return $Result;
        }

        /**
         * Typ eines Eintrags.
         *
         * @param  string $Renderer
         * @param  array $Data
         * @param  array $Watch
         * @param  array $Browse
         * @param  string $VideoId
         * @return string
         */
        private static function Type(string $Renderer, array $Data, array $Watch, array $Browse, string $VideoId): string
        {
            if ($Renderer === 'musicNavigationButtonRenderer') {
                return ItemType::Category;
            }
            $PageType = self::PageType($Browse);
            $Map = [
                'MUSIC_PAGE_TYPE_ALBUM'                    => ItemType::Album,
                'MUSIC_PAGE_TYPE_AUDIOBOOK'                => ItemType::Album,
                'MUSIC_PAGE_TYPE_PLAYLIST'                 => ItemType::Playlist,
                'MUSIC_PAGE_TYPE_ARTIST'                   => ItemType::Artist,
                'MUSIC_PAGE_TYPE_USER_CHANNEL'             => ItemType::Artist,
                'MUSIC_PAGE_TYPE_LIBRARY_ARTIST'           => ItemType::Artist,
                'MUSIC_PAGE_TYPE_PODCAST_SHOW_DETAIL_PAGE' => ItemType::Podcast,
            ];
            if (isset($Map[$PageType])) {
                return $Map[$PageType];
            }
            if (str_starts_with((string) ($Browse['browseId'] ?? ''), 'FEmusic_moods_and_genres_category')) {
                return ItemType::Category;
            }
            $VideoType = (string) ($Watch['watchEndpointMusicSupportedConfigs']['watchEndpointMusicConfig']['musicVideoType'] ?? '');
            if ($VideoType === 'MUSIC_VIDEO_TYPE_ATV') {
                return ItemType::Song;
            }
            if ($VideoType === 'MUSIC_VIDEO_TYPE_PODCAST_EPISODE') {
                return ItemType::Episode;
            }
            if ($VideoType !== '') {
                return ItemType::Video;
            }
            $TileTypes = [
                'TILE_CONTENT_TYPE_VIDEO'    => ItemType::Video,
                'TILE_CONTENT_TYPE_PLAYLIST' => ItemType::Playlist,
                'TILE_CONTENT_TYPE_CHANNEL'  => ItemType::Artist,
                'TILE_CONTENT_TYPE_SHORT'    => ItemType::Video,
            ];
            if (isset($TileTypes[$Data['contentType'] ?? ''])) {
                return $TileTypes[$Data['contentType']];
            }
            if ($VideoId !== '') {
                return $Renderer === 'playlistPanelVideoRenderer' ? ItemType::Song : ItemType::Video;
            }
            return ($Browse['browseId'] ?? '') !== '' && str_starts_with((string) $Browse['browseId'], 'UC') ? ItemType::Artist : ItemType::Unknown;
        }

        /**
         * Seitentyp eines browseEndpoint.
         *
         * @param  array $Browse
         * @return string
         */
        private static function PageType(array $Browse): string
        {
            return (string) ($Browse['browseEndpointContextSupportedConfigs']['browseEndpointContextMusicConfig']['pageType'] ?? '');
        }

        /**
         * Größtes Vorschaubild des Renderers.
         *
         * @param  array $Data
         * @return string
         */
        private static function Thumbnail(array $Data): string
        {
            $Lists = [
                $Data['thumbnailRenderer']['musicThumbnailRenderer']['thumbnail']['thumbnails'] ?? null,
                $Data['thumbnail']['musicThumbnailRenderer']['thumbnail']['thumbnails'] ?? null,
                $Data['thumbnail']['thumbnails'] ?? null,
                $Data['header']['tileHeaderRenderer']['thumbnail']['thumbnails'] ?? null,
            ];
            foreach ($Lists as $List) {
                if (!is_array($List) || count($List) === 0) {
                    continue;
                }
                usort($List, static fn (array $A, array $B): int => ($B['width'] ?? 0) <=> ($A['width'] ?? 0));
                $Url = (string) ($List[0]['url'] ?? '');
                return str_starts_with($Url, '//') ? 'https:' . $Url : $Url;
            }
            return '';
        }

        /**
         * Laufzeit als Text (z. B. 6:10), wenn vorhanden.
         *
         * @param  array $Data
         * @param  list<array> $SubtitleRuns Suchergebnisse tragen die Dauer als eigenen Teil der Unterzeile
         * @return string
         */
        private static function Duration(array $Data, array $SubtitleRuns): string
        {
            foreach (array_reverse($SubtitleRuns) as $Run) {
                $Text = trim((string) ($Run['text'] ?? ''));
                if (preg_match('/^\d{1,2}(:\d{2}){1,2}$/D', $Text)) {
                    return $Text;
                }
            }
            $Text = self::FirstText([
                $Data['lengthText'] ?? null,
                $Data['fixedColumns'][0]['musicResponsiveListItemFixedColumnRenderer']['text'] ?? null,
            ]);
            if ($Text !== '') {
                return $Text;
            }
            foreach ($Data['header']['tileHeaderRenderer']['thumbnailOverlays'] ?? [] as $Overlay) {
                if (isset($Overlay['thumbnailOverlayTimeStatusRenderer']['text'])) {
                    return self::FirstText([$Overlay['thumbnailOverlayTimeStatusRenderer']['text']]);
                }
            }
            return '';
        }

        /**
         * Erster nicht leerer Text aus runs oder simpleText.
         *
         * @param  array $Candidates
         * @return string
         */
        private static function FirstText(array $Candidates): string
        {
            foreach ($Candidates as $Candidate) {
                if (!is_array($Candidate)) {
                    continue;
                }
                $Text = isset($Candidate['simpleText']) ? (string) $Candidate['simpleText'] : (string) ($Candidate['runs'][0]['text'] ?? '');
                if ($Text !== '') {
                    return self::Clean($Text);
                }
            }
            return '';
        }

        /**
         * Geschützte Leerzeichen (U+00A0, z. B. in „Stimmungen & Momente“) durch normale ersetzen.
         *
         * @param  string $Text
         * @return string
         */
        private static function Clean(string $Text): string
        {
            return str_replace("\u{00A0}", ' ', $Text);
        }

        /**
         * Text aller runs bzw. simpleText.
         *
         * @param  array $Text
         * @return string
         */
        private static function RunsText(array $Text): string
        {
            if (isset($Text['simpleText'])) {
                return (string) $Text['simpleText'];
            }
            return implode('', array_map(static fn (array $Run): string => (string) ($Run['text'] ?? ''), $Text['runs'] ?? []));
        }

        /**
         * Durchläuft den Baum; der Rückruf erhält Schlüssel und Objekt und gibt zurück, ob weiter hinabgestiegen wird.
         *
         * @param  mixed $Node
         * @param  callable(string, array): bool $Visitor
         * @return void
         */
        private static function Walk(mixed $Node, callable $Visitor): void
        {
            if (!is_array($Node)) {
                return;
            }
            foreach ($Node as $Key => $Value) {
                if (!is_array($Value)) {
                    continue;
                }
                if (is_string($Key) && !$Visitor($Key, $Value)) {
                    continue;
                }
                self::Walk($Value, $Visitor);
            }
        }
    }
}
