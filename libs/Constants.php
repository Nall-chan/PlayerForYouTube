<?php

declare(strict_types=1);

/**
 * Konstanten der Library YouTubeMusic
 *
 * @project       YouTubeMusic
 * @file          Constants.php
 * @package       YouTubeMusic
 * @author        Michael Tröger <nall4chan@gmail.com>
 * @copyright     2026 Michael Tröger
 * @license       Custom Non-Commercial Share-Alike License (see LICENSE)
 */

namespace YouTubeMusic {
    /**
     * GUIDs der Module
     */
    class GUID
    {
        public const Player = '{432AD6E7-A9AC-44D5-A26E-962C77035C9D}';
        public const Account = '{B2338A56-F57D-47A5-90BD-2C7E4FE3033B}';
        /**
         * Datenfluss Gerät → Account
         */
        public const ToAccount = '{7D679ECD-446F-401E-B62C-FB1CDAB71880}';
        /**
         * Datenfluss Account → Geräte
         */
        public const ToDevice = '{077870C0-B790-4D1C-9A9E-E64E730710F2}';
        /**
         * Chrome Cast (Library CCast) als Ausgabegerät
         */
        public const ChromeCast = '{9034A9D8-F004-22EA-9391-BF2E5E1CAB31}';
    }

    /**
     * Namen der Properties
     */
    class Property
    {
        public const ClientId = 'ClientId';
        public const ClientSecret = 'ClientSecret';
        public const PlayerName = 'PlayerName';
        public const HiddenPlayback = 'HiddenPlayback';
        public const DeviceType = 'DeviceType';
        public const CastInstance = 'CastInstance';
        public const EnableVariables = 'EnableVariables';
        public const ControlMode = 'ControlMode';
        /**
         * Liste [{InstanceID}] der steuerbaren Player bei ControlMode::Selected
         */
        public const ControlDevices = 'ControlDevices';
    }

    /**
     * Arten der Geräte-Instanz (Property DeviceType)
     */
    class DeviceType
    {
        /**
         * Wiedergabe im Browser der Kachel (IFrame-Player)
         */
        public const Tile = 0;
        /**
         * Chrome Cast (Library CCast) per YouTube Lounge
         */
        public const ChromeCast = 1;
    }

    /**
     * Auswahl der Property EnableVariables
     */
    class EnableVariables
    {
        /**
         * Kachel-Gerät aus, Chrome Cast an
         */
        public const Auto = 0;
        public const On = 1;
        public const Off = 2;
    }

    /**
     * Auswahl der Property ControlMode: welche anderen Player die Kachel dieser Instanz steuern darf
     */
    class ControlMode
    {
        /**
         * Alle Player am selben Account
         */
        public const All = 0;
        /**
         * Nur das eigene Gerät (keine Geräteauswahl in der Kachel)
         */
        public const OwnOnly = 1;
        /**
         * Nur die in ControlDevices eingetragenen Player
         */
        public const Selected = 2;
    }

    /**
     * Felder im Datenfluss (nur DataID ist von Symcon vorgegeben)
     */
    class Flow
    {
        public const DataID = 'DataID';
        public const Function = 'Function';
        public const Params = 'Params';
        public const Type = 'Type';
        public const Source = 'Source';
        public const Target = 'Target';
        public const Data = 'Data';
        public const Result = 'Result';
        public const Error = 'Error';
    }

    /**
     * Funktionen des Accounts für die Geräte (Flow::Function)
     */
    class AccountFunction
    {
        public const Search = 'Search';
        public const Browse = 'Browse';
        public const BrowseMore = 'BrowseMore';
        public const GetQueue = 'GetQueue';
        public const GetLyrics = 'GetLyrics';
        /**
         * Titel, Interpret, Album, Cover und Dauer zu einer Video-ID (Music-Client, anonym)
         */
        public const Metadata = 'Metadata';
        /**
         * Gerät meldet seinen Zustand; der Account verteilt ihn an alle Geräte
         */
        public const State = 'State';
        /**
         * Liste der Geräte am Account mit letztem Zustand
         */
        public const Devices = 'Devices';
        /**
         * Bewertung eines Titels setzen (Data API videos.rate)
         */
        public const SetRating = 'SetRating';
    }

