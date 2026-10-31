<?php

declare(strict_types=1);

/**
 * YouTube Lounge (MDX): Fernsteuerung von YouTube auf Cast-Geräten und TV-Apps
 *
 * Ohne Symcon-Abhängigkeit: baut die Anfragen, zählt die Sitzungswerte (RID, ofs, AID) und
 * wertet die Ereignisse aus. Ablauf und Format sind im Spike a3 gegen einen Chromecast geprüft
 * (siehe STATUS.md); inoffizielle Schnittstelle.
 *
 * @project       YouTubeMusic
 * @file          Lounge.php
 * @package       YouTubeMusic
 * @author        Michael Tröger <nall4chan@gmail.com>
 * @copyright     2026 Michael Tröger
 * @license       Custom Non-Commercial Share-Alike License (see LICENSE)
 */

namespace YouTubeMusic {
    /**
     * Wiedergabezustände (Feld state in nowPlaying/onStateChange)
     */
    class LoungeState
    {
        public const Unknown = -1;
        public const Stopped = 0;
        public const Playing = 1;
        public const Paused = 2;
        public const Buffering = 3;
        /**
         * Neuer Titel geladen, noch nicht gestartet
         */
        public const Cued = 5;
    }

    /**
     * Befehle (req0__sc)
     */
    class LoungeCommand
    {
        public const Play = 'play';
        public const Pause = 'pause';
        public const Next = 'next';
        public const Previous = 'previous';
        public const SeekTo = 'seekTo';
        public const SetVolume = 'setVolume';
        public const Mute = 'mute';
        public const UnMute = 'unMute';
        /**
         * Argumente videoId, listId, optional currentTime (Start in Sekunden)
         */
        public const SetPlaylist = 'setPlaylist';
        public const AddVideo = 'addVideo';
        public const StopVideo = 'stopVideo';
        public const GetNowPlaying = 'getNowPlaying';
        public const GetVolume = 'getVolume';
    }

    /**
     * Anfragen und Auswertung der Lounge-API
     */
    class Lounge
    {
        public const MusicBase = 'https://music.youtube.com/';
        public const YouTubeBase = 'https://www.youtube.com/';
        public const TokenPath = 'api/lounge/pairing/get_lounge_token_batch';
        public const PairingPath = 'api/lounge/pairing/get_screen';
        public const BindPath = 'api/lounge/bc/bind';

        /**
         * Formularfelder für Lounge-Tokens zu Screen-IDs.
         *
         * @param  list<string> $ScreenIds
         * @return array<string, string>
         */
        public static function TokenRequest(array $ScreenIds): array
        {
            return ['screen_ids' => implode(',', $ScreenIds)];
        }

        /**
         * Wertet die Antwort von get_lounge_token_batch aus.
         *
         * @param  int $HttpCode
         * @param  string $Body
         * @return list<array{screenId: string, loungeToken: string, expiration: int}> expiration in Sekunden (Unix-Zeit)
         */
        public static function ParseTokens(int $HttpCode, string $Body): array
        {
            $Data = json_decode($Body, true);
            if ($HttpCode !== 200 || !is_array($Data)) {
                return [];
            }
            $Result = [];
            foreach ($Data['screens'] ?? [] as $Screen) {
                if (is_string($Screen['screenId'] ?? null) && is_string($Screen['loungeToken'] ?? null)) {
                    $Result[] = [
                        'screenId'    => $Screen['screenId'],
                        'loungeToken' => $Screen['loungeToken'],
                        'expiration'  => intdiv((int) ($Screen['expiration'] ?? 0), 1000),
                    ];
                }
            }
            return $Result;
        }

        /**
         * Formularfelder für die Kopplung einer TV-App per Code (Einstellungen → „Mit TV-Code verknüpfen“).
         *
         * @param  string $Code
         * @return array<string, string>
         */
        public static function PairingRequest(string $Code): array
        {
            return ['pairing_code' => preg_replace('/\D/', '', $Code)];
        }

        /**
         * Wertet die Antwort von get_screen aus.
         *
         * @param  int $HttpCode
         * @param  string $Body
         * @return array{screenId: string, loungeToken: string, name: string, expiration: int}|null
         */
        public static function ParsePairing(int $HttpCode, string $Body): ?array
        {
            $Screen = json_decode($Body, true)['screen'] ?? null;
            if ($HttpCode !== 200 || !is_array($Screen) || !is_string($Screen['screenId'] ?? null) || !is_string($Screen['loungeToken'] ?? null)) {
                return null;
            }
            return [
                'screenId'    => $Screen['screenId'],
                'loungeToken' => $Screen['loungeToken'],
                'name'        => (string) ($Screen['name'] ?? ''),
                'expiration'  => intdiv((int) ($Screen['expiration'] ?? 0), 1000),
            ];
        }

