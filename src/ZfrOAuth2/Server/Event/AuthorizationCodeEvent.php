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

namespace ZfrOAuth2\Server\Event;

use Zend\EventManager\Event;
use Zend\Http\Request as HttpRequest;
use Zend\Http\Response as HttpResponse;
use ZfrOAuth2\Server\Entity\AuthorizationCode;

/**
 * @author  Michaël Gallego <mic.gallego@gmail.com>
 * @licence MIT
 */
class AuthorizationCodeEvent extends Event
{
    const EVENT_CODE_CREATED = 'authorizationCode.created';
    const EVENT_CODE_FAILED  = 'authorizationCode.failed';

    /**
     * @var HttpRequest
     */
    protected $request;

    /**
     * @var array
     */
    protected $responseBody;

    /**
     * @var AuthorizationCode|null
     */
    protected $authorizationCode;

    /**
     * @param HttpRequest            $request
     * @param array                  $responseBody
     * @param AuthorizationCode|null $authorizationCode
     */
    public function __construct(HttpRequest $request, array $responseBody, AuthorizationCode $authorizationCode = null)
    {
        $this->request           = $request;
        $this->responseBody      = $responseBody;
        $this->authorizationCode = $authorizationCode;
    }

    /**
     * @return HttpRequest
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * @param  array $responseBody
     * @return void
     */
    public function setResponseBody(array $responseBody)
    {
        $this->responseBody = $responseBody;
    }

    /**
     * @return array
     */
    public function getResponseBody()
    {
        return $this->responseBody;
    }

    /**
     * @return AuthorizationCode|null
     */
    public function getAuthorizationCode()
    {
        return $this->authorizationCode;
    }
}
