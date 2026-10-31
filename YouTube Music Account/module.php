<?php

declare(strict_types=1);

eval('declare(strict_types=1);namespace YouTubeMusicAccount {?>' . file_get_contents(dirname(__DIR__) . '/libs/helper/DebugHelper.php') . '}');
eval('declare(strict_types=1);namespace YouTubeMusicAccount {?>' . file_get_contents(dirname(__DIR__) . '/libs/helper/BufferHelper.php') . '}');
eval('declare(strict_types=1);namespace YouTubeMusicAccount {?>' . file_get_contents(dirname(__DIR__) . '/libs/helper/SemaphoreHelper.php') . '}');
require_once dirname(__DIR__) . '/libs/Constants.php';
require_once dirname(__DIR__) . '/libs/GoogleOAuth.php';
require_once dirname(__DIR__) . '/libs/InnerTube.php';
require_once dirname(__DIR__) . '/libs/YouTubeLink.php';
require_once dirname(__DIR__) . '/libs/RequestCounter.php';

/**
 * YouTubeMusicAccount
 *
 * Google-Konto für YouTube und YouTube Music: Anmeldung per OAuth 2.0 Device Flow mit eigenem
 * Google-Cloud-Projekt, InnerTube (inoffiziell) für Startseite, Suche, Mediathek, Radio und Lyrics,
 * Rohzugriff auf die Data API, Anfragezähler. Die Geräte-Instanzen (YouTube Music Player) nutzen
 * den Account über den Datenfluss.
 *
 * @project       YouTubeMusic
 * @file          module.php
 * @package       YouTubeMusic
 * @author        Michael Tröger <nall4chan@gmail.com>
 * @copyright     2026 Michael Tröger
 * @license       Custom Non-Commercial Share-Alike License (see LICENSE)
 *
 * @property string $DeviceCode Gerätecode der laufenden Anmeldung
 * @property int $DeviceInterval Abfrageintervall der laufenden Anmeldung in Sekunden
 * @property int $DeviceExpires Ablaufzeit des Gerätecodes (Unix-Zeit)
 * @property array $MusicConfig Client-Version, Besucherkennung und Abrufzeit für den Music-Client
 * @property array $RequestStats Anfragen des Tages je Kategorie (siehe \YouTubeMusic\RequestCounter)
 * @property array $DeviceStates Letzter gemeldeter Zustand je Geräte-Instanz (Instanz-ID => Zustand)
 * @method bool SendDebug(string $Message, mixed $Data, int $Format)
 * @method bool lock(string $ident)
 * @method void unlock(string $ident)
 */
class YouTubeMusicAccount extends IPSModuleStrict
{
    use \YouTubeMusicAccount\DebugHelper;
    use \YouTubeMusicAccount\BufferHelper;
    use \YouTubeMusicAccount\Semaphore;

    /**
     * Name der Sperre für die Anfragezähler.
     */
    private const LockStats = 'Stats';

    /**
     * Status: Anmeldung abgelaufen bzw. widerrufen.
     */
    private const StatusSignInExpired = IS_EBASE + 1;

    /**
     * Access-Token so viele Sekunden vor Ablauf erneuern.
     */
    private const RefreshMargin = 300;

    /**
     * Client-Version und Besucherkennung so lange verwenden (Sekunden).
     */
    private const MusicConfigMaxAge = 86400;

    /**
     * Browse-IDs, die nur mit Anmeldung (TV-Client) Inhalte liefern.
     */
    private const PersonalBrowseIds = [
        \YouTubeMusic\InnerTubeClient::Library, \YouTubeMusic\InnerTubeClient::TvHome,
        'VLLM', 'VLLL', 'VLWL', 'FEmusic_history', 'FEmusic_library_landing', 'FEmusic_liked_videos',
    ];

    /**
     * User-Agent für InnerTube (der Music-Client erwartet einen Browser).
     */
    private const UserAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    /**
     * Clients für YTM_BrowseMore.
     */
    private const ClientMusic = 'music';
    private const ClientTv = 'tv';

    /**
     * Create
     *
     * @return void
     */
    public function Create(): void
    {
        //Never delete this line!
        parent::Create();
        $this->RegisterPropertyString(\YouTubeMusic\Property::ClientId, '');
        $this->RegisterPropertyString(\YouTubeMusic\Property::ClientSecret, '');

        $this->RegisterAttributeString(\YouTubeMusic\Attribute::RefreshToken, '');
        $this->RegisterAttributeString(\YouTubeMusic\Attribute::AccessToken, '');
        $this->RegisterAttributeInteger(\YouTubeMusic\Attribute::AccessExpiry, 0);
        $this->RegisterAttributeString(\YouTubeMusic\Attribute::ChannelId, '');
        $this->RegisterAttributeString(\YouTubeMusic\Attribute::ChannelName, '');
        $this->RegisterAttributeString(\YouTubeMusic\Attribute::RequestStats, '[]');
        $this->RegisterAttributeBoolean(\YouTubeMusic\Attribute::SignInExpired, false);

        $this->RegisterTimer(\YouTubeMusic\Timer::DevicePoll, 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'' . \YouTubeMusic\TimerAction::DevicePoll . '\', true);');
        $this->RegisterTimer(\YouTubeMusic\Timer::RefreshToken, 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'' . \YouTubeMusic\TimerAction::RefreshToken . '\', true);');

        $this->InitBuffer();
    }

