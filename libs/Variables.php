<?php

declare(strict_types=1);

/**
 * Statusvariablen der Geräte-Instanz (YouTube Music Player)
 *
 * Ohne Symcon-Abhängigkeit, damit die Darstellungen per Unit-Test gegen die Parameterlisten aus
 * IPS_GetPresentations geprüft werden können (tests/presentations.json).
 *
 * @project       YouTubeMusic
 * @file          Variables.php
 * @package       YouTubeMusic
 * @author        Michael Tröger <nall4chan@gmail.com>
 * @copyright     2026 Michael Tröger
 * @license       Custom Non-Commercial Share-Alike License (see LICENSE)
 */

namespace YouTubeMusic {
    /**
     * Idents der Statusvariablen
     */
    class Variable
    {
        public const Playback = 'Playback';
        public const Title = 'Title';
        public const Artist = 'Artist';
        public const Album = 'Album';
        /**
         * Medienobjekt (Bild) mit dem Cover, keine Variable
         */
        public const Cover = 'Cover';
        public const Volume = 'Volume';
        public const Position = 'Position';
        public const Duration = 'Duration';
    }

    /**
     * Werte der Variable Playback (Darstellung „Wiedergabe“)
     */
    class Playback
    {
        public const Previous = 0;
        public const Stop = 1;
        public const Play = 2;
        public const Pause = 3;
        public const Next = 4;

        /**
         * Wert der Variable aus dem Zustand der Lounge (state).
         *
         * @param  int $LoungeState
         * @return int
         */
        public static function FromLoungeState(int $LoungeState): int
        {
            return match ($LoungeState) {
                LoungeState::Playing, LoungeState::Buffering => self::Play,
                LoungeState::Paused => self::Pause,
                default             => self::Stop,
            };
        }
    }

    /**
     * Darstellungen und Reihenfolge der Statusvariablen
     */
    class VariablePresentation
    {
        public const TypeBoolean = 0;
        public const TypeInteger = 1;
        public const TypeFloat = 2;
        public const TypeString = 3;

        /**
         * Alle Statusvariablen.
         *
         * @return list<array{ident: string, type: int, name: string, presentation: array, position: int, action: bool}>
         */
        public static function Definitions(): array
        {
            $Text = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
            $Seconds = ['PRESENTATION' => VARIABLE_PRESENTATION_DURATION, 'FORMAT' => 1];
            return [
                ['ident' => Variable::Playback, 'type' => self::TypeInteger, 'name' => 'Playback', 'presentation' => ['PRESENTATION' => VARIABLE_PRESENTATION_PLAYBACK], 'position' => 10, 'action' => true],
                ['ident' => Variable::Title, 'type' => self::TypeString, 'name' => 'Title', 'presentation' => $Text, 'position' => 20, 'action' => false],
                ['ident' => Variable::Artist, 'type' => self::TypeString, 'name' => 'Artist', 'presentation' => $Text, 'position' => 30, 'action' => false],
                ['ident' => Variable::Album, 'type' => self::TypeString, 'name' => 'Album', 'presentation' => $Text, 'position' => 40, 'action' => false],
                // USAGE_TYPE 3 = Lautstärke (C:\IP-Symcon\presentations\sliderForm.php, Symcon 9.1)
                ['ident' => Variable::Volume, 'type' => self::TypeInteger, 'name' => 'Volume', 'presentation' => ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => 0, 'MAX' => 100, 'STEP_SIZE' => 1, 'SUFFIX' => ' %', 'USAGE_TYPE' => 3], 'position' => 60, 'action' => true],
                // FORMAT 1 = Minuten und Sekunden
                ['ident' => Variable::Position, 'type' => self::TypeInteger, 'name' => 'Position', 'presentation' => $Seconds, 'position' => 70, 'action' => false],
                ['ident' => Variable::Duration, 'type' => self::TypeInteger, 'name' => 'Duration', 'presentation' => $Seconds, 'position' => 80, 'action' => false],
            ];
        }
    }
}
