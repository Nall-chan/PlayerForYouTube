<?php

declare(strict_types=1);

eval('declare(strict_types=1);namespace YouTubeMusicPlayer {?>' . file_get_contents(dirname(__DIR__) . '/libs/helper/DebugHelper.php') . '}');
eval('declare(strict_types=1);namespace YouTubeMusicPlayer {?>' . file_get_contents(dirname(__DIR__) . '/libs/helper/BufferHelper.php') . '}');
eval('declare(strict_types=1);namespace YouTubeMusicPlayer {?>' . file_get_contents(dirname(__DIR__) . '/libs/helper/SemaphoreHelper.php') . '}');
require_once dirname(__DIR__) . '/libs/Constants.php';
require_once dirname(__DIR__) . '/libs/GoogleOAuth.php';
require_once dirname(__DIR__) . '/libs/YouTubeLink.php';
require_once dirname(__DIR__) . '/libs/Lounge.php';
require_once dirname(__DIR__) . '/libs/RequestCounter.php';
require_once dirname(__DIR__) . '/libs/Variables.php';
require_once dirname(__DIR__) . '/libs/TileTexts.php';

/**
 * YouTubeMusicPlayer
 *
 * Ein Ausgabegerät für YouTube und YouTube Music: Chrome Cast (über die YouTube Lounge) oder die
 * Wiedergabe in der Kachel. Hängt an einer Instanz „YouTube Music Account“ (Anmeldung, Suche, Mediathek).
 *
 * @project       YouTubeMusic
 * @file          module.php
 * @package       YouTubeMusic
 * @author        Michael Tröger <nall4chan@gmail.com>
 * @copyright     2026 Michael Tröger
 * @license       Custom Non-Commercial Share-Alike License (see LICENSE)
 *
 * @property array $LoungeSession Sitzung der YouTube Lounge (siehe \YouTubeMusic\Lounge::NewSession)
 * @property array $LoungeState Wiedergabezustand des Geräts (siehe \YouTubeMusic\Lounge::EmptyState)
 * @property array $RequestStats Lounge-Anfragen des Tages (siehe \YouTubeMusic\RequestCounter)
 * @property array $Metadata Titel, Interpret, Album, Cover, Dauer, Like der letzten Titel (Schlüssel videoId)
 * @property string $CoverUrl Adresse des Bildes im Medienobjekt „Cover“
 * @property array $TileState Zustand des Players im Browser (Kachel-Gerät), mit playerId und updated
 * @property float $TileSeen Zeitpunkt der letzten Meldung irgendeines Browser-Players (Kachel-Gerät)
 * @property array $TileCommands Befehle an den Player im Browser zum Abholen über den WebHook (id, playerId, command, args, time)
 * @method bool SendDebug(string $Message, mixed $Data, int $Format)
 * @method bool lock(string $ident)
 * @method void unlock(string $ident)
 */
class YouTubeMusicPlayer extends IPSModuleStrict
{
    use \YouTubeMusicPlayer\DebugHelper;
    use \YouTubeMusicPlayer\BufferHelper;
    use \YouTubeMusicPlayer\Semaphore;

    /**
     * Name der Sperre für Sitzung und Zustand der Lounge.
     */
    private const LockLounge = 'Lounge';

    /**
     * Name der Sperre für die Anfragezähler.
     */
    private const LockStats = 'Stats';

    /**
     * Abfrageintervall des Geräts in Millisekunden und Dauer einer Abfrage.
     */
    private const LoungePollInterval = 2000;
    private const LoungePollDuration = 1500;

    /**
     * Position des Medienobjekts „Cover“ zwischen Album und Lautstärke.
     */
    private const CoverPosition = 50;

    /**
     * Anzahl gepufferter Metadaten (je videoId)
     */
    private const MetadataCacheSize = 20;

    /**
     * Ohne Meldung so lange (Sekunden) gilt ein Browser-Player als weg (Fenster geschlossen)
     */
    private const TilePlayerTimeout = 120;

    /**
     * Einträge je Regal in den Inhalten der Kachel (eine Seite des TV-Clients hat bis zu 200, z. B. „Titel, die ich mag“)
     */
    private const ShelfLimit = 200;

    /**
     * Höchstzahl Titel und Ladezeit (s), wenn eine Liste für den Browser-Player aufgelöst wird
     */
    private const PlaylistLimit = 100;
    private const PlaylistSeconds = 3;

    /**
     * Einmal-Timer (und RequestAction-Ident): Typ und Zustand beim Account melden
     */
    private const AnnounceTimer = 'Announce';

    /**
     * Einmal-Timer (und RequestAction-Ident): nach Übernehmen bzw. Start still neu mit der Lounge verbinden
     */
    private const ResumeTimer = 'ResumeLounge';

    /**
     * Formularfelder, die nur bei Ausgabe auf Chrome Cast gelten
     */
    private const CastFormFields = [\YouTubeMusic\Property::CastInstance, \YouTubeMusic\Property::PlayerName, \YouTubeMusic\Property::EnableVariables, 'VariablesHint'];

    /**
     * Formularfelder, die nur beim Kachel-Gerät (Wiedergabe im Browser) gelten
     */
    private const TileFormFields = [\YouTubeMusic\Property::VideoDisplay, \YouTubeMusic\Property::HiddenPlayback, 'HiddenHint'];

    /**
     * WebHook der Kachel-Laufzeit: /hook/youtubemusic-<InstanzID>
     */
    private const HookPrefix = 'youtubemusic-';

    /**
     * Status: Nicht mit einem YouTube Music Account verbunden.
     */
    private const StatusNoAccount = IS_EBASE + 1;

    /**
     * Status: Keine Chrome-Cast-Instanz gewählt.
     */
    private const StatusNoCast = IS_EBASE + 2;

    /**
     * User-Agent für die Lounge.
     */
    private const UserAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    /**
     * Flags für JSON, das in ein <script> eingebettet wird.
     */
    private const JsonScriptFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * Create
     *
     * @return void
     */
    public function Create(): void
    {
        //Never delete this line!
        parent::Create();
        $this->RegisterPropertyInteger(\YouTubeMusic\Property::DeviceType, \YouTubeMusic\DeviceType::ChromeCast);
        $this->RegisterPropertyInteger(\YouTubeMusic\Property::CastInstance, 0);
        // Leer: Name dieser Instanz
        $this->RegisterPropertyString(\YouTubeMusic\Property::PlayerName, '');
        $this->RegisterPropertyBoolean(\YouTubeMusic\Property::HiddenPlayback, false);
        $this->RegisterPropertyInteger(\YouTubeMusic\Property::VideoDisplay, \YouTubeMusic\VideoDisplay::Floating);
        $this->RegisterPropertyInteger(\YouTubeMusic\Property::EnableVariables, \YouTubeMusic\EnableVariables::Auto);
        $this->RegisterPropertyInteger(\YouTubeMusic\Property::ControlMode, \YouTubeMusic\ControlMode::All);
        $this->RegisterPropertyString(\YouTubeMusic\Property::ControlDevices, '[]');

        $this->RegisterAttributeString(\YouTubeMusic\Attribute::RequestStats, '[]');
        $this->RegisterAttributeString(\YouTubeMusic\Attribute::HookSecret, '');
        // Flüchtig, wird bei jedem Start in Create neu registriert und beim Löschen entfernt
        $this->RegisterHook(self::HookPrefix . $this->InstanceID);

        $this->RegisterTimer(\YouTubeMusic\Timer::LoungePoll, 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'' . \YouTubeMusic\TimerAction::LoungePoll . '\', true);');

        $this->InitBuffer();
        // Normale Kachel und Vollbild über das HTML-SDK gibt es erst ab Symcon 9.1
        $this->SetVisualizationType(defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') ? constant('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') : INSTANCE_VISUALIZATION_TYPE_HTML);
    }

    /**
     * ApplyChanges
     *
     * @return void
     */
    public function ApplyChanges(): void
    {
        $this->SetTimerInterval(\YouTubeMusic\Timer::LoungePoll, 0);
        //Never delete this line!
        parent::ApplyChanges();
        $this->InitBuffer();
        if ($this->ReadAttributeString(\YouTubeMusic\Attribute::HookSecret) === '') {
            $this->WriteAttributeString(\YouTubeMusic\Attribute::HookSecret, bin2hex(random_bytes(16)));
        }
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->ApplyVariables();
        $this->PushToTiles([\YouTubeMusic\TileMessage::Type => \YouTubeMusic\TileMessage::Reload]);
        if (!$this->HasAccount()) {
            $this->SetStatus(self::StatusNoAccount);
            return;
        }
        if ($this->IsCast() && $this->CastInstance() === 0) {
            $this->SetStatus(self::StatusNoCast);
            return;
        }
        $this->SetStatus(IS_ACTIVE);
        // Beim Account anmelden (Typ und Zustand für die Geräteauswahl der anderen Kacheln)
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->PushDeviceState();
            $this->ScheduleResume();
        }
    }