    /**
     * ApplyChanges
     *
     * @return void
     */
    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();
        $this->InitBuffer();
        $this->SetTimerInterval(\YouTubeMusic\Timer::DevicePoll, 0);
        $this->ApplySignedIn();
        // Puffer der Gerätezustände ist leer: Geräte bitten, sich erneut zu melden (sie antworten zeitversetzt)
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->SendDataToChildren(json_encode([
                \YouTubeMusic\Flow::DataID => \YouTubeMusic\GUID::ToDevice,
                \YouTubeMusic\Flow::Type   => \YouTubeMusic\DeviceMessage::Announce,
            ]));
        }
    }

    /**
     * GetConfigurationForm
     *
     * Ergänzt Anmeldestatus und Anfragezähler im Formular.
     *
     * @return string
     */
    public function GetConfigurationForm(): string
    {
        $Form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $SignedIn = $this->SignedIn();
        // Kanal fehlt (z. B. API im Projekt noch nicht aktiviert): beim Öffnen erneut versuchen
        if ($SignedIn && $this->ReadAttributeString(\YouTubeMusic\Attribute::ChannelId) === '') {
            ob_start();
            $this->LoadChannel();
            ob_end_clean();
        }
        foreach ($Form['actions'] as &$Action) {
            switch ($Action['name'] ?? '') {
                case 'LoginInfo':
                    $Name = $this->ReadAttributeString(\YouTubeMusic\Attribute::ChannelName);
                    $Action['caption'] = $SignedIn
                        ? ($Name !== ''
                            ? sprintf($this->Translate('Signed in to YouTube as %s'), $Name)
                            : $this->Translate('Signed in, but the YouTube channel could not be read (see log)'))
                        : $this->Translate('Not signed in to Google');
                    break;
                case 'Login':
                    $Action['visible'] = !$SignedIn;
                    break;
                case 'LoginPopup':
                    // closeCaption übersetzt Symcon nicht selbst
                    $Action['popup']['closeCaption'] = $this->Translate('Close');
                    break;
                case 'Logout':
                    $Action['visible'] = $SignedIn;
                    break;
                case 'Requests':
                    $Stats = $this->GetRequestStatistics();
                    $Action['caption'] = sprintf($this->Translate('Requests today (%s, Pacific Time)'), $Stats['day']);
                    $Action['items'][0]['caption'] = sprintf($this->Translate('YouTube Data API quota: %d of %d units used'), $Stats['units'], $Stats['quota']);
                    $Action['items'][1]['values'] = $this->RequestRows($Stats);
                    break;
            }
        }
        unset($Action);
        return json_encode($Form);
    }

    /**
     * RequestAction
     *
     * Aufrufe der Timer.
     *
     * @param  string $Ident
     * @param  mixed $Value
     * @return void
     */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case \YouTubeMusic\TimerAction::DevicePoll:
                $this->PollDeviceToken();
                return;
            case \YouTubeMusic\TimerAction::RefreshToken:
                $this->RefreshAccessToken();
                return;
        }
        throw new InvalidArgumentException('Invalid Ident');
    }

    /**
     * ForwardData
     *
     * Anfragen der Geräte-Instanzen (die anonymen InnerTube-Funktionen funktionieren auch ohne Anmeldung).
     * Die Fehlermeldung wird nicht ausgegeben, sondern mit zurückgegeben: Das Gerät meldet sie selbst an
     * seinen Aufrufer, sonst käme sie dort als Warnung mit Dateiname und Zeile an.
     *
     * @param  string $JSONString
     * @return string serialize(['result' => mixed, 'error' => string])
     */
    public function ForwardData(string $JSONString): string
    {
        $Data = json_decode($JSONString, true);
        $Params = is_array($Data[\YouTubeMusic\Flow::Params] ?? null) ? $Data[\YouTubeMusic\Flow::Params] : [];
        $this->SendDebug(__FUNCTION__, $Data, 0);
        $Text = static fn (string $Key): string => is_scalar($Params[$Key] ?? null) ? (string) $Params[$Key] : '';
        ob_start();
        $Result = match ($Data[\YouTubeMusic\Flow::Function] ?? '') {
            \YouTubeMusic\AccountFunction::Search     => $this->Search($Text('Query'), $Text('Type')),
            \YouTubeMusic\AccountFunction::Browse     => $this->Browse($Text('BrowseId'), $Text('Params')),
            \YouTubeMusic\AccountFunction::BrowseMore => $this->BrowseMore($Text('Token'), $Text('Client')),
            \YouTubeMusic\AccountFunction::GetQueue   => $this->GetQueue($Text('VideoId'), $Text('PlaylistId')),
            \YouTubeMusic\AccountFunction::GetLyrics  => $this->GetLyrics($Text('VideoId')),
            \YouTubeMusic\AccountFunction::Metadata   => $this->Metadata($Text('VideoId')),
            \YouTubeMusic\AccountFunction::State      => $this->DeviceState((int) ($Data[\YouTubeMusic\Flow::Source] ?? 0), $Params),
            \YouTubeMusic\AccountFunction::Devices    => $this->GetDevices(),
            \YouTubeMusic\AccountFunction::SetRating  => $this->SetRating($Text('VideoId'), $Text('Rating')),
            default                                   => $this->UnknownFunction(),
        };
        $Error = trim((string) ob_get_clean());
        return serialize([\YouTubeMusic\Flow::Result => $Result, \YouTubeMusic\Flow::Error => $Error]);
    }

    /**
     * Startet die Anmeldung bei Google (Device Flow).
     *
     * Liefert die Adresse der Google-Anmeldeseite; das Formular öffnet sie (echo im onClick)
     * und zeigt den Code im Popup LoginPopup. Danach fragt der Timer DevicePoll das Token ab.
     *
     * @return string|false
     */
    public function StartLogin(): string|false
    {
        $ClientId = trim($this->ReadPropertyString(\YouTubeMusic\Property::ClientId));
        if ($ClientId === '' || trim($this->ReadPropertyString(\YouTubeMusic\Property::ClientSecret)) === '') {
            echo $this->Translate('Client ID or client secret is missing') . PHP_EOL;
            return false;
        }
        [$HttpCode, $Body, $CurlError] = $this->HttpRequest('POST', \YouTubeMusic\Google::DeviceCodeUrl, \YouTubeMusic\DeviceFlow::DeviceCodeRequest($ClientId));
        $this->SendDebug(__FUNCTION__, 'HTTP ' . $HttpCode . ' ' . $CurlError, 0);
        $Result = \YouTubeMusic\DeviceFlow::ParseDeviceCode($HttpCode, $Body);
        if (isset($Result['error'])) {
            echo $this->Translate('Sign-in failed') . ': ' . ($CurlError !== '' ? $CurlError : $Result['error']) . PHP_EOL;
            return false;
        }
        $this->DeviceCode = $Result['device_code'];
        $this->DeviceInterval = $Result['interval'];
        $this->DeviceExpires = time() + $Result['expires_in'];
        $this->SetTimerInterval(\YouTubeMusic\Timer::DevicePoll, $Result['interval'] * 1000);
        $this->SendDebug(__FUNCTION__, 'user_code ' . $Result['user_code'] . ', interval ' . $Result['interval'] . ' s, expires in ' . $Result['expires_in'] . ' s', 0);
        $this->UpdateFormField('UserCode', 'value', $Result['user_code']);
        $this->UpdateFormField('LoginPopup', 'visible', true);
        return $Result['verification_url'];
    }

    /**
     * Meldet von Google ab: widerruft das Token und löscht die gespeicherten Anmeldedaten.
     *
     * @return bool
     */
    public function Logout(): bool
    {
        $Token = $this->ReadAttributeString(\YouTubeMusic\Attribute::RefreshToken);
        if ($Token !== '') {
            [$HttpCode] = $this->HttpRequest('POST', \YouTubeMusic\Google::RevokeUrl, ['token' => $Token]);
            $this->SendDebug(__FUNCTION__, 'revoke HTTP ' . $HttpCode, 0);
        }
        $this->ClearTokens();
        $this->WriteAttributeBoolean(\YouTubeMusic\Attribute::SignInExpired, false);
        $this->ApplySignedIn();
        $this->ReloadForm();
        return true;
    }

    /**
     * Erneuert das Access-Token mit dem Refresh-Token.
     *
     * @return bool
     */
    public function RefreshAccessToken(): bool
    {
        $RefreshToken = $this->ReadAttributeString(\YouTubeMusic\Attribute::RefreshToken);
        if ($RefreshToken === '') {
            echo $this->Translate('Not signed in to Google') . PHP_EOL;
            return false;
        }
        [$HttpCode, $Body, $CurlError] = $this->HttpRequest('POST', \YouTubeMusic\Google::TokenUrl, \YouTubeMusic\DeviceFlow::RefreshRequest(
            trim($this->ReadPropertyString(\YouTubeMusic\Property::ClientId)),
            trim($this->ReadPropertyString(\YouTubeMusic\Property::ClientSecret)),
            $RefreshToken
        ));
        $Result = \YouTubeMusic\DeviceFlow::ParseTokenResponse($HttpCode, $Body);
        $this->SendDebug(__FUNCTION__, 'HTTP ' . $HttpCode . ', ' . $Result['result'] . ' ' . $CurlError, 0);
        if ($Result['result'] === \YouTubeMusic\TokenResult::Ok) {
            $this->StoreTokens($Result);
            return true;
        }
        if ($Result['result'] === \YouTubeMusic\TokenResult::InvalidGrant) {
            // Refresh-Token widerrufen oder abgelaufen: neu anmelden
            $this->ClearTokens();
            $this->WriteAttributeBoolean(\YouTubeMusic\Attribute::SignInExpired, true);
            $this->ApplySignedIn();
            echo $this->Translate('Sign-in expired. Please sign in again.') . PHP_EOL;
            return false;
        }
        // Netzwerk- oder Serverfehler: in einer Minute erneut versuchen
        $this->SetTimerInterval(\YouTubeMusic\Timer::RefreshToken, 60000);
        echo $this->Translate('Could not refresh the access token') . ': ' . ($CurlError !== '' ? $CurlError : ($Result['error'] ?? '')) . PHP_EOL;
        return false;
    }

    /**
     * Rohzugriff auf die Data API bzw. InnerTube mit dem Access-Token des Kontos.
     *
     * Nur für Hosts aus \YouTubeMusic\ApiHosts::Allowed.
     *
     * @param  string $Method GET, POST, PUT, DELETE
     * @param  string $Url Vollständige URL
     * @param  string $Body JSON-Body (leer für GET)
     * @return string|false Antwort als JSON-Text mit http und body
     */
    public function ApiRequest(string $Method, string $Url, string $Body): string|false
    {
        $Method = strtoupper($Method);
        if (!in_array($Method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
            echo $this->Translate('Invalid method') . PHP_EOL;
            return false;
        }
        $Host = parse_url($Url, PHP_URL_HOST);
        if (parse_url($Url, PHP_URL_SCHEME) !== 'https' || !in_array($Host, \YouTubeMusic\ApiHosts::Allowed, true)) {
            echo $this->Translate('URL not allowed') . PHP_EOL;
            return false;
        }
        $Token = $this->AccessToken();
        if ($Token === '') {
            echo $this->Translate('Not signed in to Google') . PHP_EOL;
            return false;
        }
        $Headers = ['Authorization: Bearer ' . $Token, 'Accept: application/json'];
        if ($Body !== '') {
            $Headers[] = 'Content-Type: application/json';
        }
        [$HttpCode, $Response, $CurlError] = $this->HttpRequest($Method, $Url, $Body !== '' ? $Body : null, $Headers, 15);
        $this->SendDebug(__FUNCTION__, $Method . ' ' . $Url . ' → HTTP ' . $HttpCode . ', ' . strlen($Response) . ' Bytes ' . $CurlError, 0);
        if ($CurlError !== '') {
            echo $CurlError . PHP_EOL;
            return false;
        }
        return json_encode(['http' => $HttpCode, 'body' => json_decode($Response, true) ?? $Response]);
    }

    /**
     * Anfragen des laufenden Tages (Tageswechsel um 0 Uhr Pacific Time wie das Kontingent der Data API).
     *
     * @return array day, requests und errors je Kategorie (oauth, dataApi, innerTubeMusic, innerTubeTv, other),
     *               units (verbrauchte Kontingent-Einheiten), quota (Tageskontingent), previous (Vortag)
     */
    public function GetRequestStatistics(): array
    {
        $Stats = is_array($this->RequestStats) ? $this->RequestStats : [];
        $Today = \YouTubeMusic\RequestCounter::Day(time());
        if (($Stats['day'] ?? '') !== $Today) {
            $Previous = isset($Stats['day']) ? array_diff_key($Stats, ['previous' => 0]) : null;
            $Stats = \YouTubeMusic\RequestCounter::Empty($Today) + ($Previous !== null ? ['previous' => $Previous] : []);
        }
        return $Stats + ['quota' => \YouTubeMusic\RequestCounter::DailyQuota];
    }

    /**
     * Sucht in YouTube Music (InnerTube, ohne Kontingent).
     *
     * @param  string $Query Suchbegriff
     * @param  string $Type song, video, album, artist, playlist oder leer für alle
     * @return array|false shelves (title, items) und continuation
     */
    public function Search(string $Query, string $Type): array|false
    {
        $Query = trim($Query);
        if ($Query === '') {
            echo $this->Translate('Search term is empty') . PHP_EOL;
            return false;
        }
        if ($Type !== '' && !isset(\YouTubeMusic\InnerTubeClient::SearchFilter[$Type])) {
            echo $this->Translate('Invalid type') . PHP_EOL;
            return false;
        }
        $Response = $this->InnerTube('search', \YouTubeMusic\InnerTubeClient::Search($Query, $Type), false);
        if ($Response === null) {
            return false;
        }
        return \YouTubeMusic\InnerTubeParser::Shelves($Response) + ['client' => self::ClientMusic];
    }

    /**
     * Lädt eine Seite (Startseite, Charts, Neu, Stimmungen, Mediathek, Playlist, Album, Künstler).
     *
     * Persönliche Seiten (Mediathek, YouTube-Startseite, Likes) und mit Anmeldung alle Playlists (VL…,
     * auch Mixe/Supermixe und Recaps, die pro Nutzer erzeugt werden) über den TV-Client mit Token,
     * alle anderen (Startseite, Charts, Neu, Stimmungen, Alben, Künstler) anonym über den Music-Client.
     * Liefert der Music-Client nichts, wird mit Anmeldung der TV-Client versucht.
     *
     * @param  string $BrowseId z. B. FEmusic_home, FEmusic_charts, FEmusic_liked_playlists, VLPL…, MPREb_…, UC…
     * @param  string $Params Zusatzparameter (z. B. Stimmung/Genre), sonst leer
     * @return array|false shelves (title, items), continuation und client (für YTM_BrowseMore)
     */
    public function Browse(string $BrowseId, string $Params): array|false
    {
        if (preg_match('/^[A-Za-z0-9_-]{2,120}$/D', $BrowseId) !== 1) {
            echo $this->Translate('Invalid browse ID') . PHP_EOL;
            return false;
        }
        $Payload = \YouTubeMusic\InnerTubeClient::Browse($BrowseId, $Params);
        $Personal = in_array($BrowseId, self::PersonalBrowseIds, true);
        if ($Personal && !$this->SignedIn()) {
            echo $this->Translate('Not signed in to Google') . PHP_EOL;
            return false;
        }
        // Mixe (VLRD…) liefert der Music-Client anonym mit fremdem Inhalt statt leer: Playlists mit Anmeldung immer per TV-Client
        $Personal = $Personal || ($this->SignedIn() && str_starts_with($BrowseId, 'VL'));
        if (!$Personal) {
            $Response = $this->InnerTube('browse', $Payload, false, $this->SignedIn());
            $Result = $Response !== null ? \YouTubeMusic\InnerTubeParser::Shelves($Response) : null;
            if ($Result !== null && count($Result['shelves']) > 0) {
                return $Result + ['client' => self::ClientMusic];
            }
            if (!$this->SignedIn()) {
                if ($Result === null) {
                    return false;
                }
                return $Result + ['client' => self::ClientMusic];
            }
            $this->SendDebug(__FUNCTION__, 'music client returned nothing, trying TV client', 0);
        }
        $Response = $this->InnerTube('browse', $Payload, true);
        if ($Response === null) {
            return false;
        }
        return \YouTubeMusic\InnerTubeParser::Shelves($Response) + ['client' => self::ClientTv];
    }

    /**
     * Lädt die nächste Seite einer Liste aus YTM_Search oder YTM_Browse.
     *
     * @param  string $Token continuation aus dem vorherigen Ergebnis
     * @param  string $Client client aus dem vorherigen Ergebnis (music oder tv)
     * @return array|false shelves, continuation und client
     */
    public function BrowseMore(string $Token, string $Client): array|false
    {
        if (preg_match('/^[A-Za-z0-9_%=-]{10,2000}$/D', $Token) !== 1 || !in_array($Client, [self::ClientMusic, self::ClientTv], true)) {
            echo $this->Translate('Invalid continuation') . PHP_EOL;
            return false;
        }
        $Response = $this->InnerTube('browse', \YouTubeMusic\InnerTubeClient::Continuation($Token), $Client === self::ClientTv);
        if ($Response === null) {
            return false;
        }
        return \YouTubeMusic\InnerTubeParser::Shelves($Response) + ['client' => $Client];
    }

    /**
     * Warteschlange zu einem Titel bzw. einer Liste (Radio, wenn keine Liste angegeben ist).
     *
     * Mit Anmeldung personalisiert (TV-Client) und mit Like-Status, sonst anonym.
     *
     * @param  string $VideoId
     * @param  string $PlaylistId Leer: Radio zum Titel
     * @return array|false items, playlistId, lyricsBrowseId, likeStatus
     */
    public function GetQueue(string $VideoId, string $PlaylistId): array|false
    {
        if (($VideoId !== '' && !\YouTubeMusic\YouTubeLink::IsVideoId($VideoId)) || ($VideoId === '' && $PlaylistId === '')
            || ($PlaylistId !== '' && preg_match('/^[A-Za-z0-9_-]{2,80}$/D', $PlaylistId) !== 1)) {
            echo $this->Translate('Invalid video or playlist ID') . PHP_EOL;
            return false;
        }
        $Response = $this->InnerTube('next', \YouTubeMusic\InnerTubeClient::Next($VideoId, $PlaylistId), $this->SignedIn());
        if ($Response === null) {
            return false;
        }
        return \YouTubeMusic\InnerTubeParser::Queue($Response, $VideoId);
    }

    /**
     * Liedtext zu einem Titel.
     *
     * @param  string $VideoId
     * @return array|false text und source; text ist leer, wenn es keinen Liedtext gibt
     */
    public function GetLyrics(string $VideoId): array|false
    {
        if (!\YouTubeMusic\YouTubeLink::IsVideoId($VideoId)) {
            echo $this->Translate('Invalid video or playlist ID') . PHP_EOL;
            return false;
        }
        // Die Lyrics-ID kommt nur über den Music-Client
        $Next = $this->InnerTube('next', \YouTubeMusic\InnerTubeClient::Next($VideoId), false);
        if ($Next === null) {
            return false;
        }
        $LyricsId = \YouTubeMusic\InnerTubeParser::Queue($Next, $VideoId)['lyricsBrowseId'];
        if ($LyricsId === '') {
            return ['text' => '', 'source' => ''];
        }
        // Zeitgestempelt (synchron zum Titel) liefert nur der Android-Client; ohne Zeiten der normale Liedtext
        $Timed = $this->AndroidInnerTube('browse', \YouTubeMusic\InnerTubeClient::Browse($LyricsId));
        if ($Timed !== null) {
            $Lyrics = \YouTubeMusic\InnerTubeParser::TimedLyrics($Timed);
            if ($Lyrics['lines'] !== []) {
                return ['text' => implode("\n", array_column($Lyrics['lines'], 'text')), 'source' => $Lyrics['source'], 'lines' => $Lyrics['lines']];
            }
        }
        $Response = $this->InnerTube('browse', \YouTubeMusic\InnerTubeClient::Browse($LyricsId), false);
        if ($Response === null) {
            return false;
        }
        return \YouTubeMusic\InnerTubeParser::Lyrics($Response);
    }

    /**
     * Bewertet einen Titel im Konto (Data API videos.rate, 50 Kontingent-Einheiten).
     *
     * @param  string $VideoId
     * @param  string $Rating like, dislike oder none (Bewertung entfernen)
     * @return bool
     */
    public function SetRating(string $VideoId, string $Rating): bool
    {
        if (!\YouTubeMusic\YouTubeLink::IsVideoId($VideoId) || !in_array($Rating, ['like', 'dislike', 'none'], true)) {
            echo $this->Translate('Invalid video ID or rating') . PHP_EOL;
            return false;
        }
        $Token = $this->AccessToken();
        if ($Token === '') {
            echo $this->Translate('Not signed in to Google') . PHP_EOL;
            return false;
        }
        $Url = 'https://www.googleapis.com/youtube/v3/videos/rate?' . http_build_query(['id' => $VideoId, 'rating' => $Rating]);
        [$HttpCode, $Body, $CurlError] = $this->HttpRequest('POST', $Url, '', ['Authorization: Bearer ' . $Token, 'Content-Length: 0']);
        $this->SendDebug(__FUNCTION__, $VideoId . ' ' . $Rating . ' → HTTP ' . $HttpCode . ' ' . $CurlError, 0);
        if ($HttpCode === 204 || $HttpCode === 200) {
            return true;
        }
        $Data = json_decode($Body, true);
        $Reason = $CurlError !== '' ? $CurlError : (is_string($Data['error']['message'] ?? null) ? $Data['error']['message'] : 'HTTP ' . $HttpCode);
        echo $this->Translate('Could not rate the video') . ': ' . $Reason . PHP_EOL;
        return false;
    }

    /**
     * Geräte (YouTube Music Player) an diesem Account mit ihrem zuletzt gemeldeten Zustand.
     *
     * @return list<array{id: int, name: string, type: int, state: array}>
     */
    public function GetDevices(): array
    {
        $States = is_array($this->DeviceStates) ? $this->DeviceStates : [];
        $Devices = [];
        foreach ($this->Children() as $ID) {
            $Devices[] = [
                'id'    => $ID,
                'name'  => IPS_GetName($ID),
                // Typ aus der letzten Meldung des Geräts (-1 = noch keine); IPS_GetProperty fremder Instanzen kann sperren
                'type'  => (int) ($States[$ID]['type'] ?? -1),
                'state' => $States[$ID] ?? [],
            ];
        }
        return $Devices;
    }

    /**
     * Instanz-IDs der Geräte, die mit diesem Account verbunden sind.
     *
     * @return list<int>
     */
    private function Children(): array
    {
        return array_values(array_filter(
            IPS_GetInstanceListByModuleID(\YouTubeMusic\GUID::Player),
            fn (int $ID): bool => IPS_GetInstance($ID)['ConnectionID'] === $this->InstanceID
        ));
    }

    /**
     * Zustand eines Geräts merken und an alle Geräte verteilen (für deren Kacheln).
     *
     * @param  int $Source
     * @param  array $State
     * @return bool
     */
    private function DeviceState(int $Source, array $State): bool
    {
        if (!in_array($Source, $this->Children(), true)) {
            return false;
        }
        $States = is_array($this->DeviceStates) ? $this->DeviceStates : [];
        $States[$Source] = $State + ['time' => time()];
        $this->DeviceStates = $States;
        $this->SendDataToChildren(json_encode([
            \YouTubeMusic\Flow::DataID => \YouTubeMusic\GUID::ToDevice,
            \YouTubeMusic\Flow::Type   => \YouTubeMusic\DeviceMessage::State,
            \YouTubeMusic\Flow::Source => $Source,
            \YouTubeMusic\Flow::Data   => $States[$Source],
        ]));
        return true;
    }

    /**
     * Meldung für eine unbekannte Funktion im Datenfluss.
     *
     * @return false
     */
    private function UnknownFunction(): bool
    {
        echo $this->Translate('Unknown function') . PHP_EOL;
        return false;
    }

    /**
     * Titel, Interpret, Album, Cover und Dauer zu einer Video-ID.
     *
     * Über den Music-Client (anonym), denn nur dieser liefert Interpreten und Album getrennt.
     * Fehler nur ins Debug: Die Geräte rufen das im Timer auf.
     *
     * @param  string $VideoId
     * @return array|false title, artist, album, cover, duration
     */
    private function Metadata(string $VideoId): array|false
    {
        if (!\YouTubeMusic\YouTubeLink::IsVideoId($VideoId)) {
            return false;
        }
        $Response = $this->InnerTube('next', \YouTubeMusic\InnerTubeClient::Next($VideoId), false, true);
        if ($Response === null) {
            return false;
        }
        $Items = \YouTubeMusic\InnerTubeParser::Queue($Response, $VideoId)['items'];
        $Matches = array_values(array_filter($Items, static fn (array $Item): bool => $Item['videoId'] === $VideoId));
        $Item = $Matches[0] ?? null;
        if ($Item === null) {
            $this->SendDebug(__FUNCTION__, 'no item for ' . $VideoId, 0);
            return false;
        }
        // Like-Status nur mit Anmeldung (TV-Client), sonst leer
        $Like = '';
        if ($this->SignedIn()) {
            $Tv = $this->InnerTube('next', \YouTubeMusic\InnerTubeClient::Next($VideoId), true, true);
            $Like = $Tv !== null ? \YouTubeMusic\InnerTubeParser::Queue($Tv, $VideoId)['likeStatus'] : '';
        }
        return [
            'title'    => $Item['title'],
            'artist'   => count($Item['artists']) > 0 ? implode(', ', $Item['artists']) : $Item['subtitle'],
            'album'    => $Item['album'],
            'cover'    => \YouTubeMusic\InnerTubeParser::LargeThumbnail($Item['thumbnail']),
            'duration' => $Item['duration'],
            // LIKE, DISLIKE, INDIFFERENT oder leer (unbekannt)
            'like'     => $Like,
        ];
    }

    /**
     * Zeilen der Tabelle „Anfragen heute“.
     *
     * @param  array $Stats
     * @return list<array{Category: string, Requests: int, Errors: int}>
     */
    private function RequestRows(array $Stats): array
    {
        $Names = [
            \YouTubeMusic\RequestCategory::OAuth          => 'Google sign-in',
            \YouTubeMusic\RequestCategory::DataApi        => 'YouTube Data API',
            \YouTubeMusic\RequestCategory::InnerTubeMusic => 'YouTube Music (InnerTube, anonymous)',
            \YouTubeMusic\RequestCategory::InnerTubeTv    => 'YouTube (InnerTube, with sign-in)',
            \YouTubeMusic\RequestCategory::Other          => 'Other',
        ];
        $Rows = [];
        foreach ($Names as $Category => $Name) {
            $Rows[] = [
                'Category' => $this->Translate($Name),
                'Requests' => (int) ($Stats['requests'][$Category] ?? 0),
                'Errors'   => (int) ($Stats['errors'][$Category] ?? 0),
            ];
        }
        return $Rows;
    }

    /**
     * HTTP-Anfrage mit Zählung je Kategorie (siehe YTM_GetRequestStatistics).
     *
     * Gespeichert wird nur bei Anfragen an die Data API (Kontingent) und beim Tageswechsel.
     *
     * @param  string $Method
     * @param  string $Url
     * @param  array<string, string>|string|null $Body
     * @param  list<string> $Headers
     * @param  int $Timeout
     * @return array{0: int, 1: string, 2: string}
     */
    private function HttpRequest(string $Method, string $Url, array|string|null $Body = null, array $Headers = [], int $Timeout = 10): array
    {
        $Result = \YouTubeMusic\Http::Request($Method, $Url, $Body, $Headers, $Timeout);
        [$HttpCode, , $CurlError] = $Result;
        // 428 (Anmeldung noch nicht bestätigt) ist beim Device Flow der Normalfall
        $Failed = $CurlError !== '' || $HttpCode === 0 || ($HttpCode >= 400 && $HttpCode !== 428);
        if ($this->lock(self::LockStats)) {
            $Stats = is_array($this->RequestStats) ? $this->RequestStats : [];
            $Previous = $Stats['day'] ?? '';
            $NewDay = \YouTubeMusic\RequestCounter::Count($Stats, $Method, $Url, $Failed, time());
            $this->RequestStats = $Stats;
            $this->unlock(self::LockStats);
            if ($NewDay || \YouTubeMusic\RequestCounter::Units($Method, $Url) > 0) {
                $this->WriteAttributeString(\YouTubeMusic\Attribute::RequestStats, json_encode($Stats));
            }
            if ($NewDay && $Previous !== '') {
                $this->SendDebug('Requests', 'day ' . $Previous . ': ' . json_encode($Stats['previous'] ?? []), 0);
            }
        }
        return $Result;
    }

    /**
     * Anfrage an InnerTube.
     *
     * @param  string $Endpoint browse, search, next
     * @param  array $Payload
     * @param  bool $Tv TV-Client mit Token (sonst Music-Client ohne Anmeldung)
     * @param  bool $Quiet Fehler nur ins Debug (für Versuche mit Rückfall)
     * @return array|null Antwort oder null bei Fehler (Meldung ist ausgegeben)
     */
    private function InnerTube(string $Endpoint, array $Payload, bool $Tv, bool $Quiet = false): ?array
    {
        [$Language, $Country] = $this->LanguageAndCountry();
        if ($Tv) {
            $Token = $this->AccessToken();
            if ($Token === '') {
                if (!$Quiet) {
                    echo $this->Translate('Not signed in to Google') . PHP_EOL;
                }
                return null;
            }
            $Url = \YouTubeMusic\InnerTubeClient::TvUrl . $Endpoint . '?prettyPrint=false';
            $Context = \YouTubeMusic\InnerTubeClient::Context(\YouTubeMusic\InnerTubeClient::TvClientName, \YouTubeMusic\InnerTubeClient::TvClientVersion, $Language, $Country);
            $Headers = ['Authorization: Bearer ' . $Token];
        } else {
            $Config = $this->MusicClientConfig();
            $Url = \YouTubeMusic\InnerTubeClient::MusicUrl . $Endpoint . '?prettyPrint=false';
            $Context = \YouTubeMusic\InnerTubeClient::Context(\YouTubeMusic\InnerTubeClient::MusicClientName, $Config['clientVersion'], $Language, $Country, $Config['visitorData']);
            $Headers = ['Origin: https://music.youtube.com', 'Referer: https://music.youtube.com/'];
        }
        $Headers[] = 'Content-Type: application/json';
        $Headers[] = 'User-Agent: ' . self::UserAgent;
        [$HttpCode, $Body, $CurlError] = $this->HttpRequest('POST', $Url, \YouTubeMusic\InnerTubeClient::Body($Context, $Payload), $Headers, 15);
        $this->SendDebug('InnerTube', ($Tv ? 'TV ' : 'Music ') . $Endpoint . ' ' . json_encode($Payload, JSON_UNESCAPED_SLASHES) . ' → HTTP ' . $HttpCode . ', ' . strlen($Body) . ' Bytes ' . $CurlError, 0);
        $Data = json_decode($Body, true);
        if ($HttpCode === 200 && is_array($Data)) {
            return $Data;
        }
        if (!$Quiet) {
            $Reason = $CurlError !== '' ? $CurlError : (is_string($Data['error']['message'] ?? null) ? $Data['error']['message'] : 'HTTP ' . $HttpCode);
            echo $this->Translate('YouTube request failed') . ': ' . $Reason . PHP_EOL;
        }
        return null;
    }

    /**
     * Anfrage an InnerTube als Android-App von YouTube Music (ohne Anmeldung). Fehler nur ins Debug.
     *
     * @param  string $Endpoint
     * @param  array $Payload
     * @return array|null
     */
    private function AndroidInnerTube(string $Endpoint, array $Payload): ?array
    {
        [$Language, $Country] = $this->LanguageAndCountry();
        $Context = \YouTubeMusic\InnerTubeClient::Context(\YouTubeMusic\InnerTubeClient::AndroidClientName, \YouTubeMusic\InnerTubeClient::AndroidClientVersion, $Language, $Country);
        $Context['client']['androidSdkVersion'] = \YouTubeMusic\InnerTubeClient::AndroidSdkVersion;
        $Headers = ['Content-Type: application/json', 'User-Agent: ' . \YouTubeMusic\InnerTubeClient::AndroidUserAgent];
        [$HttpCode, $Body, $CurlError] = $this->HttpRequest('POST', \YouTubeMusic\InnerTubeClient::MusicUrl . $Endpoint . '?prettyPrint=false', \YouTubeMusic\InnerTubeClient::Body($Context, $Payload), $Headers, 15);
        $this->SendDebug('InnerTube', 'Android ' . $Endpoint . ' ' . json_encode($Payload, JSON_UNESCAPED_SLASHES) . ' → HTTP ' . $HttpCode . ', ' . strlen($Body) . ' Bytes ' . $CurlError, 0);
        $Data = json_decode($Body, true);
        return $HttpCode === 200 && is_array($Data) ? $Data : null;
    }

    /**
     * Client-Version und Besucherkennung des Music-Clients (aus der Startseite, einmal am Tag).
     *
     * @return array{clientVersion: string, visitorData: string}
     */
    private function MusicClientConfig(): array
    {
        $Config = is_array($this->MusicConfig) ? $this->MusicConfig : [];
        if (($Config['time'] ?? 0) + self::MusicConfigMaxAge > time() && ($Config['clientVersion'] ?? '') !== '') {
            return $Config;
        }
        // Cookie SOCS überspringt die Zustimmungsseite
        [$HttpCode, $Html] = $this->HttpRequest('GET', 'https://music.youtube.com/', null, ['User-Agent: ' . self::UserAgent, 'Cookie: SOCS=CAI', 'Accept-Language: ' . implode('-', $this->LanguageAndCountry())], 15);
        $Config = \YouTubeMusic\InnerTubeClient::ParseConfig($Html);
        $this->SendDebug(__FUNCTION__, 'HTTP ' . $HttpCode . ', version ' . ($Config['clientVersion'] ?: 'fallback') . ', visitorData ' . ($Config['visitorData'] !== '' ? 'yes' : 'no'), 0);
        if ($Config['clientVersion'] === '') {
            // Rückfall, aber bald erneut versuchen
            return ['clientVersion' => \YouTubeMusic\InnerTubeClient::MusicClientVersion, 'visitorData' => ''];
        }
        $this->MusicConfig = $Config + ['time' => time()];
        return $Config;
    }

    /**
     * Sprache und Land aus der Systemsprache (z. B. de_DE → de, DE).
     *
     * @return array{0: string, 1: string}
     */
    private function LanguageAndCountry(): array
    {
        $Parts = explode('_', IPS_GetSystemLanguage());
        $Language = strtolower($Parts[0] ?? '') ?: 'en';
        $Country = strtoupper($Parts[1] ?? '') ?: strtoupper($Language);
        return [$Language, $Country];
    }

    /**
     * Fragt während der Anmeldung das Token zum Gerätecode ab (Timer DevicePoll).
     *
     * @return void
     */
    private function PollDeviceToken(): void
    {
        if ($this->DeviceCode === '' || time() > $this->DeviceExpires) {
            $this->SetTimerInterval(\YouTubeMusic\Timer::DevicePoll, 0);
            $this->DeviceCode = '';
            $this->SendDebug(__FUNCTION__, 'device code expired', 0);
            return;
        }
        [$HttpCode, $Body, $CurlError] = $this->HttpRequest('POST', \YouTubeMusic\Google::TokenUrl, \YouTubeMusic\DeviceFlow::TokenPollRequest(
            trim($this->ReadPropertyString(\YouTubeMusic\Property::ClientId)),
            trim($this->ReadPropertyString(\YouTubeMusic\Property::ClientSecret)),
            $this->DeviceCode
        ));
        $Result = \YouTubeMusic\DeviceFlow::ParseTokenResponse($HttpCode, $Body);
        $this->SendDebug(__FUNCTION__, 'HTTP ' . $HttpCode . ', ' . $Result['result'] . ' ' . ($Result['error'] ?? '') . $CurlError, 0);
        switch ($Result['result']) {
            case \YouTubeMusic\TokenResult::Pending:
                return;
            case \YouTubeMusic\TokenResult::SlowDown:
                $this->DeviceInterval += 5;
                $this->SetTimerInterval(\YouTubeMusic\Timer::DevicePoll, $this->DeviceInterval * 1000);
                return;
            case \YouTubeMusic\TokenResult::Ok:
                $this->SetTimerInterval(\YouTubeMusic\Timer::DevicePoll, 0);
                $this->DeviceCode = '';
                $this->SendDebug(__FUNCTION__, 'scope: ' . ($Result['scope'] ?? '') . ', refresh token ' . (isset($Result['refresh_token']) ? 'received' : 'missing'), 0);
                $this->StoreTokens($Result);
                $this->LoadChannel();
                $this->ApplySignedIn();
                $this->ReloadForm();
                return;
        }
        // Abgelehnt, abgelaufen oder Fehler: Anmeldung beenden
        $this->SetTimerInterval(\YouTubeMusic\Timer::DevicePoll, 0);
        $this->DeviceCode = '';
        echo $this->Translate('Sign-in failed') . ': ' . ($Result['error'] ?? $Result['result']) . PHP_EOL;
    }

    /**
     * Liest Name und ID des eigenen YouTube-Kanals (channels.list, 1 Einheit).
     *
     * @return void
     */
    private function LoadChannel(): void
    {
        $Token = $this->AccessToken();
        [$HttpCode, $Body] = $this->HttpRequest('GET', 'https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true', null, ['Authorization: Bearer ' . $Token]);
        $Data = json_decode($Body, true);
        $Channel = $Data['items'][0] ?? null;
        $this->SendDebug(__FUNCTION__, 'HTTP ' . $HttpCode . ', ' . (is_array($Channel) ? 'channel found' : 'no channel'), 0);
        if ($HttpCode !== 200) {
            $this->WriteAttributeString(\YouTubeMusic\Attribute::ChannelId, '');
            $this->WriteAttributeString(\YouTubeMusic\Attribute::ChannelName, '');
            $Reason = is_string($Data['error']['message'] ?? null) ? $Data['error']['message'] : 'HTTP ' . $HttpCode;
            echo $this->Translate('Could not read the YouTube channel') . ': ' . $Reason . PHP_EOL;
            return;
        }
        $this->WriteAttributeString(\YouTubeMusic\Attribute::ChannelId, (string) ($Channel['id'] ?? ''));
        $this->WriteAttributeString(\YouTubeMusic\Attribute::ChannelName, (string) ($Channel['snippet']['title'] ?? $this->Translate('(no channel)')));
    }

    /**
     * Liefert ein gültiges Access-Token, erneuert es bei Bedarf.
     *
     * @return string Leer, wenn nicht angemeldet oder die Erneuerung fehlschlägt
     */
    private function AccessToken(): string
    {
        if (!$this->SignedIn()) {
            return '';
        }
        if ($this->ReadAttributeInteger(\YouTubeMusic\Attribute::AccessExpiry) - 60 < time()) {
            ob_start();
            $Ok = $this->RefreshAccessToken();
            ob_end_clean();
            if (!$Ok) {
                return '';
            }
        }
        return $this->ReadAttributeString(\YouTubeMusic\Attribute::AccessToken);
    }

    /**
     * Speichert die Token und plant die nächste Erneuerung.
     *
     * @param  array $Result Ergebnis von DeviceFlow::ParseTokenResponse
     * @return void
     */
    private function StoreTokens(array $Result): void
    {
        $this->WriteAttributeString(\YouTubeMusic\Attribute::AccessToken, $Result['access_token']);
        $this->WriteAttributeInteger(\YouTubeMusic\Attribute::AccessExpiry, time() + $Result['expires_in']);
        if (isset($Result['refresh_token'])) {
            // Neue Anmeldung
            $this->WriteAttributeString(\YouTubeMusic\Attribute::RefreshToken, $Result['refresh_token']);
            $this->WriteAttributeBoolean(\YouTubeMusic\Attribute::SignInExpired, false);
        }
        $this->SendDebug('Token', 'access token ' . strlen($Result['access_token']) . ' chars, expires in ' . $Result['expires_in'] . ' s', 0);
        $this->SetTimerInterval(\YouTubeMusic\Timer::RefreshToken, max(60, $Result['expires_in'] - self::RefreshMargin) * 1000);
    }

    /**
     * Löscht alle Anmeldedaten.
     *
     * @return void
     */
    private function ClearTokens(): void
    {
        $this->WriteAttributeString(\YouTubeMusic\Attribute::RefreshToken, '');
        $this->WriteAttributeString(\YouTubeMusic\Attribute::AccessToken, '');
        $this->WriteAttributeInteger(\YouTubeMusic\Attribute::AccessExpiry, 0);
        $this->WriteAttributeString(\YouTubeMusic\Attribute::ChannelId, '');
        $this->WriteAttributeString(\YouTubeMusic\Attribute::ChannelName, '');
        $this->SetTimerInterval(\YouTubeMusic\Timer::RefreshToken, 0);
    }

    /**
     * Ist ein Refresh-Token vorhanden?
     *
     * @return bool
     */
    private function SignedIn(): bool
    {
        return $this->ReadAttributeString(\YouTubeMusic\Attribute::RefreshToken) !== '';
    }

    /**
     * Status und Timer nach Anmeldung, Abmeldung oder Übernehmen.
     *
     * @return void
     */
    private function ApplySignedIn(): void
    {
        // Ohne Anmeldung (oder ohne Client) bleibt der Account aktiv: Suche, Start, Neu, Lyrics und Metadaten gehen anonym.
        // Nur eine abgelaufene Anmeldung ist ein Fehler, bis neu angemeldet oder abgemeldet wird.
        $this->SetStatus($this->ReadAttributeBoolean(\YouTubeMusic\Attribute::SignInExpired) ? self::StatusSignInExpired : IS_ACTIVE);
        if (trim($this->ReadPropertyString(\YouTubeMusic\Property::ClientId)) === ''
            || trim($this->ReadPropertyString(\YouTubeMusic\Property::ClientSecret)) === ''
            || !$this->SignedIn()) {
            $this->SetTimerInterval(\YouTubeMusic\Timer::RefreshToken, 0);
            return;
        }
        $Remaining = $this->ReadAttributeInteger(\YouTubeMusic\Attribute::AccessExpiry) - time() - self::RefreshMargin;
        $this->SetTimerInterval(\YouTubeMusic\Timer::RefreshToken, max(60, $Remaining) * 1000);
    }

    /**
     * Buffer initialisieren.
     *
     * @return void
     */
    private function InitBuffer(): void
    {
        $this->DeviceCode = '';
        $this->DeviceInterval = 5;
        $this->DeviceExpires = 0;
        $this->MusicConfig = [];
        $this->DeviceStates = [];
        // Zähler überstehen Neustarts nur bis zum letzten Speichern (siehe HttpRequest)
        $Stats = json_decode($this->ReadAttributeString(\YouTubeMusic\Attribute::RequestStats), true);
        $this->RequestStats = is_array($Stats) ? $Stats : [];
    }
}
