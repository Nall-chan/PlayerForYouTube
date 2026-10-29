<?php

declare(strict_types=1);

/**
 * OAuth 2.0 Device Flow für Google (Client-Typ „TVs and Limited Input devices“)
 *
 * Ohne Symcon-Abhängigkeit: baut die Anfragen und wertet die Antworten aus.
 * Ablauf: https://developers.google.com/identity/protocols/oauth2/limited-input-device
 *
 * @project       YouTubeMusic
 * @file          GoogleOAuth.php
 * @package       YouTubeMusic
 * @author        Michael Tröger <nall4chan@gmail.com>
 * @copyright     2026 Michael Tröger
 * @license       Custom Non-Commercial Share-Alike License (see LICENSE)
 */

namespace YouTubeMusic {
    /**
     * Endpunkte und feste Werte von Google OAuth
     */
    class Google
    {
        public const DeviceCodeUrl = 'https://oauth2.googleapis.com/device/code';
        public const TokenUrl = 'https://oauth2.googleapis.com/token';
        public const RevokeUrl = 'https://oauth2.googleapis.com/revoke';
        public const DeviceGrant = 'urn:ietf:params:oauth:grant-type:device_code';
        public const RefreshGrant = 'refresh_token';
        /**
         * Lesen, Bewerten, Playlists bearbeiten. Der Device Flow lehnt `youtube.force-ssl` ab
         * („Invalid device flow scope“, geprüft 2026-10-29), erlaubt ist nur `youtube` bzw. `youtube.readonly`.
         */
        public const Scope = 'https://www.googleapis.com/auth/youtube';
    }

    /**
     * Ergebnis einer Token-Abfrage
     */
    class TokenResult
    {
        public const Ok = 'ok';
        public const Pending = 'pending';
        public const SlowDown = 'slow_down';
        public const Denied = 'denied';
        public const Expired = 'expired';
        public const InvalidGrant = 'invalid_grant';
        public const Error = 'error';
    }

    /**
     * Device Flow: Anfragen und Auswertung der Antworten
     */
    class DeviceFlow
    {
        /**
         * Formularfelder für die Anforderung eines Gerätecodes.
         *
         * @param  string $ClientId
         * @param  string $Scope
         * @return array<string, string>
         */
        public static function DeviceCodeRequest(string $ClientId, string $Scope = Google::Scope): array
        {
            return ['client_id' => $ClientId, 'scope' => $Scope];
        }

        /**
         * Formularfelder für die Abfrage des Tokens zum Gerätecode.
         *
         * @param  string $ClientId
         * @param  string $ClientSecret
         * @param  string $DeviceCode
         * @return array<string, string>
         */
        public static function TokenPollRequest(string $ClientId, string $ClientSecret, string $DeviceCode): array
        {
            return ['client_id' => $ClientId, 'client_secret' => $ClientSecret, 'device_code' => $DeviceCode, 'grant_type' => Google::DeviceGrant];
        }

        /**
         * Formularfelder für die Erneuerung des Access-Tokens.
         *
         * @param  string $ClientId
         * @param  string $ClientSecret
         * @param  string $RefreshToken
         * @return array<string, string>
         */
        public static function RefreshRequest(string $ClientId, string $ClientSecret, string $RefreshToken): array
        {
            return ['client_id' => $ClientId, 'client_secret' => $ClientSecret, 'refresh_token' => $RefreshToken, 'grant_type' => Google::RefreshGrant];
        }

        /**
         * Wertet die Antwort auf die Gerätecode-Anforderung aus.
         *
         * @param  int $HttpCode
         * @param  string $Body
         * @return array{device_code: string, user_code: string, verification_url: string, expires_in: int, interval: int}|array{error: string}
         */
        public static function ParseDeviceCode(int $HttpCode, string $Body): array
        {
            $Data = json_decode($Body, true);
            if (!is_array($Data)) {
                return ['error' => 'HTTP ' . $HttpCode];
            }
            if ($HttpCode !== 200 || !isset($Data['device_code'], $Data['user_code'])) {
                return ['error' => self::ErrorText($Data, $HttpCode)];
            }
            return [
                'device_code'      => (string) $Data['device_code'],
                'user_code'        => (string) $Data['user_code'],
                'verification_url' => (string) ($Data['verification_url'] ?? $Data['verification_uri'] ?? 'https://www.google.com/device'),
                'expires_in'       => (int) ($Data['expires_in'] ?? 1800),
                'interval'         => max(1, (int) ($Data['interval'] ?? 5)),
            ];
        }