    /**
     * Nachrichtenarten Account → Geräte (Flow::Type)
     */
    class DeviceMessage
    {
        public const State = 'State';
        /**
         * Account (neu) gestartet: Geräte melden Typ und Zustand erneut
         */
        public const Announce = 'Announce';
    }

    /**
     * Namen der Attribute
     */
    class Attribute
    {
        public const RefreshToken = 'RefreshToken';
        public const AccessToken = 'AccessToken';
        public const AccessExpiry = 'AccessExpiry';
        public const ChannelId = 'ChannelId';
        public const ChannelName = 'ChannelName';
        public const RequestStats = 'RequestStats';
        /**
         * Anmeldung abgelaufen bzw. widerrufen (Fehlerstatus bis zur neuen Anmeldung oder Abmeldung)
         */
        public const SignInExpired = 'SignInExpired';
        /**
         * Schlüssel für den WebHook der Kachel-Laufzeit
         */
        public const HookSecret = 'HookSecret';
    }

    /**
     * Timer
     */
    class Timer
    {
        public const DevicePoll = 'DevicePoll';
        public const RefreshToken = 'RefreshToken';
        public const LoungePoll = 'LoungePoll';
    }

    /**
     * Idents für RequestAction aus Timern
     */
    class TimerAction
    {
        public const DevicePoll = 'DevicePoll';
        public const RefreshToken = 'RefreshToken';
        public const LoungePoll = 'LoungePoll';
    }

    /**
     * Erlaubte Hosts für den Rohzugriff (YTM_ApiRequest)
     */
    class ApiHosts
    {
        public const Allowed = ['www.googleapis.com', 'youtube.googleapis.com', 'music.youtube.com', 'www.youtube.com'];
    }

    /**
     * Idents für RequestAction aus der Kachel
     */
    class TileAction
    {
        public const Debug = 'Debug';
        /**
         * Zustand des Players im Browser (Kachel-Gerät)
         */
        public const TileState = 'TileState';
        /**
         * Befehl an das gewählte Gerät {target, command, args}
         */
        public const Command = 'Command';
        /**
         * Bewertung eines Titels {videoId, rating}
         */
        public const Rating = 'Rating';
        /**
         * Metadaten zu einer videoId anfragen (Antwort als TileMessage::Meta an alle Kacheln)
         */
        public const Metadata = 'Metadata';
        /**
         * Liste in Titel auflösen {request, listId, videoId} (Antwort als TileMessage::Playlist)
         */
        public const ResolveList = 'ResolveList';
        /**
         * Steuerbare Geräte neu anfragen (Antwort als TileMessage::Devices)
         */
        public const Devices = 'Devices';
        /**
         * Inhalte anfragen {request, kind: browse|search|more|queue|lyrics, …} (Antwort als TileMessage::Result)
         */
        public const Query = 'Query';
    }

    /**
     * Nachrichtentypen an die Kachel (UpdateVisualizationValue)
     */
    class TileMessage
    {
        public const Type = 'type';
        public const Reload = 'reload';
        public const Device = 'device';
        /**
         * Metadaten zu einer videoId {videoId, meta}
         */
        public const Meta = 'meta';
        /**
         * Aufgelöste Liste {request, listId, videoIds, index}
         */
        public const Playlist = 'playlist';
        /**
         * Steuerbare Geräte mit Zustand {devices, serverTime}
         */
        public const Devices = 'devices';
        /**
         * Antwort auf TileAction::Query {request, data} oder {request, error}
         */
        public const Result = 'result';
        /**
         * Zustand eines anderen Geräts am selben Account
         */
        public const RemoteState = 'remoteState';
        /**
         * Befehl an den Player im Browser (Kachel-Gerät)
         */
        public const Command = 'command';
    }
}
