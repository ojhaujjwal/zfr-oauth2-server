<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

namespace ZfrOAuth2\Server;

use Zend\EventManager\EventManagerAwareInterface;
use Zend\EventManager\EventManagerAwareTrait;
use Zend\Http\Request as HttpRequest;
use Zend\Http\Response as HttpResponse;
use ZfrOAuth2\Server\Entity\Client;
use ZfrOAuth2\Server\Entity\TokenOwnerInterface;
use ZfrOAuth2\Server\Event\AuthorizationCodeEvent;
use ZfrOAuth2\Server\Event\TokenEvent;
use ZfrOAuth2\Server\Exception\OAuth2Exception;
use ZfrOAuth2\Server\Grant\AuthorizationServerAwareInterface;
use ZfrOAuth2\Server\Grant\GrantInterface;
use ZfrOAuth2\Server\Service\ClientService;
use ZfrOAuth2\Server\Service\TokenService;

/**
 * The authorization server main role is to create access tokens or refresh tokens
 *
 * @author  Michaël Gallego <mic.gallego@gmail.com>
 * @licence MIT
 */
class AuthorizationServer implements EventManagerAwareInterface
{
    use EventManagerAwareTrait;

    /**
     * @var ClientService
     */
    protected $clientService;

    /**
     * A list of grant
     *
     * @var GrantInterface[]
     */
    protected $grants = [];

    /**
     * A list of grant that can answer to an authorization request
     *
     * @var GrantInterface[]
     */
    protected $responseTypes = [];

    /**
     * @var TokenService
     */
    protected $accessTokenService;

    /**
     * @var TokenService
     */
    protected $refreshTokenService;

    /**
     * @param ClientService    $clientService
     * @param GrantInterface[] $grants
     * @param TokenService     $accessTokenService
     * @param TokenService     $refreshTokenService
     */
    public function __construct(
        ClientService $clientService,
        array $grants,
        TokenService $accessTokenService,
        TokenService $refreshTokenService
    ) {
        $this->clientService       = $clientService;
        $this->accessTokenService  = $accessTokenService;
        $this->refreshTokenService = $refreshTokenService;

        foreach ($grants as $grant) {
            if ($grant instanceof AuthorizationServerAwareInterface) {
                $grant->setAuthorizationServer($this);
            }

            $this->grants[$grant->getType()] = $grant;

            if ($responseType = $grant->getResponseType()) {
                $this->responseTypes[$responseType] = $grant;
            }
        }
    }

    /**
     * Check if the authorization server supports this grant
     *
     * @param  string $grantType
     * @return bool
     */
    public function hasGrant($grantType)
    {
        return isset($this->grants[$grantType]);
    }

    /**
     * Get the grant by its name
     *
     * @param  string $grantType
     * @return GrantInterface
     * @throws OAuth2Exception If grant type is not registered by this authorization server
     */
    public function getGrant($grantType)
    {
        if ($this->hasGrant($grantType)) {
            return $this->grants[$grantType];
        }

        // If we reach here... then no grant was found. Not good!
        throw OAuth2Exception::unsupportedGrantType(sprintf(
            'Grant type "%s" is not supported by this server',
            $grantType
        ));
    }

    /**
     * Check if the authorization server supports this response type
     *
     * @param  string $responseType
     * @return bool
     */
    public function hasResponseType($responseType)
    {
        return isset($this->responseTypes[$responseType]);
    }

    /**
     * Get the response type by its name
     *
     * @param  string $responseType
     * @return GrantInterface
     * @throws Exception\OAuth2Exception
     */
    public function getResponseType($responseType)
    {
        if ($this->hasResponseType($responseType)) {
            return $this->responseTypes[$responseType];
        }

        // If we reach here... then no grant was found. Not good!
        throw OAuth2Exception::unsupportedResponseType(sprintf(
            'Response type "%s" is not supported by this server',
            $responseType
        ));
    }

    /**
     * @param  HttpRequest              $request
     * @param  TokenOwnerInterface|null $owner
     * @return HttpResponse
     * @throws OAuth2Exception If no "response_type" could be found in the GET parameters
     */
    public function handleAuthorizationRequest(HttpRequest $request, TokenOwnerInterface $owner = null)
    {
        try {
            $responseType = $request->getQuery('response_type');

            if (null === $responseType) {
                throw OAuth2Exception::invalidRequest('No grant response type was found in the request');
            }

            $responseType = $this->getResponseType($responseType);
            $client       = $this->getClient($request, $responseType->allowPublicClients());

            $response = $responseType->createAuthorizationResponse($request, $client, $owner);
        } catch (OAuth2Exception $exception) {
            $response = $this->createResponseFromOAuthException($exception);
        }

        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');

        $responseBody = json_decode($response->getContent(), true);

        $event = new AuthorizationCodeEvent($request, $responseBody, $response->getMetadata('authorizationCode'));
        $event->setTarget($this);

        if ($response->isSuccess()) {
            $this->getEventManager()->trigger(AuthorizationCodeEvent::EVENT_CODE_CREATED, $event);
        } else {
            $this->getEventManager()->trigger(AuthorizationCodeEvent::EVENT_CODE_FAILED, $event);
        }

        // We re-encode the content back into the response in case it changed
        $response->setContent(json_encode($event->getResponseBody()));

        return $response;
    }

