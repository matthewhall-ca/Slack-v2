<?php

namespace SocialiteProviders\Slack;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;
use SocialiteProviders\Manager\OAuth2\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User;

class Provider extends AbstractProvider
{
    /**
     * Unique Provider Identifier.
     */
    public const IDENTIFIER = 'SLACK';

    /**
     * {@inheritdoc}
     */
    public function getScopes()
    {
        // No scopes is valid with Slack
        return [];
    }

    /**
     * Middleware that throws exceptions for non successful slack api calls
     * "http_error" request option is set to true.
     *
     * @return callable Returns a function that accepts the next handler.
     */
    private function getSlackApiErrorMiddleware()
    {
        return function (callable $handler) {
            return function ($request, array $options) use ($handler) {
                if (empty($options['http_errors'])) {
                    return $handler($request, $options);
                }

                return $handler($request, $options)->then(
                    function (ResponseInterface $response) use ($request) {
                        $body = json_decode($response->getBody()->getContents(), true);
                        $response->getBody()->rewind();

                        if ($body['ok']) {
                            return $response;
                        }

                        throw RequestException::create($request, $response);
                    }
                );
            };
        };
    }

    /**
     * {@inheritdoc}
     */
    protected function getHttpClient()
    {
        $handler = HandlerStack::create();
        $handler->push($this->getSlackApiErrorMiddleware(), 'slack_api_errors');

        if (is_null($this->httpClient)) {
            $this->httpClient = new Client(['handler' => $handler]);
        }

        return $this->httpClient;
    }

    /**
     * {@inheritdoc}
     */
    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase(
            'https://slack.com/oauth/v2/authorize',
            $state
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenUrl()
    {
        return 'https://slack.com/api/oauth.v2.access';
    }

    /**
     * {@inheritdoc}
     */
    protected function getUserByToken($token)
    {
        try {
            $response = $this->getHttpClient()->get(
                'https://slack.com/api/users.identity',
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                    ],
                ]
            );
        } catch (RequestException $exception) {
            // Getting user informations requires the "identity.*" scopes, however we might want to not add them to the
            // scope list for various reasons. Instead of throwing an exception on this error, we return an empty user.

            if ($exception->hasResponse()) {
                $data = json_decode($exception->getResponse()->getBody()->getContents(), true);

                if (Arr::get($data, 'error') === 'missing_scope') {
                    return [];
                }
            }

            throw $exception;
        }

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * {@inheritdoc}
     */
    protected function mapUserToObject(array $user)
    {
        return (new User())->setRaw($user)->map([
            'id'              => Arr::get($user, 'user.id'),
            'name'            => Arr::get($user, 'user.name'),
            'email'           => Arr::get($user, 'user.email'),
            'avatar'          => Arr::get($user, 'user.image_192'),
            'organization_id' => Arr::get($user, 'team.id'),
        ]);
    }
    
    /**
     * Get the access token from the token response body.
     *
     * @param array $body
     *
     * @return string
     */
    protected function parseAccessToken($body)
    {
        return Arr::get($body, 'authed_user.access_token');
    }
}