        /**
         * Neue Sitzung (noch ohne SID).
         *
         * @param  string $LoungeToken
         * @param  string $Name Name der Fernbedienung (erscheint auf dem Gerät)
         * @param  string $Base MusicBase oder YouTubeBase
         * @return array Sitzungswerte
         */
        public static function NewSession(string $LoungeToken, string $Name, string $Base = self::MusicBase): array
        {
            return [
                'base'  => $Base,
                'token' => $LoungeToken,
                'id'    => bin2hex(random_bytes(16)),
                'name'  => $Name,
                'sid'   => '',
                'gsid'  => '',
                'aid'   => 0,
                'rid'   => random_int(10000, 89999),
                'ofs'   => 0,
            ];
        }

        /**
         * URL und Body für den Handshake (Antwort enthält SID und gsessionid).
         *
         * @param  array $Session wird aktualisiert (RID)
         * @return array{0: string, 1: array<string, string>}
         */
        public static function Handshake(array &$Session, array $InitialState = []): array
        {
            $Session['sid'] = '';
            $Session['gsid'] = '';
            $Session['aid'] = 0;
            $Session['ofs'] = 0;
            $Session['rid']++;
            $Extra = ['RID' => (string) $Session['rid']];
            // Wiedergabe gleich beim Verbinden übergeben (wie die Web-App): method=setInitialState, params als JSON
            if ($InitialState !== []) {
                $Extra['method'] = 'setInitialState';
                $Extra['params'] = json_encode(array_map(static fn ($Value): string => (string) $Value, $InitialState), JSON_UNESCAPED_SLASHES);
            }
            return [self::BindUrl($Session, $Extra), ['count' => '0']];
        }

        /**
         * URL für den Abruf der Ereignisse ab AID (GET).
         *
         * @param  array $Session
         * @return string
         */
        public static function Poll(array $Session): string
        {
            return self::BindUrl($Session, ['SID' => $Session['sid'], 'gsessionid' => $Session['gsid'], 'RID' => 'rpc', 'AID' => (string) $Session['aid'], 'CI' => '0', 'TYPE' => 'xmlhttp']);
        }

        /**
         * URL und Body für einen Befehl; RID und ofs zählen hoch (sonst verwirft der Server den Befehl als Wiederholung).
         *
         * @param  array $Session wird aktualisiert
         * @param  string $Command Befehl aus LoungeCommand
         * @param  array<string, scalar> $Arguments
         * @return array{0: string, 1: array<string, string>}
         */
        public static function Command(array &$Session, string $Command, array $Arguments = []): array
        {
            $Session['rid']++;
            $Body = ['count' => '1', 'ofs' => (string) $Session['ofs'], 'req0__sc' => $Command];
            foreach ($Arguments as $Key => $Value) {
                $Body['req0_' . $Key] = is_bool($Value) ? ($Value ? 'true' : 'false') : (string) $Value;
            }
            $Session['ofs']++;
            return [self::BindUrl($Session, ['SID' => $Session['sid'], 'gsessionid' => $Session['gsid'], 'RID' => (string) $Session['rid'], 'AID' => (string) $Session['aid']]), $Body];
        }

        /**
         * Zerlegt eine bind-Antwort (`<Länge>\n<JSON-Array>`, auch mehrere hintereinander und unvollständig).
         *
         * Die Länge zählt Zeichen in UTF-16 wie in JavaScript, nicht Bytes. Unvollständige Reste
         * bleiben im Puffer für den nächsten Aufruf.
         *
         * @param  string $Buffer wird um die gelesenen Teile gekürzt
         * @return list<array{0: int, 1: string, 2: array}> Index, Ereignisname, Daten
         */
        public static function ParseChunks(string &$Buffer): array
        {
            $Events = [];
            while (preg_match('/^\s*(\d+)\n/', $Buffer, $Match)) {
                $Start = strlen($Match[0]);
                $Length = self::Utf8Length(substr($Buffer, $Start), (int) $Match[1]);
                if ($Length === null) {
                    break;
                }
                $Chunk = json_decode(substr($Buffer, $Start, $Length), true);
                $Buffer = (string) substr($Buffer, $Start + $Length);
                if (!is_array($Chunk)) {
                    continue;
                }
                foreach ($Chunk as $Entry) {
                    if (!is_array($Entry) || !is_int($Entry[0] ?? null) || !is_array($Entry[1] ?? null) || !is_string($Entry[1][0] ?? null)) {
                        continue;
                    }
                    $Data = $Entry[1][1] ?? [];
                    $Events[] = [$Entry[0], $Entry[1][0], is_array($Data) ? $Data : [$Data]];
                }
            }
            return $Events;
        }