    /**
     * MessageSink
     *
     * Nach dem Start von Symcon beim Account anmelden (in ApplyChanges ist der Kernel noch nicht bereit).
     *
     * @param  int $TimeStamp
     * @param  int $SenderID
     * @param  int $Message
     * @param  array $Data
     * @return void
     */
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED && $this->HasAccount()) {
            $this->PushDeviceState();
            $this->ScheduleResume();
        }
    }

    /**
     * GetConfigurationForm
     *
     * @return string
     */
    public function GetConfigurationForm(): string
    {
        $Form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $Cast = $this->IsCast();
        foreach ($Form['elements'] as &$Element) {
            if (in_array($Element['name'] ?? '', self::CastFormFields, true)) {
                $Element['visible'] = $Cast;
            }
            if (in_array($Element['name'] ?? '', self::TileFormFields, true)) {
                $Element['visible'] = !$Cast;
            }
            if (($Element['name'] ?? '') === \YouTubeMusic\Property::ControlDevices) {
                $Element['visible'] = $this->ReadPropertyInteger(\YouTubeMusic\Property::ControlMode) === \YouTubeMusic\ControlMode::Selected;
            }
            // Ältere Instanzen: Wert 1 (An) zeigt die Auswahl wie 0 (An)
            if (($Element['name'] ?? '') === \YouTubeMusic\Property::EnableVariables
                && $this->ReadPropertyInteger(\YouTubeMusic\Property::EnableVariables) === \YouTubeMusic\EnableVariables::On) {
                $Element['value'] = \YouTubeMusic\EnableVariables::Auto;
            }
        }
        unset($Element);
        foreach ($Form['actions'] as &$Action) {
            if (($Action['name'] ?? '') === 'Requests') {
                $Action['visible'] = $Cast;
                $Stats = $this->GetRequestStatistics();
                $Action['caption'] = sprintf($this->Translate('Lounge requests today (%s, Pacific Time)'), $Stats['day']);
                $Action['items'][0]['values'] = $this->RequestRows($Stats);
            }
        }
        unset($Action);
        return json_encode($Form);
    }

    /**
     * GetVisualizationTile
     *
     * Liefert die Kachel mit eingebetteter Konfiguration.
     *
     * @return string
     */
    public function GetVisualizationTile(): string
    {
        $State = $this->DeviceState();
        $Host = (string) file_get_contents(__DIR__ . '/assets/host.js');
        $Config = [
            'instance'       => $this->InstanceID,
            // Nach einem Update ersetzt die Kachel eine ältere Laufzeit im Hauptfenster
            'hostVersion'    => md5($Host),
            'deviceType'     => $this->ReadPropertyInteger(\YouTubeMusic\Property::DeviceType),
            'hiddenPlayback' => $this->ReadPropertyBoolean(\YouTubeMusic\Property::HiddenPlayback),
            'videoInTile'    => $this->ReadPropertyInteger(\YouTubeMusic\Property::VideoDisplay) === \YouTubeMusic\VideoDisplay::Tile,
            // Nur zur Anzeige in der Kachel
            'playerName'     => IPS_GetName($this->InstanceID),
            // Für die Player-Laufzeit im Hauptfenster, solange keine Kachel eingehängt ist
            'hook'           => '/hook/' . self::HookPrefix . $this->InstanceID,
            'secret'         => $this->ReadAttributeString(\YouTubeMusic\Attribute::HookSecret),
            'state'          => $State,
            'meta'           => $this->CachedMetadata($State['videoId']),
            'texts'          => $this->TileTexts(),
            // Geräteauswahl; Zustände mit Zeitstempel des Servers (Position hochrechnen)
            'devices'        => $this->ControllableDevices(),
            'serverTime'     => microtime(true),
        ];
        return strtr((string) file_get_contents(__DIR__ . '/module.html'), [
            '/*CONFIG*/null' => json_encode($Config, self::JsonScriptFlags),
            '/*STYLE*/'      => (string) file_get_contents(__DIR__ . '/assets/tile.css'),
            '/*HOST*/'       => $Host,
            '/*VIEWS*/'      => (string) file_get_contents(__DIR__ . '/assets/views.js'),
            '/*CORE*/'       => (string) file_get_contents(__DIR__ . '/assets/core.js'),
        ]);
    }

    /**
     * RequestAction
     *
     * Aufrufe von Timern und der Kachel. RequestAction ist öffentlich, jede Eingabe wird geprüft.
     *
     * @param  string $Ident
     * @param  mixed $Value
     * @return void
     */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case \YouTubeMusic\TimerAction::LoungePoll:
                $this->PollLounge();
                return;
            case \YouTubeMusic\Variable::Playback:
                match ((int) $Value) {
                    \YouTubeMusic\Playback::Previous => $this->Previous(),
                    \YouTubeMusic\Playback::Stop     => $this->Stop(),
                    \YouTubeMusic\Playback::Pause    => $this->Pause(),
                    \YouTubeMusic\Playback::Play     => $this->Play(),
                    \YouTubeMusic\Playback::Next     => $this->Next(),
                    default                          => throw new InvalidArgumentException('Invalid value'),
                };
                return;
            case \YouTubeMusic\Variable::Volume:
                $this->SetVolume((int) $Value);
                return;
            case 'FormDeviceType':
                // Formular: Felder für Chrome Cast nur bei dieser Ausgabe zeigen
                $Cast = (int) $Value === \YouTubeMusic\DeviceType::ChromeCast;
                foreach (self::CastFormFields as $Field) {
                    $this->UpdateFormField($Field, 'visible', $Cast);
                }
                foreach (self::TileFormFields as $Field) {
                    $this->UpdateFormField($Field, 'visible', !$Cast);
                }
                $this->UpdateFormField('Requests', 'visible', $Cast);
                return;
            case self::AnnounceTimer:
                $this->PushDeviceState();
                return;
            case self::ResumeTimer:
                $this->ResumeLounge();
                return;
            case 'FormControlMode':
                $this->UpdateFormField(\YouTubeMusic\Property::ControlDevices, 'visible', (int) $Value === \YouTubeMusic\ControlMode::Selected);
                return;
            case \YouTubeMusic\TileAction::Query:
                $this->HandleQuery((string) $Value);
                return;
            case \YouTubeMusic\TileAction::Devices:
                $this->PushToTiles([\YouTubeMusic\TileMessage::Type => \YouTubeMusic\TileMessage::Devices, 'devices' => $this->ControllableDevices(), 'serverTime' => microtime(true)]);
                return;
            case \YouTubeMusic\TileAction::Debug:
                $this->SendDebug('Tile', mb_substr((string) $Value, 0, 1000), 0);
                return;
            case \YouTubeMusic\TileAction::TileState:
                $this->HandleTileState((string) $Value);
                return;
            case \YouTubeMusic\TileAction::Rating:
                $Data = json_decode((string) $Value, true);
                $this->SetRating((string) ($Data['videoId'] ?? ''), (string) ($Data['rating'] ?? ''));
                return;
            case \YouTubeMusic\TileAction::Metadata:
                $this->PushMetadata((string) $Value);
                return;
            case \YouTubeMusic\TileAction::ResolveList:
                $Data = json_decode((string) $Value, true);
                $ListId = (string) ($Data['listId'] ?? '');
                $VideoId = (string) ($Data['videoId'] ?? '');
                $Request = (string) ($Data['request'] ?? '');
                if (preg_match('/^[A-Za-z0-9_-]{2,80}$/D', $ListId) !== 1 || ($VideoId !== '' && !\YouTubeMusic\YouTubeLink::IsVideoId($VideoId))
                    || preg_match('/^[a-z0-9]{1,32}$/D', $Request) !== 1) {
                    throw new InvalidArgumentException('Invalid list');
                }
                // Die anfragende Kachel erkennt ihre Antwort an der Anfrage-ID
                $this->PushToTiles([\YouTubeMusic\TileMessage::Type => \YouTubeMusic\TileMessage::Playlist, 'request' => $Request, 'listId' => $ListId]
                    + $this->PlaylistVideos($ListId, $VideoId));
                return;
            case \YouTubeMusic\TileAction::Command:
                $Data = json_decode((string) $Value, true);
                if (!is_array($Data)) {
                    throw new InvalidArgumentException('Invalid command');
                }
                $this->ExecuteCommand((int) ($Data['target'] ?? 0), (string) ($Data['command'] ?? ''), is_array($Data['args'] ?? null) ? $Data['args'] : []);
                return;
        }
        throw new InvalidArgumentException('Invalid Ident');
    }

    /**
     * ReceiveData
     *
     * Nachrichten des Accounts an die Geräte: Zustand der anderen Geräte geht an die eigenen Kacheln.
     *
     * @param  string $JSONString
     * @return string
     */
    public function ReceiveData(string $JSONString): string
    {
        $Data = json_decode($JSONString, true);
        if (($Data[\YouTubeMusic\Flow::Type] ?? '') === \YouTubeMusic\DeviceMessage::Announce) {
            // Nicht direkt antworten: der Account ist gerade in ApplyChanges und würde auf sich selbst warten
            $this->RegisterOnceTimer(self::AnnounceTimer, 'IPS_RequestAction($_IPS[\'TARGET\'], \'' . self::AnnounceTimer . '\', true);');
            return '';
        }
        if (($Data[\YouTubeMusic\Flow::Type] ?? '') === \YouTubeMusic\DeviceMessage::State) {
            $Source = (int) ($Data[\YouTubeMusic\Flow::Source] ?? 0);
            // Nur Geräte, die diese Kachel steuern darf
            $SourceState = is_array($Data[\YouTubeMusic\Flow::Data] ?? null) ? $Data[\YouTubeMusic\Flow::Data] : [];
            // Kachel-Geräte anderer Instanzen sind keine Ziele (sie stehen für „Wiedergabe in diesem Browser“)
            if ($Source !== $this->InstanceID && ($SourceState['type'] ?? -1) !== \YouTubeMusic\DeviceType::Tile
                && in_array($Source, $this->AllowedTargets(), true)) {
                $this->PushToTiles([
                    \YouTubeMusic\TileMessage::Type => \YouTubeMusic\TileMessage::RemoteState,
                    'device'                        => $Source,
                    'state'                         => is_array($Data[\YouTubeMusic\Flow::Data] ?? null) ? $Data[\YouTubeMusic\Flow::Data] : [],
                ]);
            }
        }
        return '';
    }

    /**
     * Geräte am selben Account mit ihrem zuletzt gemeldeten Zustand (siehe YTM_GetDevices des Accounts).
     *
     * @return array
     */
    public function GetDevices(): array
    {
        $Devices = $this->SendToAccount(\YouTubeMusic\AccountFunction::Devices, []);
        return is_array($Devices) ? $Devices : [];
    }

    /**
     * Spielt einen Titel bzw. eine Liste ab einer Position ab.
     *
     * @param  string $VideoId Leer: Liste ab dem ersten Eintrag
     * @param  string $PlaylistId Leer: nur der Titel
     * @param  float $Seconds Startposition im Titel
     * @return bool
     */
    public function PlayVideoAt(string $VideoId, string $PlaylistId, float $Seconds): bool
    {
        if ($Seconds < 0) {
            echo $this->Translate('Invalid position') . PHP_EOL;
            return false;
        }
        return $this->StartPlaylist($VideoId, $PlaylistId, $Seconds);
    }

    /**
     * Übergibt die laufende Wiedergabe (Titel, Liste, Position) an ein anderes Gerät am selben Account
     * und beendet sie auf diesem Gerät (Chrome Cast meldet sich ab, Kachel pausiert).
     *
     * @param  int $TargetID Instanz-ID des Ziel-Players
     * @return bool
     */
    public function TransferTo(int $TargetID): bool
    {
        if ($TargetID === $this->InstanceID || !IPS_InstanceExists($TargetID)
            || IPS_GetInstance($TargetID)['ModuleInfo']['ModuleID'] !== \YouTubeMusic\GUID::Player
            || IPS_GetInstance($TargetID)['ConnectionID'] !== IPS_GetInstance($this->InstanceID)['ConnectionID']) {
            echo $this->Translate('Target is not another player of the same YouTube Music Account') . PHP_EOL;
            return false;
        }
        $State = $this->DeviceState();
        if ($State['videoId'] === '') {
            echo $this->Translate('Nothing is playing on this device') . PHP_EOL;
            return false;
        }
        if (!YTM_PlayVideoAt($TargetID, $State['videoId'], $State['listId'], $State['position'])) {
            return false;
        }
        ob_start();
        $this->Stop();
        ob_end_clean();
        return true;
    }

    /**
     * Spielt einen Link von YouTube oder YouTube Music ab (watch?v=…&list=…, playlist?list=…, youtu.be/…, IDs).
     *
     * @param  string $URL
     * @return bool
     */
    public function PlayURL(string $URL): bool
    {
        $Link = \YouTubeMusic\YouTubeLink::Parse($URL);
        if ($Link === null || ($Link['videoId'] === '' && $Link['listId'] === '' && !str_starts_with($Link['browseId'], 'VL'))) {
            echo $this->Translate('Not a playable YouTube link') . PHP_EOL;
            return false;
        }
        $ListId = $Link['listId'] !== '' ? $Link['listId'] : (str_starts_with($Link['browseId'], 'VL') ? substr($Link['browseId'], 2) : '');
        return $this->PlayVideo($Link['videoId'], $ListId);
    }

    /**
     * Spielt einen Titel bzw. eine Liste ab.
     *
     * @param  string $VideoId Leer: Liste ab dem ersten Eintrag
     * @param  string $PlaylistId Leer: nur der Titel (danach Vorschläge des Dienstes)
     * @return bool
     */
    public function PlayVideo(string $VideoId, string $PlaylistId): bool
    {
        return $this->StartPlaylist($VideoId, $PlaylistId, 0);
    }

    /**
     * Setzt die Wiedergabe fort.
     *
     * @return bool
     */
    public function Play(): bool
    {
        return $this->LoungeCommand(\YouTubeMusic\LoungeCommand::Play);
    }

    /**
     * Pausiert die Wiedergabe.
     *
     * @return bool
     */
    public function Pause(): bool
    {
        return $this->LoungeCommand(\YouTubeMusic\LoungeCommand::Pause);
    }

    /**
     * Beendet die Wiedergabe. Chrome Cast: Titel stoppen und die Fernbedienung vom Gerät abmelden, damit das Gerät
     * frei ist (sonst bleibt die App mit „verbunden“ in der Pause stehen). Kachel: Pause in allen Browsern.
     *
     * @return bool
     */
    public function Stop(): bool
    {
        if (!$this->IsCast()) {
            return $this->TileCommand(\YouTubeMusic\LoungeCommand::Pause, []);
        }
        $Session = is_array($this->LoungeSession) ? $this->LoungeSession : [];
        if (($Session['sid'] ?? '') === '') {
            return true;
        }
        $Result = $this->LoungeCommand(\YouTubeMusic\LoungeCommand::StopVideo);
        $this->LeaveLounge();
        return $Result;
    }

    /**
     * Nächster Titel.
     *
     * @return bool
     */
    public function Next(): bool
    {
        return $this->LoungeCommand(\YouTubeMusic\LoungeCommand::Next);
    }

    /**
     * Vorheriger Titel.
     *
     * @return bool
     */
    public function Previous(): bool
    {
        return $this->LoungeCommand(\YouTubeMusic\LoungeCommand::Previous);
    }

    /**
     * Springt im laufenden Titel.
     *
     * @param  float $Seconds Position in Sekunden ab Titelanfang
     * @return bool
     */
    public function Seek(float $Seconds): bool
    {
        if ($Seconds < 0) {
            echo $this->Translate('Invalid position') . PHP_EOL;
            return false;
        }
        return $this->LoungeCommand(\YouTubeMusic\LoungeCommand::SeekTo, ['newTime' => round($Seconds, 1)]);
    }

    /**
     * Setzt die Lautstärke der YouTube-App auf dem Gerät.
     *
     * @param  int $Volume 0 bis 100
     * @return bool
     */
    public function SetVolume(int $Volume): bool
    {
        if ($Volume < 0 || $Volume > 100) {
            echo $this->Translate('Volume must be between 0 and 100') . PHP_EOL;
            return false;
        }
        return $this->LoungeCommand(\YouTubeMusic\LoungeCommand::SetVolume, ['volume' => $Volume]);
    }

    /**
     * Schaltet die Wiedergabe stumm bzw. wieder laut (Lautstärke bleibt erhalten).
     *
     * @param  bool $Mute
     * @return bool
     */
    public function SetMute(bool $Mute): bool
    {
        if (!$this->IsCast()) {
            return $this->TileCommand($Mute ? \YouTubeMusic\LoungeCommand::Mute : \YouTubeMusic\LoungeCommand::UnMute, []);
        }
        // Lounge nimmt „mute“ an, die App reagiert aber nicht (live an „Büro“); deshalb am Cast-Gerät selbst
        $Cast = $this->CastInstance();
        if ($Cast === 0) {
            echo $this->Translate('No Chrome Cast instance selected') . PHP_EOL;
            return false;
        }
        if (!CCAST_SetMute($Cast, $Mute)) {
            return false;
        }
        // Die Lounge meldet das nicht, also selbst vermerken
        $this->lock(self::LockLounge);
        $State = is_array($this->LoungeState) ? $this->LoungeState : [];
        $State['muted'] = $Mute;
        $this->LoungeState = $State;
        $this->unlock(self::LockLounge);
        $this->PushDeviceState();
        return true;
    }

    /**
     * Zustand des Geräts (aus den Lounge-Ereignissen; Position hochgerechnet).
     *
     * Beim Kachel-Gerät spielt jede Visu für sich, einen gemeinsamen Zustand gibt es nicht.
     *
     * @return array|false videoId, listId, state (-1 unbekannt, 0 beendet, 1 spielt, 2 Pause, 3 puffert, 5 geladen),
     *                     position, duration, volume, muted, hasPrevious, hasNext, shuffle, loop, connected
     */
    public function GetDeviceState(): array|false
    {
        if (!$this->IsCast()) {
            echo $this->Translate('The tile player has no device state: every visualization plays on its own.') . PHP_EOL;
            return false;
        }
        return $this->DeviceState();
    }

    /**
     * Lounge-Anfragen des laufenden Tages (Tageswechsel um 0 Uhr Pacific Time).
     *
     * @return array day, requests und errors je Kategorie (loungeToken, loungeCommand, loungePoll), previous (Vortag)
     */
    public function GetRequestStatistics(): array
    {
        $Stats = is_array($this->RequestStats) ? $this->RequestStats : [];
        $Today = \YouTubeMusic\RequestCounter::Day(time());
        if (($Stats['day'] ?? '') !== $Today) {
            $Previous = isset($Stats['day']) ? array_diff_key($Stats, ['previous' => 0]) : null;
            $Stats = \YouTubeMusic\RequestCounter::Empty($Today) + ($Previous !== null ? ['previous' => $Previous] : []);
        }
        return $Stats;
    }

    /**
     * Sucht in YouTube Music über den verbundenen Account (siehe YTM_Search des Accounts).
     *
     * @param  string $Query
     * @param  string $Type song, video, album, artist, playlist oder leer für alle
     * @return array|false
     */
    public function Search(string $Query, string $Type): array|false
    {
        return $this->SendToAccount(\YouTubeMusic\AccountFunction::Search, ['Query' => $Query, 'Type' => $Type]);
    }

    /**
     * Lädt eine Seite über den verbundenen Account (siehe YTM_Browse des Accounts).
     *
     * @param  string $BrowseId
     * @param  string $Params
     * @return array|false
     */
    public function Browse(string $BrowseId, string $Params): array|false
    {
        return $this->SendToAccount(\YouTubeMusic\AccountFunction::Browse, ['BrowseId' => $BrowseId, 'Params' => $Params]);
    }

    /**
     * Nächste Seite über den verbundenen Account (siehe YTM_BrowseMore des Accounts).
     *
     * @param  string $Token
     * @param  string $Client
     * @return array|false
     */
    public function BrowseMore(string $Token, string $Client): array|false
    {
        return $this->SendToAccount(\YouTubeMusic\AccountFunction::BrowseMore, ['Token' => $Token, 'Client' => $Client]);
    }

    /**
     * Warteschlange über den verbundenen Account (siehe YTM_GetQueue des Accounts).
     *
     * @param  string $VideoId
     * @param  string $PlaylistId
     * @return array|false
     */
    public function GetQueue(string $VideoId, string $PlaylistId): array|false
    {
        return $this->SendToAccount(\YouTubeMusic\AccountFunction::GetQueue, ['VideoId' => $VideoId, 'PlaylistId' => $PlaylistId]);
    }

    /**
     * Bewertet einen Titel über den verbundenen Account (siehe YTM_SetRating des Accounts) und aktualisiert die Kacheln.
     *
     * @param  string $VideoId
     * @param  string $Rating like, dislike oder none
     * @return bool
     */
    public function SetRating(string $VideoId, string $Rating): bool
    {
        if (!$this->SendToAccount(\YouTubeMusic\AccountFunction::SetRating, ['VideoId' => $VideoId, 'Rating' => $Rating])) {
            return false;
        }
        $Cache = is_array($this->Metadata) ? $this->Metadata : [];
        if (is_array($Cache[$VideoId] ?? null)) {
            $Cache[$VideoId]['like'] = ['like' => 'LIKE', 'dislike' => 'DISLIKE'][$Rating] ?? 'INDIFFERENT';
            $this->Metadata = $Cache;
            $this->PushMetadata($VideoId);
            $this->PushDeviceState();
        }
        return true;
    }

    /**
     * Liedtext über den verbundenen Account (siehe YTM_GetLyrics des Accounts).
     *
     * @param  string $VideoId
     * @return array|false
     */
    public function GetLyrics(string $VideoId): array|false
    {
        return $this->SendToAccount(\YouTubeMusic\AccountFunction::GetLyrics, ['VideoId' => $VideoId]);
    }

    /**
     * WebHook der Player-Laufzeit im Hauptfenster der Visu (meldet bei versteckter Kachel und holt Befehle ab).
     *
     * POST-Felder: secret, type (state|poll), data (JSON-Zustand bei state), after (letzte ausgeführte Befehls-ID).
     *
     * @return void
     */
    protected function ProcessHookData(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $Secret = is_string($_POST['secret'] ?? null) ? $_POST['secret'] : '';
        if ($Secret === '' || !hash_equals($this->ReadAttributeString(\YouTubeMusic\Attribute::HookSecret), $Secret)) {
            http_response_code(403);
            echo '{"error":"denied"}';
            return;
        }
        // Instanz auf Chrome Cast umgestellt: Browser-Player beendet sich
        if ($this->IsCast()) {
            echo '{"release":true}';
            return;
        }
        if (($_POST['type'] ?? '') === 'state' && is_string($_POST['data'] ?? null)) {
            ob_start();
            try {
                $this->HandleTileState($_POST['data']);
            } catch (\Throwable $e) {
                $this->SendDebug(__FUNCTION__, 'invalid state: ' . $e->getMessage(), 0);
            }
            ob_end_clean();
        }
        // Nächstes Fenster einer langen Liste; Antwort direkt (auch ohne eingehängte Kachel)
        if (($_POST['type'] ?? '') === 'more' && is_string($_POST['data'] ?? null)) {
            $More = json_decode($_POST['data'], true);
            ob_start();
            $Next = is_array($More) ? $this->ContinueVideos($More) : ['videoIds' => []];
            ob_end_clean();
            echo json_encode($Next);
            return;
        }
        $After = (int) ($_POST['after'] ?? 0);
        $Commands = array_values(array_filter(is_array($this->TileCommands) ? $this->TileCommands : [], static fn (array $Command): bool => $Command['id'] > $After));
        echo json_encode(['commands' => $Commands]);
    }

    /**
     * Zustand des Geräts; beim Kachel-Gerät der des Browsers, der zuletzt die Wiedergabe gestartet hat
     * (für Übergabe, Kacheln anderer Geräte und Debug).
     *
     * @return array siehe GetDeviceState
     */
    private function DeviceState(): array
    {
        if (!$this->IsCast()) {
            return $this->TileDeviceState();
        }
        $State = (is_array($this->LoungeState) ? $this->LoungeState : []) + \YouTubeMusic\Lounge::EmptyState();
        $Session = is_array($this->LoungeSession) ? $this->LoungeSession : [];
        return [
            'videoId'     => $State['videoId'],
            'listId'      => $State['listId'],
            'state'       => $State['state'],
            'position'    => round(\YouTubeMusic\Lounge::Position($State, microtime(true)), 1),
            'duration'    => $State['duration'],
            'volume'      => $State['volume'],
            'muted'       => $State['muted'],
            'hasPrevious' => $State['hasPrevious'],
            'hasNext'     => $State['hasNext'],
            'shuffle'     => $State['shuffle'],
            'loop'        => $State['loop'],
            'connected'   => ($Session['sid'] ?? '') !== '' && $State['screenConnected'],
        ];
    }

    /**
     * Startet Titel bzw. Liste (setPlaylist), optional ab einer Position.
     *
     * @param  string $VideoId
     * @param  string $PlaylistId
     * @param  float $Seconds
     * @return bool
     */
    private function StartPlaylist(string $VideoId, string $PlaylistId, float $Seconds): bool
    {
        if (($VideoId === '' && $PlaylistId === '') || ($VideoId !== '' && !\YouTubeMusic\YouTubeLink::IsVideoId($VideoId))
            || ($PlaylistId !== '' && preg_match('/^[A-Za-z0-9_-]{2,80}$/D', $PlaylistId) !== 1)) {
            echo $this->Translate('Invalid video or playlist ID') . PHP_EOL;
            return false;
        }
        $Arguments = [];
        if ($VideoId !== '') {
            $Arguments['videoId'] = $VideoId;
        }
        if ($PlaylistId !== '') {
            $Arguments['listId'] = $PlaylistId;
        }
        if ($Seconds > 0) {
            $Arguments['currentTime'] = round($Seconds, 1);
        }
        if (!$this->IsCast() && $PlaylistId !== '') {
            $Arguments += $this->PlaylistVideos($PlaylistId, $VideoId);
        }
        $Loaded = (is_array($this->LoungeState) ? $this->LoungeState : [])['videoId'] ?? '';
        if (!$this->LoungeCommand(\YouTubeMusic\LoungeCommand::SetPlaylist, $Arguments)) {
            return false;
        }
        // Ist der Titel am Gerät schon geladen, setzt die Lounge an der alten Stelle fort und übergeht currentTime (live an „Büro“)
        if ($this->IsCast() && $Seconds > 0 && $VideoId !== '' && $Loaded === $VideoId) {
            usleep(800000);
            return $this->LoungeCommand(\YouTubeMusic\LoungeCommand::SeekTo, ['newTime' => round($Seconds, 1)]);
        }
        return true;
    }

    /**
     * Titel einer Liste für den Browser-Player.
     *
     * Der IFrame-Player lädt persönliche Listen (LR…, LM, Supermixe) nicht und startet Listen immer beim ersten Eintrag.
     * Deshalb bekommt er die Titel als Liste. Auch das Radio eines Titels (RD<videoId>, RDAMVM<videoId>): Daraus bildet der
     * IFrame-Player einen eigenen Mix, der nicht mit dem gewählten Titel beginnt (Nutzer, 2026-10-29); die Warteschlange von
     * YouTube Music (next) beginnt mit ihm.
     *
     * Lange Listen bekommt der Player als wanderndes Fenster: `more` sagt, wo es weitergeht (siehe ContinueVideos).
     *
     * @param  string $PlaylistId
     * @param  string $VideoId Starttitel (optional)
     * @return array{videoIds?: string[], index?: int, more?: array} leer, wenn die Liste nicht aufgelöst werden konnte
     */
    private function PlaylistVideos(string $PlaylistId, string $VideoId): array
    {
        if (!$this->HasAccount()) {
            return [];
        }
        // Inoffizielle Schnittstelle: Fehler nur ins Debug, der Player versucht die Liste dann selbst
        if (preg_match('/^RD(?:AMVM)?([A-Za-z0-9_-]{11})$/D', $PlaylistId, $Match) === 1) {
            $VideoId = $VideoId !== '' ? $VideoId : $Match[1];
            [$VideoIds, $More] = $this->RadioVideos($PlaylistId, $VideoId);
        } else {
            ob_start();
            // Album-ID (MPREb_…) ist keine Playlist: Albumseite direkt
            $BrowseId = str_starts_with($PlaylistId, 'MPRE') ? $PlaylistId : 'VL' . $PlaylistId;
            $Result = $this->SendToAccount(\YouTubeMusic\AccountFunction::Browse, ['BrowseId' => $BrowseId, 'Params' => '']);
            ob_end_clean();
            [$VideoIds, $More] = $this->CollectVideos($Result);
            // Mixe ohne eigene Seite (z. B. Künstler-Mix RDEM… aus den Abos) liefert nur die Warteschlange
            if ($VideoIds === [] && str_starts_with($PlaylistId, 'RD')) {
                [$VideoIds, $More] = $this->RadioVideos($PlaylistId, $VideoId);
            }
        }
        if ($VideoIds === []) {
            $this->SendDebug(__FUNCTION__, 'no videos for ' . $PlaylistId, 0);
            return [];
        }
        $Index = $VideoId !== '' ? array_search($VideoId, $VideoIds, true) : 0;
        if ($Index === false) {
            array_unshift($VideoIds, $VideoId);
            $Index = 0;
        }
        $this->SendDebug(__FUNCTION__, $PlaylistId . ': ' . count($VideoIds) . ' videos, index ' . $Index . ($More !== null ? ', more' : ''), 0);
        return ['videoIds' => $VideoIds, 'index' => $Index] + ($More !== null ? ['more' => $More] : []);
    }

    /**
     * Nächstes Fenster einer Liste für den Browser-Player (Anfrage über den WebHook, wenn das Fenster zu Ende ist).
     *
     * @param  array $More aus PlaylistVideos bzw. dem vorigen Fenster: {token, client} oder {radio, last}
     * @return array{videoIds: string[], more?: array}
     */
    private function ContinueVideos(array $More): array
    {
        if (!$this->HasAccount()) {
            return ['videoIds' => []];
        }
        $Radio = (string) ($More['radio'] ?? '');
        $Last = (string) ($More['last'] ?? '');
        if ($Radio !== '' && preg_match('/^RD[A-Za-z0-9_-]{2,78}$/D', $Radio) === 1 && \YouTubeMusic\YouTubeLink::IsVideoId($Last)) {
            [$VideoIds, $Next] = $this->RadioVideos($Radio, $Last);
            // Die Warteschlange beginnt mit dem letzten Titel des vorigen Fensters
            $VideoIds = array_values(array_filter($VideoIds, static fn (string $Id): bool => $Id !== $Last));
        } else {
            $Token = (string) ($More['token'] ?? '');
            $Client = (string) ($More['client'] ?? '');
            if ($Token === '' || strlen($Token) > 2000 || preg_match('/^[a-z]{0,10}$/D', $Client) !== 1) {
                return ['videoIds' => []];
            }
            ob_start();
            $Result = $this->SendToAccount(\YouTubeMusic\AccountFunction::BrowseMore, ['Token' => $Token, 'Client' => $Client]);
            ob_end_clean();
            [$VideoIds, $Next] = $this->CollectVideos($Result);
        }
        $this->SendDebug(__FUNCTION__, count($VideoIds) . ' videos' . ($Next !== null ? ', more' : ''), 0);
        return ['videoIds' => $VideoIds] + ($Next !== null && $VideoIds !== [] ? ['more' => $Next] : []);
    }

    /**
     * Titel aus einer Seite und weiteren Seiten (TV-Client: 15 Titel je Seite), begrenzt auf Anzahl und Dauer.
     * Die letzte Seite wird nicht abgeschnitten, damit das nächste Fenster nahtlos anschließt.
     *
     * @param  mixed $Result Ergebnis von Browse/BrowseMore oder false
     * @return array{0: string[], 1: ?array} Titel und Fortsetzung {token, client} (null: Liste zu Ende)
     */
    private function CollectVideos(mixed $Result): array
    {
        $VideoIds = [];
        $Started = microtime(true);
        while (is_array($Result)) {
            $Before = count($VideoIds);
            foreach ($Result['shelves'] ?? [] as $Shelf) {
                foreach ($Shelf['items'] ?? [] as $Item) {
                    $Id = (string) ($Item['videoId'] ?? '');
                    if (\YouTubeMusic\YouTubeLink::IsVideoId($Id) && !in_array($Id, $VideoIds, true)) {
                        $VideoIds[] = $Id;
                    }
                }
            }
            $More = ['token' => (string) ($Result['continuation'] ?? ''), 'client' => (string) ($Result['client'] ?? '')];
            // Seite ohne neuen Titel: Ende (eine Fortsetzung, die sich wiederholt, liefe sonst endlos)
            if ($More['token'] === '' || count($VideoIds) === $Before) {
                return [$VideoIds, null];
            }
            if (count($VideoIds) >= self::PlaylistLimit || microtime(true) - $Started > self::PlaylistSeconds) {
                return [$VideoIds, $More];
            }
            ob_start();
            $Result = $this->SendToAccount(\YouTubeMusic\AccountFunction::BrowseMore, ['Token' => $More['token'], 'Client' => $More['client']]);
            ob_end_clean();
        }
        return [$VideoIds, null];
    }

    /**
     * Radio zu einem Titel über die Warteschlange (beginnt mit dem Titel); weiter geht es mit dem letzten Titel.
     *
     * @param  string $PlaylistId RD<videoId> bzw. RDAMVM<videoId>
     * @param  string $VideoId Starttitel
     * @return array{0: string[], 1: ?array} Titel und Fortsetzung {radio, last}
     */
    private function RadioVideos(string $PlaylistId, string $VideoId): array
    {
        ob_start();
        $Queue = $this->SendToAccount(\YouTubeMusic\AccountFunction::GetQueue, ['VideoId' => $VideoId, 'PlaylistId' => $PlaylistId]);
        ob_end_clean();
        $VideoIds = [];
        foreach (is_array($Queue) ? $Queue['items'] ?? [] : [] as $Item) {
            $Id = (string) ($Item['videoId'] ?? '');
            if (\YouTubeMusic\YouTubeLink::IsVideoId($Id) && !in_array($Id, $VideoIds, true)) {
                $VideoIds[] = $Id;
            }
        }
        return [$VideoIds, $VideoIds !== [] ? ['radio' => $PlaylistId, 'last' => end($VideoIds)] : null];
    }

    /**
     * Anfrage an den Account (ForwardData). Die Fehlermeldung des Accounts gibt das Gerät an seinen Aufrufer weiter.
     *
     * @param  string $Function aus \YouTubeMusic\AccountFunction
     * @param  array $Params
     * @return mixed Ergebnis oder false
     */
    private function SendToAccount(string $Function, array $Params): mixed
    {
        if (!$this->HasAccount()) {
            echo $this->Translate('Not connected to a YouTube Music Account') . PHP_EOL;
            return false;
        }
        $Result = $this->SendDataToParent(json_encode([
            \YouTubeMusic\Flow::DataID   => \YouTubeMusic\GUID::ToAccount,
            \YouTubeMusic\Flow::Function => $Function,
            \YouTubeMusic\Flow::Source   => $this->InstanceID,
            \YouTubeMusic\Flow::Params   => $Params,
        ]));
        $Answer = is_string($Result) && $Result !== '' ? unserialize($Result, ['allowed_classes' => false]) : false;
        if (!is_array($Answer) || !array_key_exists(\YouTubeMusic\Flow::Result, $Answer)) {
            echo $this->Translate('No answer from the YouTube Music Account') . PHP_EOL;
            return false;
        }
        if (($Answer[\YouTubeMusic\Flow::Error] ?? '') !== '') {
            echo $Answer[\YouTubeMusic\Flow::Error] . PHP_EOL;
        }
        return $Answer[\YouTubeMusic\Flow::Result];
    }

    /**
     * Sollen Statusvariablen angelegt sein?
     *
     * @return bool
     */
    private function VariablesEnabled(): bool
    {
        // Kachel-Gerät: jede Visu spielt etwas anderes, Statusvariablen hätten keinen eindeutigen Inhalt
        if (!$this->IsCast()) {
            return false;
        }
        return $this->ReadPropertyInteger(\YouTubeMusic\Property::EnableVariables) !== \YouTubeMusic\EnableVariables::Off;
    }

    /**
     * Legt die Statusvariablen an bzw. entfernt sie (Hinweis dazu steht im Formular).
     *
     * @return void
     */
    private function ApplyVariables(): void
    {
        $Enabled = $this->VariablesEnabled();
        foreach (\YouTubeMusic\VariablePresentation::Definitions() as $Definition) {
            if (!$Enabled) {
                $this->UnregisterVariable($Definition['ident']);
                continue;
            }
            $Name = $this->Translate($Definition['name']);
            match ($Definition['type']) {
                \YouTubeMusic\VariablePresentation::TypeInteger => $this->RegisterVariableInteger($Definition['ident'], $Name, $Definition['presentation'], $Definition['position']),
                \YouTubeMusic\VariablePresentation::TypeString  => $this->RegisterVariableString($Definition['ident'], $Name, $Definition['presentation'], $Definition['position']),
            };
            if ($Definition['action']) {
                $this->EnableAction($Definition['ident']);
            }
        }
        $this->ApplyCoverMedia($Enabled);
    }

    /**
     * Legt das Medienobjekt „Cover“ (Bild, gecacht) an bzw. entfernt es.
     *
     * Bis 0.10 war das Cover eine String-Variable mit gleichem Ident; sie wird ersetzt.
     *
     * @param  bool $Enabled
     * @return void
     */
    private function ApplyCoverMedia(bool $Enabled): void
    {
        $ID = @IPS_GetObjectIDByIdent(\YouTubeMusic\Variable::Cover, $this->InstanceID);
        if ($ID !== false && IPS_GetObject($ID)['ObjectType'] === OBJECTTYPE_VARIABLE) {
            $this->UnregisterVariable(\YouTubeMusic\Variable::Cover);
            $ID = false;
        }
        if (!$Enabled) {
            if ($ID !== false) {
                IPS_DeleteMedia($ID, true);
            }
            return;
        }
        if ($ID === false) {
            // Kein IPSModule-Gegenstück für Medienobjekte vorhanden
            $ID = IPS_CreateMedia(MEDIATYPE_IMAGE);
            IPS_SetParent($ID, $this->InstanceID);
            IPS_SetIdent($ID, \YouTubeMusic\Variable::Cover);
            IPS_SetName($ID, $this->Translate('Cover'));
            IPS_SetPosition($ID, self::CoverPosition);
            IPS_SetMediaCached($ID, true);
            IPS_SetMediaFile($ID, 'media' . DIRECTORY_SEPARATOR . 'YouTubeMusic_Cover_' . $this->InstanceID . '.jpg', false);
            $this->SetCoverContent('');
        }
    }

    /**
     * Lädt das Cover in das Medienobjekt, nur wenn sich die Adresse geändert hat. Ohne Cover das Ersatzbild.
     *
     * @param  string $Url
     * @return void
     */
    private function UpdateCover(string $Url): void
    {
        if (!$this->VariablesEnabled() || $Url === $this->CoverUrl) {
            return;
        }
        $this->CoverUrl = $Url;
        $this->SetCoverContent($Url);
    }

    /**
     * Schreibt das Bild zur Adresse (bzw. das Ersatzbild) in das Medienobjekt.
     *
     * @param  string $Url
     * @return void
     */
    private function SetCoverContent(string $Url): void
    {
        $ID = @IPS_GetObjectIDByIdent(\YouTubeMusic\Variable::Cover, $this->InstanceID);
        if ($ID === false) {
            return;
        }
        $Image = '';
        if (str_starts_with($Url, 'https://')) {
            [$HttpCode, $Image, $CurlError] = \YouTubeMusic\Http::Request('GET', $Url, null, [], 10);
            $this->SendDebug(__FUNCTION__, 'HTTP ' . $HttpCode . ', ' . strlen($Image) . ' Bytes ' . $CurlError, 0);
            if ($HttpCode !== 200 || @imagecreatefromstring($Image) === false) {
                $Image = '';
            }
        }
        if ($Image === '') {
            $Image = (string) file_get_contents(__DIR__ . '/imgs/nocover.png');
        }
        IPS_SetMediaContent($ID, base64_encode($Image));
    }

    /**
     * Schreibt eine Statusvariable nur bei Änderung (und nur, wenn Variablen aktiv sind).
     *
     * @param  string $Ident
     * @param  mixed $Value
     * @return void
     */
    private function SetVariable(string $Ident, mixed $Value): void
    {
        if (!$this->VariablesEnabled() || @$this->GetIDForIdent($Ident) === false) {
            return;
        }
        if ($this->GetValue($Ident) !== $Value) {
            $this->SetValue($Ident, $Value);
        }
    }

    /**
     * Statusvariablen aus dem Gerätezustand und den Metadaten des Titels.
     *
     * @param  array $State Ergebnis von GetDeviceState
     * @return void
     */
    private function UpdateVariables(array $State): void
    {
        $this->SetVariable(\YouTubeMusic\Variable::Playback, \YouTubeMusic\Playback::FromLoungeState($State['state']));
        if ($State['volume'] >= 0) {
            $this->SetVariable(\YouTubeMusic\Variable::Volume, (int) $State['volume']);
        }
        $this->SetVariable(\YouTubeMusic\Variable::Position, (int) round($State['position']));
        $Meta = $this->MetadataFor($State['videoId']);
        $this->SetVariable(\YouTubeMusic\Variable::Title, $Meta['title']);
        $this->SetVariable(\YouTubeMusic\Variable::Artist, $Meta['artist']);
        $this->SetVariable(\YouTubeMusic\Variable::Album, $Meta['album']);
        $this->UpdateCover($Meta['cover']);
        // Die Lounge meldet die Dauer erst nach dem Laden; bis dahin die aus den Metadaten
        $Duration = $State['duration'] > 0 ? (int) round($State['duration']) : self::DurationSeconds($Meta['duration']);
        $this->SetVariable(\YouTubeMusic\Variable::Duration, $Duration);
    }

    /**
     * Metadaten eines Titels, aus dem Puffer oder vom Account.
     *
     * Gepuffert werden die letzten Titel je videoId: Beim Kachel-Gerät spielt jeder Browser einen eigenen Titel.
     *
     * @param  string $VideoId
     * @return array{title: string, artist: string, album: string, cover: string, duration: string, like: string}
     */
    private function MetadataFor(string $VideoId): array
    {
        $Empty = ['title' => '', 'artist' => '', 'album' => '', 'cover' => '', 'duration' => '', 'like' => ''];
        if (!\YouTubeMusic\YouTubeLink::IsVideoId($VideoId)) {
            return $Empty;
        }
        $Cache = is_array($this->Metadata) ? $this->Metadata : [];
        if (isset($Cache[$VideoId])) {
            return $Cache[$VideoId] + $Empty;
        }
        ob_start();
        $Meta = $this->SendToAccount(\YouTubeMusic\AccountFunction::Metadata, ['VideoId' => $VideoId]);
        $Error = trim((string) ob_get_clean());
        if (!is_array($Meta)) {
            // Inoffizielle Schnittstelle: nur ins Debug, beim nächsten Zustand erneut versuchen
            $this->SendDebug(__FUNCTION__, 'no metadata for ' . $VideoId . ' ' . $Error, 0);
            return $Empty;
        }
        $Meta = array_intersect_key($Meta, $Empty) + $Empty;
        $Cache[$VideoId] = $Meta;
        $this->Metadata = array_slice($Cache, -self::MetadataCacheSize, null, true);
        $this->SendDebug(__FUNCTION__, $Meta, 0);
        return $Meta;
    }

    /**
     * Dauer aus Text (z. B. 4:11 oder 1:02:30) in Sekunden.
     *
     * @param  string $Text
     * @return int
     */
    private static function DurationSeconds(string $Text): int
    {
        if (preg_match('/^\d{1,2}(:\d{2}){1,2}$/D', $Text) !== 1) {
            return 0;
        }
        $Seconds = 0;
        foreach (explode(':', $Text) as $Part) {
            $Seconds = $Seconds * 60 + (int) $Part;
        }
        return $Seconds;
    }

    /**
     * Zustand des Players im Browser übernehmen (von der Kachel oder über den WebHook).
     *
     * @param  string $Json
     * @return void
     */
    private function HandleTileState(string $Json): void
    {
        if ($this->IsCast()) {
            return;
        }
        $Data = json_decode($Json, true);
        if (!is_array($Data) || preg_match('/^[a-f0-9]{8,32}$/D', (string) ($Data['playerId'] ?? '')) !== 1) {
            throw new InvalidArgumentException('Invalid state');
        }
        $VideoId = (string) ($Data['videoId'] ?? '');
        $State = [
            'playerId'    => (string) $Data['playerId'],
            'videoId'     => \YouTubeMusic\YouTubeLink::IsVideoId($VideoId) ? $VideoId : '',
            'listId'      => preg_match('/^[A-Za-z0-9_-]{0,80}$/D', (string) ($Data['listId'] ?? '')) === 1 ? (string) $Data['listId'] : '',
            'state'       => max(-1, min(5, (int) ($Data['state'] ?? -1))),
            'currentTime' => max(0.0, (float) ($Data['currentTime'] ?? 0)),
            'duration'    => max(0.0, (float) ($Data['duration'] ?? 0)),
            'volume'      => max(-1, min(100, (int) ($Data['volume'] ?? -1))),
            'muted'       => ($Data['muted'] ?? false) === true,
            'hasPrevious' => ($Data['hasPrevious'] ?? false) === true,
            'hasNext'     => ($Data['hasNext'] ?? false) === true,
            'updated'     => microtime(true),
        ];
        $this->TileSeen = $State['updated'];
        $Before = is_array($this->TileState) ? $this->TileState : [];
        // Mehrere Visus spielen unabhängig voneinander. Gerätezustand und Befehle aus Skripten gelten dem Browser,
        // der zuletzt die Wiedergabe gestartet hat; die anderen werden erst übernommen, wenn dieser sich nicht mehr meldet.
        $Active = (string) ($Before['playerId'] ?? '');
        $ActiveAlive = $Active !== '' && microtime(true) - (float) ($Before['updated'] ?? 0) < self::TilePlayerTimeout;
        if ($ActiveAlive && $Active !== $State['playerId'] && !in_array($State['state'], [1, 3], true)) {
            return;
        }
        $this->TileState = $State;
        $Compare = static fn (array $S): array => array_intersect_key($S, array_flip(['playerId', 'videoId', 'listId', 'state', 'volume', 'muted', 'hasPrevious', 'hasNext']));
        if ($Compare($Before) !== $Compare($State)) {
            $this->PushDeviceState();
            return;
        }
        $this->UpdatePosition();
    }

    /**
     * Zustand des Kachel-Geräts im Format von GetDeviceState.
     *
     * @return array
     */
    private function TileDeviceState(): array
    {
        $State = (is_array($this->TileState) ? $this->TileState : []) + \YouTubeMusic\Lounge::EmptyState() + ['playerId' => ''];
        return [
            'videoId'     => $State['videoId'],
            'listId'      => $State['listId'],
            'state'       => $State['state'],
            'position'    => round(\YouTubeMusic\Lounge::Position($State, microtime(true)), 1),
            'duration'    => $State['duration'],
            'volume'      => $State['volume'],
            'muted'       => $State['muted'],
            'hasPrevious' => $State['hasPrevious'],
            'hasNext'     => $State['hasNext'],
            'shuffle'     => false,
            'loop'        => false,
            'connected'   => $State['playerId'] !== '' && microtime(true) - (float) $State['updated'] < self::TilePlayerTimeout,
        ];
    }

    /**
     * Befehl an alle Player im Browser (jede Visu spielt für sich): über die eingehängten Kacheln
     * und zum Abholen über den WebHook.
     *
     * Starten geht nur in einem Browser, in dem schon gespielt wurde (Autoplay-Sperre der Browser).
     *
     * @param  string $Command Befehl aus \YouTubeMusic\LoungeCommand
     * @param  array $Arguments
     * @return bool
     */
    private function TileCommand(string $Command, array $Arguments): bool
    {
        if (!is_float($this->TileSeen) || microtime(true) - $this->TileSeen >= self::TilePlayerTimeout) {
            echo $this->Translate('No browser is playing on this tile player. Start playback in the tile first.') . PHP_EOL;
            return false;
        }
        // Zeit in Millisekunden: steigt auch über einen Neustart der Instanz hinweg (die Laufzeit merkt sich die letzte ID)
        $Queue = is_array($this->TileCommands) ? $this->TileCommands : [];
        $Id = max((int) round(microtime(true) * 1000), (int) (end($Queue)['id'] ?? 0) + 1);
        // Ohne playerId: jeder Browser-Player der Instanz führt den Befehl aus
        $Entry = ['id' => $Id, 'playerId' => '', 'command' => $Command, 'args' => $Arguments, 'time' => time()];
        // Nur die letzten Befehle der letzten Minute zum Abholen vorhalten
        $Queue = array_filter($Queue, static fn (array $C): bool => $C['time'] > time() - 60);
        $Queue[] = $Entry;
        $this->TileCommands = array_slice(array_values($Queue), -10);
        $this->SendDebug(__FUNCTION__, $Entry, 0);
        $this->PushToTiles([\YouTubeMusic\TileMessage::Type => \YouTubeMusic\TileMessage::Command] + $Entry);
        return true;
    }

    /**
     * Befehl aus der Kachel an das gewählte Gerät (eigene Instanz oder ein anderer Player am selben Account).
     *
     * @param  int $Target
     * @param  string $Command play, pause, next, previous, seekTo, setVolume, setPlaylist
     * @param  array $Args
     * @return void
     */
    private function ExecuteCommand(int $Target, string $Command, array $Args): void
    {
        // Freigabe gilt nur in Richtung Kachel → Gerät (Einstellung „Steuerbare Geräte“ dieser Instanz)
        if ($Target !== $this->InstanceID && !in_array($Target, array_column($this->ControllableDevices(), 'id'), true)) {
            echo $this->Translate('This device may not be controlled from this tile') . PHP_EOL;
            return;
        }
        $Own = $Target === $this->InstanceID;
        $Text = static fn (string $Key): string => is_scalar($Args[$Key] ?? null) ? (string) $Args[$Key] : '';
        match ($Command) {
            \YouTubeMusic\LoungeCommand::Play        => $Own ? $this->Play() : YTM_Play($Target),
            \YouTubeMusic\LoungeCommand::Pause       => $Own ? $this->Pause() : YTM_Pause($Target),
            \YouTubeMusic\LoungeCommand::StopVideo   => $Own ? $this->Stop() : YTM_Stop($Target),
            \YouTubeMusic\LoungeCommand::Next        => $Own ? $this->Next() : YTM_Next($Target),
            \YouTubeMusic\LoungeCommand::Previous    => $Own ? $this->Previous() : YTM_Previous($Target),
            \YouTubeMusic\LoungeCommand::SeekTo      => $Own ? $this->Seek((float) $Text('newTime')) : YTM_Seek($Target, (float) $Text('newTime')),
            \YouTubeMusic\LoungeCommand::SetVolume   => $Own ? $this->SetVolume((int) $Text('volume')) : YTM_SetVolume($Target, (int) $Text('volume')),
            \YouTubeMusic\LoungeCommand::Mute        => $Own ? $this->SetMute(true) : YTM_SetMute($Target, true),
            \YouTubeMusic\LoungeCommand::UnMute      => $Own ? $this->SetMute(false) : YTM_SetMute($Target, false),
            \YouTubeMusic\LoungeCommand::SetPlaylist => $Own
                ? $this->PlayVideoAt($Text('videoId'), $Text('listId'), (float) $Text('currentTime'))
                : YTM_PlayVideoAt($Target, $Text('videoId'), $Text('listId'), (float) $Text('currentTime')),
            default => throw new InvalidArgumentException('Invalid command'),
        };
    }

    /**
     * Inhalte für die Kachel (Start, Neu, Entdecken, Mediathek, Suche, Details, Warteschlange, Liedtext).
     *
     * Die Antwort geht an alle Kacheln der Instanz; nur die anfragende erkennt sie an der Anfrage-ID.
     * Fehler des Accounts (z. B. Mediathek ohne Anmeldung) gehen als Text mit, nicht als Fehler der Aktion.
     *
     * @param  string $Json {request, kind, …}
     * @return void
     */
    private function HandleQuery(string $Json): void
    {
        $Data = json_decode($Json, true);
        $Request = (string) ($Data['request'] ?? '');
        if (!is_array($Data) || preg_match('/^[a-z0-9]{1,32}$/D', $Request) !== 1) {
            throw new InvalidArgumentException('Invalid query');
        }
        $Text = static fn (string $Key, int $Max = 200): string => mb_substr(is_scalar($Data[$Key] ?? null) ? trim((string) $Data[$Key]) : '', 0, $Max);
        ob_start();
        $Result = match ((string) ($Data['kind'] ?? '')) {
            'browse' => $this->Browse($Text('id', 120), $Text('params', 400)),
            'search' => $this->Search($Text('query'), $Text('filter', 20)),
            'more'   => $this->BrowseMore($Text('token', 2000), $Text('client', 10)),
            'queue'  => $this->GetQueue($Text('videoId', 11), $Text('playlistId', 80)),
            'lyrics' => $this->GetLyrics($Text('videoId', 11)),
            default  => false,
        };
        $Error = trim((string) ob_get_clean());
        $Message = [\YouTubeMusic\TileMessage::Type => \YouTubeMusic\TileMessage::Result, 'request' => $Request];
        if (is_array($Result)) {
            $Message['data'] = self::TrimResult($Result);
        } else {
            $Message['error'] = $Error !== '' ? $Error : $this->Translate('No answer from the YouTube Music Account');
        }
        $this->PushToTiles($Message);
    }

    /**
     * Ergebnis für die Kachel kürzen: je Eintrag nur die Felder, die die Kachel braucht (Kategorieseiten haben sonst 150 KB).
     *
     * @param  array $Result
     * @return array
     */
    private static function TrimResult(array $Result): array
    {
        $Fields = array_flip(['type', 'title', 'subtitle', 'videoId', 'playlistId', 'browseId', 'params', 'thumbnail', 'duration']);
        $Trim = static fn (array $Items): array => array_map(static fn (array $Item): array => array_intersect_key($Item, $Fields), $Items);
        if (isset($Result['shelves'])) {
            // Obergrenze gegen sehr große Seiten
            $Result['shelves'] = array_map(static fn (array $Shelf): array => ['items' => $Trim(array_slice($Shelf['items'] ?? [], 0, self::ShelfLimit))] + $Shelf, $Result['shelves']);
        }
        if (isset($Result['items'])) {
            $Result['items'] = $Trim($Result['items']);
        }
        return $Result;
    }

    /**
     * Andere Player, die die Kachel dieser Instanz steuern darf (gleicher Account, Einstellung „Steuerbare Geräte“).
     *
     * @return list<int>
     */
    private function AllowedTargets(): array
    {
        $Account = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($Account === 0) {
            return [];
        }
        $Siblings = array_values(array_filter(
            IPS_GetInstanceListByModuleID(\YouTubeMusic\GUID::Player),
            fn (int $ID): bool => $ID !== $this->InstanceID && IPS_GetInstance($ID)['ConnectionID'] === $Account
        ));
        switch ($this->ReadPropertyInteger(\YouTubeMusic\Property::ControlMode)) {
            case \YouTubeMusic\ControlMode::OwnOnly:
                return [];
            case \YouTubeMusic\ControlMode::Selected:
                $Rows = json_decode($this->ReadPropertyString(\YouTubeMusic\Property::ControlDevices), true);
                $Selected = array_map(static fn ($Row): int => (int) ($Row['InstanceID'] ?? 0), is_array($Rows) ? $Rows : []);
                return array_values(array_intersect($Siblings, $Selected));
            default:
                return $Siblings;
        }
    }

    /**
     * Steuerbare Geräte für die Kachel: zuerst das eigene, dann die freigegebenen, je mit zuletzt gemeldetem Zustand.
     *
     * @return list<array{id: int, name: string, type: int, own: bool, state: array}>
     */
    private function ControllableDevices(): array
    {
        $Allowed = $this->AllowedTargets();
        $Known = [];
        if ($Allowed !== [] && $this->HasAccount()) {
            ob_start();
            $Devices = $this->SendToAccount(\YouTubeMusic\AccountFunction::Devices, []);
            ob_end_clean();
            foreach (is_array($Devices) ? $Devices : [] as $Device) {
                $Known[(int) $Device['id']] = $Device;
            }
        }
        $List = [['id' => $this->InstanceID, 'name' => IPS_GetName($this->InstanceID), 'type' => $this->ReadPropertyInteger(\YouTubeMusic\Property::DeviceType), 'own' => true, 'state' => []]];
        // Keine Properties fremder Instanzen lesen: zwei Kacheln bauen sich gleichzeitig auf und würden sich gegenseitig sperren
        foreach ($Allowed as $ID) {
            $Type = (int) ($Known[$ID]['type'] ?? -1);
            // Kachel-Geräte anderer Instanzen nicht: sie stehen für „Wiedergabe in diesem Browser“ und sind nur in ihrer eigenen Kachel sinnvoll
            if ($Type === \YouTubeMusic\DeviceType::Tile) {
                continue;
            }
            $State = is_array($Known[$ID]['state'] ?? null) ? $Known[$ID]['state'] : [];
            $List[] = ['id' => $ID, 'name' => IPS_GetName($ID), 'type' => $Type, 'own' => false, 'state' => $State];
        }
        return $List;
    }

    /**
     * Metadaten des Titels nur aus dem Puffer (ohne Anfrage, z. B. beim Aufbau der Kachel).
     *
     * @param  string $VideoId
     * @return array{title: string, artist: string, album: string, cover: string, duration: string, like: string}
     */
    private function CachedMetadata(string $VideoId): array
    {
        $Empty = ['title' => '', 'artist' => '', 'album' => '', 'cover' => '', 'duration' => '', 'like' => ''];
        $Cache = is_array($this->Metadata) ? $this->Metadata : [];
        return is_array($Cache[$VideoId] ?? null) ? $Cache[$VideoId] + $Empty : $Empty;
    }

    /**
     * Übersetzte Texte der Kachel.
     *
     * @return array<string, string>
     */
    private function TileTexts(): array
    {
        $Texts = [];
        foreach (\YouTubeMusic\TileTexts::All as $Text) {
            $Texts[$Text] = $this->Translate($Text);
        }
        return $Texts;
    }

    /**
     * Ist ein Account als Parent verbunden?
     *
     * Bewusst ohne Prüfung auf „aktiv“: Ohne Anmeldung steht der Account auf 202, Suche und
     * öffentliche Seiten funktionieren trotzdem.
     *
     * @return bool
     */
    private function HasAccount(): bool
    {
        $Parent = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        return $Parent > 0 && IPS_InstanceExists($Parent);
    }

    /**
     * Ist dies ein Chrome-Cast-Gerät?
     *
     * @return bool
     */
    private function IsCast(): bool
    {
        return $this->ReadPropertyInteger(\YouTubeMusic\Property::DeviceType) === \YouTubeMusic\DeviceType::ChromeCast;
    }

    /**
     * Gewählte Chrome-Cast-Instanz, wenn sie existiert und vom Modul Chrome Cast ist.
     *
     * @return int 0, wenn keine gültige gewählt ist
     */
    private function CastInstance(): int
    {
        $ID = $this->ReadPropertyInteger(\YouTubeMusic\Property::CastInstance);
        if ($ID > 0 && IPS_InstanceExists($ID) && IPS_GetInstance($ID)['ModuleInfo']['ModuleID'] === \YouTubeMusic\GUID::ChromeCast) {
            return $ID;
        }
        return 0;
    }

    /**
     * Zeilen der Tabelle „Lounge-Anfragen heute“.
     *
     * @param  array $Stats
     * @return list<array{Category: string, Requests: int, Errors: int}>
     */
    private function RequestRows(array $Stats): array
    {
        $Names = [
            \YouTubeMusic\RequestCategory::LoungeToken   => 'Lounge: tokens',
            \YouTubeMusic\RequestCategory::LoungeCommand => 'Lounge: commands',
            \YouTubeMusic\RequestCategory::LoungePoll    => 'Lounge: status queries',
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
     * Stilles Wiederverbinden zeitversetzt anstoßen (ApplyChanges soll nicht auf das Gerät warten).
     *
     * @return void
     */
    private function ScheduleResume(): void
    {
        if ($this->IsCast() && $this->CastInstance() !== 0) {
            $this->RegisterOnceTimer(self::ResumeTimer, 'IPS_RequestAction($_IPS[\'TARGET\'], \'' . self::ResumeTimer . '\', true);');
        }
    }

    /**
     * Nach Übernehmen bzw. Start von Symcon die Lounge-Sitzung wieder aufnehmen, wenn am Gerät YouTube Music läuft
     * (Wiedergabe läuft dort weiter, das Modul hat seine Sitzung verloren). Startet nie eine App;
     * gelesen wird nur die Statusvariable der Chrome-Cast-Instanz. Fehler nur ins Debug.
     *
     * @return void
     */
    private function ResumeLounge(): void
    {
        $Cast = $this->CastInstance();
        $Session = is_array($this->LoungeSession) ? $this->LoungeSession : [];
        if ($Cast === 0 || ($Session['sid'] ?? '') !== '') {
            return;
        }
        $AppVariable = @IPS_GetObjectIDByIdent(\YouTubeMusic\ChromeCast::AppIdIdent, $Cast);
        if ($AppVariable === false || GetValueString($AppVariable) !== \YouTubeMusic\ChromeCast::YouTubeMusicApp) {
            return;
        }
        ob_start();
        $Ok = $this->ConnectLounge();
        $Error = trim((string) ob_get_clean());
        $this->SendDebug(__FUNCTION__, $Ok ? 'resumed' : 'failed ' . $Error, 0);
    }

    /**
     * Screen-ID der laufenden YouTube-Music-App am Gerät (CCast startet die App, falls sie nicht läuft).
     *
     * @return string|false
     */
    private function CurrentScreenId(): string|false
    {
        $DeviceID = $this->CastInstance();
        if ($DeviceID === 0) {
            echo $this->Translate('No Chrome Cast instance selected') . PHP_EOL;
            return false;
        }
        if (!function_exists('CCAST_GetYouTubeScreenId')) {
            echo $this->Translate('Chrome Cast library 0.51 or newer is required') . PHP_EOL;
            return false;
        }
        $ScreenId = CCAST_GetYouTubeScreenId($DeviceID, true);
        if (!is_string($ScreenId) || $ScreenId === '') {
            echo $this->Translate('The device did not report a YouTube screen ID') . PHP_EOL;
            return false;
        }
        return $ScreenId;
    }

    /**
     * Verbindet die Lounge mit dem Chrome-Cast-Gerät: Screen-ID über CCast, Lounge-Token, Handshake.
     *
     * @param  string $ScreenId bereits ermittelte Screen-ID (leer: bei CCast abfragen)
     * @return bool
     */
    private function ConnectLounge(string $ScreenId = '', array $InitialState = []): bool
    {
        $DeviceID = $this->CastInstance();
        if ($ScreenId === '') {
            $ScreenId = $this->CurrentScreenId();
            if ($ScreenId === false) {
                return false;
            }
        }
        // Versuch (2026-10-31): Lounge-Sitzung mit dem Konto anmelden, damit das Gerät mit dem Konto spielt (ohne Werbung).
        // Die Web-App meldet sich per Cookies an; wir mit dem Access-Token des Accounts. Ohne Erfolg anonym wie bisher.
        $Auth = '';
        if ($this->HasAccount()) {
            ob_start();
            $Token = $this->SendToAccount(\YouTubeMusic\AccountFunction::LoungeAuth, []);
            ob_end_clean();
            $Auth = is_string($Token) ? $Token : '';
        }
        $Headers = $this->LoungeHeaders($Auth);
        [$HttpCode, $Body, $CurlError] = $this->HttpRequest('POST', \YouTubeMusic\Lounge::MusicBase . \YouTubeMusic\Lounge::TokenPath, \YouTubeMusic\Lounge::TokenRequest([$ScreenId]), $Headers);
        $Tokens = \YouTubeMusic\Lounge::ParseTokens($HttpCode, $Body);
        if (count($Tokens) === 0 && $Auth !== '') {
            $this->SendDebug(__FUNCTION__, 'signed-in token request failed (HTTP ' . $HttpCode . '), anonymous', 0);
            $Auth = '';
            $Headers = $this->LoungeHeaders();
            [$HttpCode, $Body, $CurlError] = $this->HttpRequest('POST', \YouTubeMusic\Lounge::MusicBase . \YouTubeMusic\Lounge::TokenPath, \YouTubeMusic\Lounge::TokenRequest([$ScreenId]), $Headers);
            $Tokens = \YouTubeMusic\Lounge::ParseTokens($HttpCode, $Body);
        }
        $this->SendDebug(__FUNCTION__, 'device ' . $DeviceID . ', screen ' . substr($ScreenId, 0, 6) . '…, token HTTP ' . $HttpCode . ' ' . $CurlError . ', ' . count($Tokens) . ' token(s), ' . ($Auth !== '' ? 'signed in' : 'anonymous'), 0);
        if (count($Tokens) === 0) {
            echo $this->Translate('Could not get a YouTube Lounge token') . PHP_EOL;
            return false;
        }
        // Name der Fernbedienung auf dem Gerät („… connected“): eigener Name, sonst der Name dieser Instanz (nur Anzeige)
        $Name = mb_substr(trim($this->ReadPropertyString(\YouTubeMusic\Property::PlayerName)) ?: (trim(IPS_GetName($this->InstanceID)) ?: 'Symcon'), 0, 40);
        $Session = \YouTubeMusic\Lounge::NewSession($Tokens[0]['loungeToken'], $Name);
        // Zum Erkennen eines App-Neustarts am Gerät (neue Screen-ID, alte Sitzung läuft ins Leere)
        $Session['screenId'] = $ScreenId;
        $Session['auth'] = $Auth;
        [$Url, $Post] = \YouTubeMusic\Lounge::Handshake($Session, $InitialState);
        [$HttpCode, $Body, $CurlError] = $this->HttpRequest('POST', $Url, $Post, $Headers);
        $Events = \YouTubeMusic\Lounge::ParseChunks($Body);
        \YouTubeMusic\Lounge::ApplySession($Session, $Events);
        $this->SendDebug(__FUNCTION__, 'handshake HTTP ' . $HttpCode . ' ' . $CurlError . ', ' . count($Events) . ' events, SID ' . ($Session['sid'] !== '' ? 'ok' : 'missing'), 0);
        $this->DebugLoungeDevices($Events);
        if ($Session['sid'] === '') {
            echo $this->Translate('YouTube Lounge connection failed') . PHP_EOL;
            return false;
        }
        $State = [];
        \YouTubeMusic\Lounge::ApplyState($State, $Events, microtime(true));
        if (!$this->lock(self::LockLounge)) {
            return false;
        }
        $this->LoungeSession = $Session;
        $this->LoungeState = $State;
        $this->unlock(self::LockLounge);
        $this->SetTimerInterval(\YouTubeMusic\Timer::LoungePoll, self::LoungePollInterval);
        $this->PushDeviceState();
        return true;
    }

    /**
     * Sendet einen Befehl an das Gerät. Verbindet bei Bedarf (neu) und wiederholt den Befehl einmal.
     *
     * @param  string $Command
     * @param  array $Arguments
     * @return bool
     */
    private function LoungeCommand(string $Command, array $Arguments = []): bool
    {
        if (!$this->IsCast()) {
            return $this->TileCommand($Command, $Arguments);
        }
        $Session = is_array($this->LoungeSession) ? $this->LoungeSession : [];
        $State = is_array($this->LoungeState) ? $this->LoungeState : [];
        $Connected = ($Session['sid'] ?? '') !== '' && ($State['screenConnected'] ?? false);
        $ScreenId = '';
        // Startbefehle: Hat sich die App am Gerät beendet (Leerlauf) oder neu gestartet, meldet die Lounge das erst
        // nach etwa 30 s; bis dahin nimmt die alte Sitzung Befehle an, die ins Leere gehen (live an „Büro“).
        // Deshalb die aktuelle Screen-ID prüfen; läuft die App nicht, startet CCast sie.
        if (in_array($Command, [\YouTubeMusic\LoungeCommand::SetPlaylist, \YouTubeMusic\LoungeCommand::Play], true)) {
            $ScreenId = $this->CurrentScreenId();
            if ($ScreenId === false) {
                return false;
            }
            if ($Connected && ($Session['screenId'] ?? '') !== $ScreenId) {
                $this->SendDebug(__FUNCTION__, 'screen changed, reconnect', 0);
                $Connected = false;
            }
        }
        // Ohne Verbindung wie die Web-App: neue Sitzung, Wiedergabe gleich beim Verbinden (setInitialState); so spielt ein
        // Gerät, das nicht selbst angemeldet ist, mit dem Konto (TV Wohnzimmer). In einer bestehenden Sitzung übernimmt
        // das Gerät setInitialState nicht (und meldet jedes Mal ein neues Gerät): dort setPlaylist (Nutzer, 2026-10-31)
        if (!$Connected && $Command === \YouTubeMusic\LoungeCommand::SetPlaylist && ($Arguments['videoId'] ?? '') !== '') {
            $Initial = ['videoId' => $Arguments['videoId'], 'currentTime' => $Arguments['currentTime'] ?? 0, 'currentIndex' => 0];
            if (($Arguments['listId'] ?? '') !== '') {
                $Initial['listId'] = $Arguments['listId'];
            }
            return $this->ConnectLounge($ScreenId, $Initial);
        }
        if (!$Connected && !$this->ConnectLounge($ScreenId)) {
            return false;
        }
        for ($Try = 0; $Try < 2; $Try++) {
            if (!$this->lock(self::LockLounge)) {
                return false;
            }
            $Session = is_array($this->LoungeSession) ? $this->LoungeSession : [];
            [$Url, $Post] = \YouTubeMusic\Lounge::Command($Session, $Command, $Arguments);
            $this->LoungeSession = $Session;
            $this->unlock(self::LockLounge);
            [$HttpCode, $Body, $CurlError] = $this->HttpRequest('POST', $Url, $Post, $this->LoungeHeaders((string) ($Session['auth'] ?? '')));
            $this->SendDebug(__FUNCTION__, $Command . ' ' . json_encode(array_diff_key($Arguments, ['videoIds' => 0, 'more' => 0])) . ' → HTTP ' . $HttpCode . ' ' . $CurlError, 0);
            if ($HttpCode === 200 && !str_contains($Body, 'loungeScreenDisconnected')) {
                // Ereignisse zum Befehl zeitnah abholen
                $this->SetTimerInterval(\YouTubeMusic\Timer::LoungePoll, 200);
                return true;
            }
            if ($Try === 0 && ($CurlError === '') && $this->ConnectLounge()) {
                continue;
            }
            break;
        }
        echo $this->Translate('YouTube Lounge command failed') . ': ' . ($CurlError !== '' ? $CurlError : 'HTTP ' . $HttpCode) . PHP_EOL;
        return false;
    }

    /**
     * Holt die Ereignisse des Geräts (Timer LoungePoll).
     *
     * Fehler nur ins Debug (inoffizielle Schnittstelle). Ist kein Bildschirm mehr verbunden (App beendet),
     * endet die Abfrage; erst der nächste Befehl verbindet neu, die App wird nicht ungefragt gestartet.
     *
     * @return void
     */
    private function PollLounge(): void
    {
        $this->SetTimerInterval(\YouTubeMusic\Timer::LoungePoll, self::LoungePollInterval);
        $Session = is_array($this->LoungeSession) ? $this->LoungeSession : [];
        if (($Session['sid'] ?? '') === '') {
            $this->SetTimerInterval(\YouTubeMusic\Timer::LoungePoll, 0);
            return;
        }
        [$HttpCode, $Body, $CurlError] = $this->HttpRequest('GET', \YouTubeMusic\Lounge::Poll($Session), null, $this->LoungeHeaders((string) ($Session['auth'] ?? '')), 10, self::LoungePollDuration);
        if (\YouTubeMusic\Lounge::SessionExpired($HttpCode, $Body) || $CurlError !== '') {
            $this->SendDebug(__FUNCTION__, 'session ended: HTTP ' . $HttpCode . ' ' . $CurlError, 0);
            $this->StopLounge();
            return;
        }
        // Ein unvollständiger Block am Ende wird verworfen, die nächste Abfrage liefert ihn ab AID erneut
        $Events = \YouTubeMusic\Lounge::ParseChunks($Body);
        if (count($Events) === 0) {
            $this->UpdatePosition();
            return;
        }
        if (!$this->lock(self::LockLounge)) {
            return;
        }
        $Session = is_array($this->LoungeSession) ? $this->LoungeSession : [];
        $Connected = \YouTubeMusic\Lounge::ApplySession($Session, $Events);
        $State = is_array($this->LoungeState) ? $this->LoungeState : [];
        $Changed = \YouTubeMusic\Lounge::ApplyState($State, $Events, microtime(true));
        $this->LoungeSession = $Session;
        $this->LoungeState = $State;
        $this->unlock(self::LockLounge);
        $this->SendDebug(__FUNCTION__, implode(',', array_column($Events, 1)), 0);
        if (!$Connected || !$State['screenConnected']) {
            $this->SendDebug(__FUNCTION__, 'screen disconnected', 0);
            $this->StopLounge();
            return;
        }
        if ($Changed) {
            $this->PushDeviceState();
            return;
        }
        $this->UpdatePosition();
    }

    /**
     * Hochgerechnete Position in die Statusvariable, solange der Titel spielt.
     *
     * @return void
     */
    private function UpdatePosition(): void
    {
        $State = $this->DeviceState();
        if ($State['state'] === \YouTubeMusic\LoungeState::Playing) {
            $this->SetVariable(\YouTubeMusic\Variable::Position, (int) round($State['position']));
        }
    }

    /**
     * Meldet die Fernbedienung vom Gerät ab (wie die Web-App beim Trennen) und beendet die Abfrage. Fehler nur ins Debug.
     *
     * @return void
     */
    private function LeaveLounge(): void
    {
        if (!$this->lock(self::LockLounge)) {
            return;
        }
        $Session = is_array($this->LoungeSession) ? $this->LoungeSession : [];
        $Url = ($Session['sid'] ?? '') !== '' ? \YouTubeMusic\Lounge::Terminate($Session) : '';
        $this->LoungeSession = $Session;
        $this->unlock(self::LockLounge);
        if ($Url !== '') {
            [$HttpCode, , $CurlError] = $this->HttpRequest('POST', $Url, ['count' => '0'], $this->LoungeHeaders((string) ($Session['auth'] ?? '')));
            $this->SendDebug(__FUNCTION__, 'terminate → HTTP ' . $HttpCode . ' ' . $CurlError, 0);
        }
        $this->StopLounge();
    }

    /**
     * Beendet die Abfrage nach Verlust der Sitzung.
     *
     * @return void
     */
    private function StopLounge(): void
    {
        $this->SetTimerInterval(\YouTubeMusic\Timer::LoungePoll, 0);
        $this->lock(self::LockLounge);
        $this->LoungeSession = [];
        $State = is_array($this->LoungeState) ? $this->LoungeState : [];
        $State['screenConnected'] = false;
        $State['state'] = \YouTubeMusic\LoungeState::Unknown;
        $this->LoungeState = $State;
        $this->unlock(self::LockLounge);
        $this->PushDeviceState();
    }

    /**
     * Zustand des Geräts ins Debug und an die Kacheln.
     *
     * @return void
     */
    private function PushDeviceState(): void
    {
        $State = $this->DeviceState();
        $this->SendDebug('Device State', $State, 0);
        $this->UpdateVariables($State);
        $this->PushToTiles([\YouTubeMusic\TileMessage::Type => \YouTubeMusic\TileMessage::Device, 'state' => $State, 'meta' => $this->CachedMetadata($State['videoId'])]);
        // Für die Kacheln der anderen Geräte am Account; Fehler nur ins Debug
        if ($this->HasAccount()) {
            ob_start();
            // Typ mitschicken: der Account liest keine Properties fremder Instanzen (gegenseitige Sperre beim Kachelaufbau)
            $this->SendToAccount(\YouTubeMusic\AccountFunction::State, $State + $this->MetadataFor($State['videoId'])
                + ['type' => $this->ReadPropertyInteger(\YouTubeMusic\Property::DeviceType)]);
            $Error = trim((string) ob_get_clean());
            if ($Error !== '') {
                $this->SendDebug(__FUNCTION__, $Error, 0);
            }
        }
    }

    /**
     * HTTP-Anfrage mit Zählung je Kategorie (siehe YTM_GetRequestStatistics).
     *
     * Gespeichert wird nur beim Tageswechsel, nicht bei jeder Lounge-Abfrage (alle 2 s).
     *
     * @param  string $Method
     * @param  string $Url
     * @param  array<string, string>|string|null $Body
     * @param  list<string> $Headers
     * @param  int $Timeout
     * @param  int $StreamMs
     * @return array{0: int, 1: string, 2: string}
     */
    private function HttpRequest(string $Method, string $Url, array|string|null $Body = null, array $Headers = [], int $Timeout = 10, int $StreamMs = 0): array
    {
        $Result = \YouTubeMusic\Http::Request($Method, $Url, $Body, $Headers, $Timeout, $StreamMs);
        [$HttpCode, , $CurlError] = $Result;
        $Failed = $CurlError !== '' || $HttpCode === 0 || $HttpCode >= 400;
        if ($this->lock(self::LockStats)) {
            $Stats = is_array($this->RequestStats) ? $this->RequestStats : [];
            $NewDay = \YouTubeMusic\RequestCounter::Count($Stats, $Method, $Url, $Failed, time());
            $this->RequestStats = $Stats;
            $this->unlock(self::LockStats);
            if ($NewDay) {
                $this->WriteAttributeString(\YouTubeMusic\Attribute::RequestStats, json_encode($Stats));
                if (isset($Stats['previous'])) {
                    $this->SendDebug('Requests', 'day ' . $Stats['previous']['day'] . ': ' . json_encode($Stats['previous']), 0);
                }
            }
        }
        return $Result;
    }

    /**
     * Header für Anfragen an die Lounge.
     *
     * @return list<string>
     */
    private function LoungeHeaders(string $Auth = ''): array
    {
        $Headers = ['Origin: https://www.youtube.com', 'User-Agent: ' . self::UserAgent];
        if ($Auth !== '') {
            $Headers[] = 'Authorization: Bearer ' . $Auth;
        }
        return $Headers;
    }

    /**
     * Geräte der Lounge-Sitzung ins Debug (Typ, App, Name, ob mit Konto); ohne Kennungen und Token.
     *
     * @param  array $Events
     * @return void
     */
    private function DebugLoungeDevices(array $Events): void
    {
        foreach ($Events as [$Index, $Name, $Data]) {
            if ($Name !== 'loungeStatus') {
                continue;
            }
            $Devices = json_decode((string) ($Data['devices'] ?? '[]'), true);
            foreach (is_array($Devices) ? $Devices : [] as $Device) {
                $Keys = array_keys($Device);
                $this->SendDebug('LoungeDevice', ($Device['type'] ?? '?') . ' / ' . ($Device['app'] ?? '?') . ' / ' . ($Device['name'] ?? '?')
                    . ' / user: ' . (isset($Device['user']) || isset($Device['userAvatarUri']) ? 'yes' : 'no') . ' / keys: ' . implode(',', $Keys), 0);
            }
        }
    }

    /**
     * Buffer initialisieren.
     *
     * @return void
     */
    private function InitBuffer(): void
    {
        $this->LoungeSession = [];
        $this->LoungeState = [];
        $this->Metadata = [];
        $this->CoverUrl = '';
        $this->TileState = [];
        $this->TileSeen = 0.0;
        $this->TileCommands = [];
        // Zähler überstehen Neustarts nur bis zum letzten Speichern (Tageswechsel)
        $Stats = json_decode($this->ReadAttributeString(\YouTubeMusic\Attribute::RequestStats), true);
        $this->RequestStats = is_array($Stats) ? $Stats : [];
    }

    /**
     * Sendet eine Nachricht an alle geöffneten Kacheln.
     *
     * @param  array $Message
     * @return void
     */
    private function PushToTiles(array $Message): void
    {
        $this->UpdateVisualizationValue(json_encode($Message));
    }

    /**
     * Metadaten eines Titels an alle Kacheln (jede Kachel übernimmt sie für ihren eigenen Titel).
     *
     * @param  string $VideoId
     * @return void
     */
    private function PushMetadata(string $VideoId): void
    {
        $Meta = $this->MetadataFor($VideoId);
        if ($Meta['title'] === '' && $Meta['cover'] === '') {
            return;
        }
        $this->PushToTiles([\YouTubeMusic\TileMessage::Type => \YouTubeMusic\TileMessage::Meta, 'videoId' => $VideoId, 'meta' => $Meta]);
    }
}
