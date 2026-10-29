<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../libs/GoogleOAuth.php';

/**
 * Antworten von Google nach https://developers.google.com/identity/protocols/oauth2/limited-input-device
 * und aus dem Live-Test vom 2026-10-29 (Werte gekürzt bzw. ersetzt).
 */
class DeviceFlowTest extends TestCase
{
    public function testDeviceCode(): void
    {
        $Body = '{"device_code":"AH-1Ng3x","user_code":"FWN-TRG-YYWM","expires_in":1800,"interval":5,"verification_url":"https://www.google.com/device"}';
        $Result = \YouTubeMusic\DeviceFlow::ParseDeviceCode(200, $Body);
        $this->assertSame('FWN-TRG-YYWM', $Result['user_code']);
        $this->assertSame('https://www.google.com/device', $Result['verification_url']);
        $this->assertSame(5, $Result['interval']);
        $this->assertSame(1800, $Result['expires_in']);
    }

    public function testDeviceCodeInvalidScope(): void
    {
        $Body = '{"error":"invalid_scope","error_description":"Invalid device flow scope: https://www.googleapis.com/auth/youtube.force-ssl"}';
        $Result = \YouTubeMusic\DeviceFlow::ParseDeviceCode(400, $Body);
        $this->assertSame('invalid_scope: Invalid device flow scope: https://www.googleapis.com/auth/youtube.force-ssl', $Result['error']);
    }

    public function testTokenPending(): void
    {
        $Result = \YouTubeMusic\DeviceFlow::ParseTokenResponse(428, '{"error":"authorization_pending","error_description":"Precondition Required"}');
        $this->assertSame(\YouTubeMusic\TokenResult::Pending, $Result['result']);
    }

    public function testTokenSlowDownDeniedExpired(): void
    {
        $this->assertSame(\YouTubeMusic\TokenResult::SlowDown, \YouTubeMusic\DeviceFlow::ParseTokenResponse(403, '{"error":"slow_down"}')['result']);
        $this->assertSame(\YouTubeMusic\TokenResult::Denied, \YouTubeMusic\DeviceFlow::ParseTokenResponse(403, '{"error":"access_denied"}')['result']);
        $this->assertSame(\YouTubeMusic\TokenResult::Expired, \YouTubeMusic\DeviceFlow::ParseTokenResponse(400, '{"error":"expired_token"}')['result']);
        $this->assertSame(\YouTubeMusic\TokenResult::InvalidGrant, \YouTubeMusic\DeviceFlow::ParseTokenResponse(400, '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}')['result']);
    }

    public function testTokenOk(): void
    {
        $Body = '{"access_token":"ya29.a0","expires_in":3599,"refresh_token":"1//0g","scope":"https://www.googleapis.com/auth/youtube","token_type":"Bearer"}';
        $Result = \YouTubeMusic\DeviceFlow::ParseTokenResponse(200, $Body);
        $this->assertSame(\YouTubeMusic\TokenResult::Ok, $Result['result']);
        $this->assertSame('ya29.a0', $Result['access_token']);
        $this->assertSame('1//0g', $Result['refresh_token']);
        $this->assertSame(3599, $Result['expires_in']);
    }

    public function testRefreshWithoutNewRefreshToken(): void
    {
        $Result = \YouTubeMusic\DeviceFlow::ParseTokenResponse(200, '{"access_token":"ya29.b0","expires_in":3599,"token_type":"Bearer"}');
        $this->assertSame(\YouTubeMusic\TokenResult::Ok, $Result['result']);
        $this->assertArrayNotHasKey('refresh_token', $Result);
    }

    public function testNoJson(): void
    {
        $Result = \YouTubeMusic\DeviceFlow::ParseTokenResponse(502, '<html>Bad Gateway</html>');
        $this->assertSame(\YouTubeMusic\TokenResult::Error, $Result['result']);
        $this->assertSame('HTTP 502', $Result['error']);
    }

    public function testRequests(): void
    {
        $this->assertSame(['client_id' => 'id', 'scope' => \YouTubeMusic\Google::Scope], \YouTubeMusic\DeviceFlow::DeviceCodeRequest('id'));
        $this->assertSame(\YouTubeMusic\Google::DeviceGrant, \YouTubeMusic\DeviceFlow::TokenPollRequest('id', 'secret', 'code')['grant_type']);
        $this->assertSame('refresh_token', \YouTubeMusic\DeviceFlow::RefreshRequest('id', 'secret', 'rt')['grant_type']);
    }
}