        /**
         * Übernimmt Ereignisse in die Sitzung (SID, gsessionid, AID).
         *
         * @param  array $Session wird aktualisiert
         * @param  list<array{0: int, 1: string, 2: array}> $Events
         * @return bool false, wenn das Gerät die Sitzung beendet hat (loungeScreenDisconnected, noch kein Handshake)
         */
        public static function ApplySession(array &$Session, array $Events): bool
        {
            $Connected = true;
            foreach ($Events as [$Index, $Name, $Data]) {
                // Sitzung kann leer sein, wenn sie während der Abfrage verworfen wurde (z. B. Modul-Reload)
                $Session['aid'] = max((int) ($Session['aid'] ?? 0), $Index);
                switch ($Name) {
                    case 'c':
                        $Session['sid'] = (string) ($Data[0] ?? '');
                        break;
                    case 'S':
                        $Session['gsid'] = (string) ($Data[0] ?? '');
                        break;
                    case 'loungeScreenDisconnected':
                    case 'stop':
                        $Connected = false;
                        break;
                }
            }
            return $Connected;
        }

        /**
         * Übernimmt Ereignisse in den Wiedergabezustand.
         *
         * Werte, die ein Ereignis nicht mitliefert, behalten ihren letzten Stand.
         *
         * @param  array $State wird aktualisiert; updated = Zeitpunkt der letzten Positionsangabe (für die Hochrechnung)
         * @param  list<array{0: int, 1: string, 2: array}> $Events
         * @param  float $Now Zeit in Sekunden (microtime(true))
         * @return bool true, wenn sich der Zustand geändert hat
         */
        public static function ApplyState(array &$State, array $Events, float $Now): bool
        {
            $Before = $State;
            $State += self::EmptyState();
            foreach ($Events as [, $Name, $Data]) {
                switch ($Name) {
                    case 'nowPlaying':
                        if (array_key_exists('videoId', $Data)) {
                            if ((string) $Data['videoId'] !== $State['videoId']) {
                                // Neuer Titel: Werte des vorherigen gelten nicht mehr
                                $State['duration'] = 0.0;
                                $State['currentTime'] = 0.0;
                                $State['updated'] = $Now;
                            }
                            $State['videoId'] = (string) $Data['videoId'];
                        }
                        if (isset($Data['listId'])) {
                            $State['listId'] = (string) $Data['listId'];
                        }
                        self::ApplyPosition($State, $Data, $Now);
                        // nowPlaying ohne videoId: Wiedergabe beendet
                        if (!isset($Data['videoId']) && count($Data) === 0) {
                            $State['state'] = LoungeState::Stopped;
                            $State['videoId'] = '';
                        }
                        break;
                    case 'onStateChange':
                        self::ApplyPosition($State, $Data, $Now);
                        break;
                    case 'onVolumeChanged':
                        if (isset($Data['volume'])) {
                            $State['volume'] = max(0, min(100, (int) $Data['volume']));
                        }
                        if (isset($Data['muted'])) {
                            $State['muted'] = $Data['muted'] === 'true' || $Data['muted'] === true;
                        }
                        break;
                    case 'onHasPreviousNextChanged':
                        $State['hasPrevious'] = ($Data['hasPrevious'] ?? 'false') === 'true';
                        $State['hasNext'] = ($Data['hasNext'] ?? 'false') === 'true';
                        break;
                    case 'onPlaylistModeChanged':
                        $State['shuffle'] = ($Data['shuffleEnabled'] ?? 'false') === 'true';
                        $State['loop'] = ($Data['loopEnabled'] ?? 'false') === 'true';
                        break;
                    case 'playlistModified':
                        if (isset($Data['listId'])) {
                            $State['listId'] = (string) $Data['listId'];
                        }
                        break;
                    case 'loungeStatus':
                        // Ohne LOUNGE_SCREEN läuft die App auf dem Gerät nicht (mehr) bzw. die Screen-ID ist veraltet
                        $State['screenConnected'] = false;
                        $Devices = json_decode((string) ($Data['devices'] ?? '[]'), true);
                        foreach (is_array($Devices) ? $Devices : [] as $Device) {
                            if (($Device['type'] ?? '') === 'LOUNGE_SCREEN') {
                                $State['screenName'] = (string) ($Device['name'] ?? '');
                                $State['screenConnected'] = true;
                            }
                        }
                        break;
                }
            }
            $Compare = static fn (array $S): array => array_diff_key($S, ['updated' => 0, 'currentTime' => 0]);
            return $Compare($State) !== $Compare($Before + self::EmptyState())
                || abs(($State['currentTime'] ?? 0) - ($Before['currentTime'] ?? 0)) >= 2;
        }