    /**
     * @param  HttpRequest              $request
     * @param  TokenOwnerInterface|null $owner
     * @return HttpResponse
     * @throws OAuth2Exception If no "grant_type" could be found in the POST parameters
     */
    public function handleTokenRequest(HttpRequest $request, TokenOwnerInterface $owner = null)
    {
        try {
            $grant = $request->getPost('grant_type');

            if (null === $grant) {
                throw OAuth2Exception::invalidRequest('No grant type was found in the request');
            }

            $grant  = $this->getGrant($grant);
            $client = $this->getClient($request, $grant->allowPublicClients());

            $response = $grant->createTokenResponse($request, $client, $owner);
        } catch (OAuth2Exception $exception) {
            $response = $this->createResponseFromOAuthException($exception);
        }

        // According to the spec, we must set those headers (http://tools.ietf.org/html/rfc6749#section-5.1)
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json')
                               ->addHeaderLine('Cache-Control', 'no-store')
                               ->addHeaderLine('Pragma', 'no-cache');

        $responseBody = json_decode($response->getContent(), true);

        $event = new TokenEvent($request, $responseBody, $response->getMetadata('accessToken'));
        $event->setTarget($this);

        if ($response->isSuccess()) {
            $this->getEventManager()->trigger(TokenEvent::EVENT_TOKEN_CREATED, $event);
        } else {
            $this->getEventManager()->trigger(TokenEvent::EVENT_TOKEN_FAILED, $event);
        }

        // We re-encode the content back into the response in case it changed
        $response->setContent(json_encode($event->getResponseBody()));

        return $response;
    }

    /**
     * @param  HttpRequest $request
     * @return HttpResponse
     * @throws OAuth2Exception If no "token" is present
     */
    public function handleRevocationRequest(HttpRequest $request)
    {
        $token     = $request->getPost('token');
        $tokenHint = $request->getPost('token_type_hint');

        if (null === $token || null === $tokenHint) {
            throw OAuth2Exception::invalidRequest(
                'Cannot revoke a token as the "token" and/or "token_type_hint" parameters are missing'
            );
        }

        if ($tokenHint !== 'access_token' && $tokenHint !== 'refresh_token') {
            throw OAuth2Exception::unsupportedTokenType(sprintf(
                'Authorization server does not support revocation of token of type "%s"',
                $tokenHint
            ));
        }

        if ($tokenHint === 'access_token') {
            $token = $this->accessTokenService->getToken($token);
        } else {
            $token = $this->refreshTokenService->getToken($token);
        }

        $response = new HttpResponse();

        // According to spec, we should return 200 if token is invalid
        if (null === $token) {
            return $response;
        }

        // Now, we must validate the client if the token was generated against a non-public client
        if (null !== $token->getClient() && !$token->getClient()->isPublic()) {
            $requestClient = $this->getClient($request, false);

            if ($requestClient !== $token->getClient()) {
                throw OAuth2Exception::invalidClient('Token was issued for another client and cannot be revoked');
            }
        }

        try {
            if ($tokenHint === 'access_token') {
                $this->accessTokenService->deleteToken($token);
            } else {
                $this->refreshTokenService->deleteToken($token);
            }
        } catch (\Exception $exception) {
            // According to spec (https://tools.ietf.org/html/rfc7009#section-2.2.1), we should return a server 503
            // error if we cannot delete the token for any reason
            $response->setStatusCode(503);
        }

        return $response;
    }

    /**
     * Get the client (after authenticating it)
     *
     * According to the spec (http://tools.ietf.org/html/rfc6749#section-2.3), for public clients we do
     * not need to authenticate them
     *
     * @param  HttpRequest $request
     * @param  bool        $allowPublicClients
     * @return Client|null
     * @throws Exception\OAuth2Exception
     */
    protected function getClient(HttpRequest $request, $allowPublicClients)
    {
        list($id, $secret) = $this->extractClientCredentials($request);

        // If the grant type we are issuing does not allow public clients, and that the secret is
        // missing, then we have an error...
        if (!$allowPublicClients && !$secret) {
            throw OAuth2Exception::invalidClient('Client secret is missing');
        }

        // If we allow public clients and no client id was set, we can return null
        if ($allowPublicClients && !$id) {
            return null;
        }

        $client = $this->clientService->getClient($id);

        // We delegate all the checks to the client service
        if (null === $client || (!$allowPublicClients && !$this->clientService->authenticate($client, $secret))) {
            throw OAuth2Exception::invalidClient('Client authentication failed');
        }

        return $client;
    }

    /**
     * Create a response from the exception, using the format of the spec
     *
     * @link   http://tools.ietf.org/html/rfc6749#section-5.2
     * @param  OAuth2Exception $exception
     * @return HttpResponse
     */
    protected function createResponseFromOAuthException(OAuth2Exception $exception)
    {
        $response = new HttpResponse();
        $response->setStatusCode(400);

        $body = ['error' => $exception->getCode(), 'error_description' => $exception->getMessage()];
        $response->setContent(json_encode($body));

        return $response;
    }

    /**
     * Extract the client credentials from Authorization header or POST data
     *
     * @param  HttpRequest $request
     * @return array
     */
    private function extractClientCredentials(HttpRequest $request)
    {
        // We first try to get the Authorization header, as this is the recommended way according to the spec
        if ($header = $request->getHeader('Authorization')) {
            // The value is "Basic xxx", we are interested in the last part
            $parts = explode(' ', $header->getFieldValue());
            $value = base64_decode(end($parts));

            list($id, $secret) = explode(':', $value);
        } else {
            $id     = $request->getPost('client_id');
            $secret = $request->getPost('client_secret');
        }

        return [$id, $secret];
    }
}
