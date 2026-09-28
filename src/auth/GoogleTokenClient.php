<?php

namespace enovate\socialstream\auth;

use Craft;
use enovate\socialstream\SocialStream;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Talks to Google's OAuth 2.0 endpoints.
 *
 * Deliberately knows nothing about connections, encryption or the YouTube Data
 * API — it exchanges and refreshes credentials and reports what Google said.
 * {@see \enovate\socialstream\services\TokenService} owns storage, and the
 * channel lookup lives in {@see \enovate\socialstream\providers\YouTubeProvider}
 * alongside the rest of the Data API calls.
 */
class GoogleTokenClient
{
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * Reading channel info and listing videos needs nothing more than this.
     */
    public const SCOPE = 'https://www.googleapis.com/auth/youtube.readonly';

    /**
     * Google's error for a refresh token it will not honour — revoked, expired
     * (7 days, for an app still in Testing mode), or issued to another client.
     * Only re-authorisation clears it.
     */
    public const ERROR_INVALID_GRANT = 'invalid_grant';

    /**
     * Exchange an authorisation code for an access token and refresh token.
     *
     * @return array{success: bool, error: string|null, invalidGrant: bool, accessToken: string|null, refreshToken: string|null, expiresIn: int|null}
     */
    public function exchangeCode(
        string $code,
        string $clientId,
        string $clientSecret,
        string $redirectUri,
    ): array {
        return $this->post([
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);
    }

    /**
     * Trade a refresh token for a fresh access token.
     *
     * Google does not return a new refresh token on this grant — the existing one
     * stays valid, so callers must not overwrite it with the null returned here.
     *
     * @return array{success: bool, error: string|null, invalidGrant: bool, accessToken: string|null, refreshToken: string|null, expiresIn: int|null}
     */
    public function refresh(string $refreshToken, string $clientId, string $clientSecret): array
    {
        return $this->post([
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'refresh_token',
        ]);
    }

    /**
     * Build the URL that sends an admin to Google's consent screen.
     *
     * `access_type=offline` is what makes Google issue a refresh token at all, and
     * `prompt=consent` forces the consent screen every time so re-authorising
     * always yields a fresh one rather than silently reusing a revoked grant.
     */
    public function authorisationUrl(string $clientId, string $redirectUri, string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * @param array<string, string> $params
     * @return array{success: bool, error: string|null, invalidGrant: bool, accessToken: string|null, refreshToken: string|null, expiresIn: int|null}
     */
    private function post(array $params): array
    {
        try {
            $client = Craft::createGuzzleClient();
            $response = $client->post(self::TOKEN_URL, ['form_params' => $params]);
            $data = json_decode($response->getBody()->getContents(), true);

            if (!is_array($data) || empty($data['access_token'])) {
                return $this->failure('Google returned no access token.');
            }

            return [
                'success' => true,
                'error' => null,
                'invalidGrant' => false,
                'accessToken' => $data['access_token'],
                'refreshToken' => $data['refresh_token'] ?? null,
                'expiresIn' => isset($data['expires_in']) ? (int) $data['expires_in'] : null,
            ];
        } catch (ClientException $e) {
            $body = json_decode($e->getResponse()->getBody()->getContents(), true);
            $code = is_array($body) ? ($body['error'] ?? null) : null;
            $description = is_array($body) ? ($body['error_description'] ?? null) : null;

            return $this->failure(
                'Google rejected the request: ' . ($description ?? $code ?? $e->getMessage()),
                $code === self::ERROR_INVALID_GRANT,
            );
        } catch (GuzzleException $e) {
            return $this->failure('Could not reach Google: ' . $e->getMessage());
        }
    }

    /**
     * @return array{success: bool, error: string|null, invalidGrant: bool, accessToken: null, refreshToken: null, expiresIn: null}
     */
    private function failure(string $error, bool $invalidGrant = false): array
    {
        SocialStream::warning($error);

        return [
            'success' => false,
            'error' => $error,
            'invalidGrant' => $invalidGrant,
            'accessToken' => null,
            'refreshToken' => null,
            'expiresIn' => null,
        ];
    }
}