        /**
         * Hochgerechnete Position (Sekunden): zuletzt gemeldete Position plus verstrichene Zeit, solange es spielt.
         *
         * @param  array $State
         * @param  float $Now
         * @return float
         */
        public static function Position(array $State, float $Now): float
        {
            $Position = (float) ($State['currentTime'] ?? 0);
            if (($State['state'] ?? LoungeState::Unknown) === LoungeState::Playing) {
                $Position += max(0, $Now - (float) ($State['updated'] ?? $Now));
            }
            $Duration = (float) ($State['duration'] ?? 0);
            return $Duration > 0 ? min($Position, $Duration) : $Position;
        }

        /**
         * Leerer Zustand.
         *
         * @return array
         */
        public static function EmptyState(): array
        {
            return [
                'videoId'     => '',
                'listId'      => '',
                'state'       => LoungeState::Unknown,
                'currentTime' => 0.0,
                'duration'    => 0.0,
                'updated'     => 0.0,
                'volume'      => -1,
                'muted'       => false,
                'hasPrevious' => false,
                'hasNext'     => false,
                'shuffle'     => false,
                'loop'        => false,
                'screenName'  => '',
                // bis zum ersten loungeStatus als verbunden annehmen
                'screenConnected' => true,
            ];
        }

        /**
         * Ist die Antwort ein Zeichen für eine abgelaufene Sitzung (neuer Handshake nötig)?
         *
         * @param  int $HttpCode
         * @param  string $Body
         * @return bool
         */
        public static function SessionExpired(int $HttpCode, string $Body): bool
        {
            return in_array($HttpCode, [400, 404, 410], true) || str_contains($Body, 'Unknown SID');
        }

        /**
         * Position, Dauer und Zustand aus nowPlaying/onStateChange.
         *
         * @param  array $State
         * @param  array $Data
         * @param  float $Now
         * @return void
         */
        private static function ApplyPosition(array &$State, array $Data, float $Now): void
        {
            if (isset($Data['currentTime']) && is_numeric($Data['currentTime'])) {
                $State['currentTime'] = (float) $Data['currentTime'];
                $State['updated'] = $Now;
            }
            if (isset($Data['duration']) && is_numeric($Data['duration']) && (float) $Data['duration'] > 0) {
                $State['duration'] = (float) $Data['duration'];
            }
            if (isset($Data['state']) && is_numeric($Data['state'])) {
                $State['state'] = (int) $Data['state'];
            }
        }

        /**
         * URL zu bind mit den gemeinsamen Parametern.
         *
         * @param  array $Session
         * @param  array<string, string> $Extra
         * @return string
         */
        private static function BindUrl(array $Session, array $Extra): string
        {
            // Wie die Web-App von YouTube Music (Mitschnitt 2026-10-31); authuser nur bei angemeldeter Sitzung
            $Query = [
                'device'        => 'REMOTE_CONTROL',
                'id'            => $Session['id'],
                'name'          => $Session['name'],
                'app'           => 'youtube.m-desktop',
                'mdxVersion'    => '3',
                'capabilities'  => 'que,mlm',
                'theme'         => $Session['base'] === self::MusicBase ? 'm' : 'cl',
                'loungeIdToken' => $Session['token'],
                'VER'           => '8',
                'v'             => '2',
                'CVER'          => '1',
                'ui'            => '1',
                'zx'            => bin2hex(random_bytes(6)),
                't'             => '1',
            ] + (($Session['auth'] ?? '') !== '' ? ['authuser' => '0'] : []);
            return $Session['base'] . self::BindPath . '?' . http_build_query($Extra + $Query);
        }

        /**
         * Bytes, die Count UTF-16-Zeichen am Anfang von Text belegen; null, wenn Text dafür zu kurz ist.
         *
         * @param  string $Text UTF-8
         * @param  int $Count
         * @return int|null
         */
        private static function Utf8Length(string $Text, int $Count): ?int
        {
            $Bytes = 0;
            $Units = 0;
            $Length = strlen($Text);
            while ($Units < $Count) {
                if ($Bytes >= $Length) {
                    return null;
                }
                $Byte = ord($Text[$Bytes]);
                $Size = $Byte < 0x80 ? 1 : ($Byte < 0xE0 ? 2 : ($Byte < 0xF0 ? 3 : 4));
                // Zeichen außerhalb der BMP zählen in UTF-16 doppelt
                $Units += $Size === 4 ? 2 : 1;
                $Bytes += $Size;
            }
            return $Bytes <= $Length ? $Bytes : null;
        }
    }
}