        /**
         * Wertet die Antwort des Token-Endpunkts aus (Abfrage zum Gerätecode oder Erneuerung).
         *
         * @param  int $HttpCode
         * @param  string $Body
         * @return array{result: string, access_token?: string, refresh_token?: string, expires_in?: int, scope?: string, error?: string}
         */
        public static function ParseTokenResponse(int $HttpCode, string $Body): array
        {
            $Data = json_decode($Body, true);
            if (!is_array($Data)) {
                return ['result' => TokenResult::Error, 'error' => 'HTTP ' . $HttpCode];
            }
            if ($HttpCode === 200 && isset($Data['access_token'])) {
                $Result = [
                    'result'       => TokenResult::Ok,
                    'access_token' => (string) $Data['access_token'],
                    'expires_in'   => (int) ($Data['expires_in'] ?? 3600),
                    'scope'        => (string) ($Data['scope'] ?? ''),
                ];
                // Bei der Erneuerung liefert Google meist kein neues Refresh-Token
                if (isset($Data['refresh_token'])) {
                    $Result['refresh_token'] = (string) $Data['refresh_token'];
                }
                return $Result;
            }
            $Map = [
                'authorization_pending' => TokenResult::Pending,
                'slow_down'             => TokenResult::SlowDown,
                'access_denied'         => TokenResult::Denied,
                'expired_token'         => TokenResult::Expired,
                'invalid_grant'         => TokenResult::InvalidGrant,
            ];
            return ['result' => $Map[$Data['error'] ?? ''] ?? TokenResult::Error, 'error' => self::ErrorText($Data, $HttpCode)];
        }

        /**
         * Fehlertext aus einer Google-Antwort.
         *
         * @param  array $Data
         * @param  int $HttpCode
         * @return string
         */
        private static function ErrorText(array $Data, int $HttpCode): string
        {
            $Error = is_string($Data['error'] ?? null) ? $Data['error'] : 'HTTP ' . $HttpCode;
            $Description = is_string($Data['error_description'] ?? null) ? $Data['error_description'] : '';
            return $Description !== '' ? $Error . ': ' . $Description : $Error;
        }
    }

    /**
     * Schlanker HTTP-Client (cURL), ohne Symcon-Abhängigkeit
     */
    class Http
    {
        /**
         * Führt eine Anfrage aus.
         *
         * @param  string $Method GET, POST, PUT, DELETE
         * @param  string $Url
         * @param  array<string, string>|string|null $Body Formularfelder (urlencoded) oder fertiger Body
         * @param  list<string> $Headers
         * @param  int $Timeout Sekunden
         * @param  int $StreamMs Long-Poll: nach so vielen Millisekunden abbrechen und das bis dahin Empfangene liefern (0 = aus)
         * @return array{0: int, 1: string, 2: string} HTTP-Code, Body, cURL-Fehler (beim gewollten Abbruch leer)
         */
        public static function Request(string $Method, string $Url, array|string|null $Body = null, array $Headers = [], int $Timeout = 10, int $StreamMs = 0): array
        {
            $ch = curl_init($Url);
            $Options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $Method,
                CURLOPT_TIMEOUT        => $Timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_ENCODING       => '',
                CURLOPT_HTTPHEADER     => $Headers,
            ];
            if (is_array($Body)) {
                $Options[CURLOPT_POSTFIELDS] = http_build_query($Body);
                $Options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
            } elseif (is_string($Body)) {
                $Options[CURLOPT_POSTFIELDS] = $Body;
            }
            if ($StreamMs > 0) {
                // Der Server hält die Verbindung offen: mitschreiben statt auf das Ende warten
                $Received = '';
                $Options[CURLOPT_TIMEOUT_MS] = $StreamMs;
                $Options[CURLOPT_WRITEFUNCTION] = static function ($Handle, string $Data) use (&$Received): int
                {
                    $Received .= $Data;
                    return strlen($Data);
                };
                curl_setopt_array($ch, $Options);
                curl_exec($ch);
                $Error = curl_errno($ch) === CURLE_OPERATION_TIMEDOUT ? '' : curl_error($ch);
                return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $Received, $Error];
            }
            curl_setopt_array($ch, $Options);
            $Response = curl_exec($ch);
            return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), is_string($Response) ? $Response : '', curl_error($ch)];
        }
    }
}
