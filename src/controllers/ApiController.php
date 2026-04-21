<?php

namespace enovate\socialstream\controllers;

use Craft;
use craft\web\Controller;
use enovate\socialstream\models\Post;
use enovate\socialstream\records\SettingsRecord;
use enovate\socialstream\SocialStream;
use yii\web\Response;

/**
 * Optional JSON API endpoint for external consumers.
 *
 * Disabled by default. When enabled, requires Bearer token authentication.
 * Accepts the same parameters as the Twig variable and returns an identical
 * response contract.
 */
class ApiController extends Controller
{
    protected array|int|bool $allowAnonymous = ['index'];

    public bool $enableCsrfValidation = false;

    /**
     * GET /actions/social-stream/api
     */
    public function actionIndex(): Response
    {
        $request = Craft::$app->request;
        $siteId = (int) ($request->getQueryParam('siteId') ?? Craft::$app->sites->currentSite->id);

        $settingsRecord = SettingsRecord::findOne(['siteId' => $siteId]);
        if ($settingsRecord === null || !$settingsRecord->secureApiEndpoint) {
            $this->response->setStatusCode(404);
            return $this->asJson([
                'success' => false,
                'error' => 'API endpoint is not enabled.',
            ]);
        }

        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            $this->response->setStatusCode(401);
            return $this->asJson([
                'success' => false,
                'error' => 'Missing or invalid Authorization header. Expected: Bearer {token}',
            ]);
        }

        $providedToken = substr($authHeader, 7);
        $storedToken = SocialStream::$plugin->token->decrypt($settingsRecord->apiToken);

        if ($storedToken === null || !hash_equals($storedToken, $providedToken)) {
            $this->response->setStatusCode(401);
            return $this->asJson([
                'success' => false,
                'error' => 'Invalid API token.',
            ]);
        }

        $provider = $request->getQueryParam('provider');
        if ($provider === null || $provider === '') {
            $this->response->setStatusCode(400);
            return $this->asJson([
                'success' => false,
                'error' => "Missing required query param: 'provider' (e.g. 'instagram').",
            ]);
        }

        $options = [
            'siteId' => $siteId,
            'provider' => $provider,
        ];

        $limit = $request->getQueryParam('limit');
        if ($limit !== null) {
            $options['limit'] = (int) $limit;
        }

        $mediaType = $request->getQueryParam('mediaType');
        if ($mediaType !== null) {
            $options['mediaType'] = $mediaType;
        }

        $excludeNonFeed = $request->getQueryParam('excludeNonFeed');
        if ($excludeNonFeed !== null) {
            $options['excludeNonFeed'] = filter_var($excludeNonFeed, FILTER_VALIDATE_BOOLEAN);
        }

        $after = $request->getQueryParam('after');
        if ($after !== null) {
            $options['after'] = $after;
        }

        $variable = new \enovate\socialstream\variables\SocialStreamVariable();
        $response = $variable->getStream($options);

        if (!empty($response['data'])) {
            $response['data'] = array_map(
                fn($post) => $post instanceof Post ? $post->toArray() : $post,
                $response['data'],
            );
        }

        return $this->asJson($response);
    }
}
