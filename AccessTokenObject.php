<?php
namespace Poirot\ApiClient;

use Poirot\Std\Struct\DataOptionsOpen;
use Poirot\ApiClient\Interfaces\Token\iAccessTokenObject;


class AccessTokenObject
    extends DataOptionsOpen
    implements iAccessTokenObject
{
    protected $accessToken;
    protected $refreshToken;
    protected $tokenType;
    protected $clientId;
    protected $expiresIn;
    protected $scopes;

    protected $datetimeExpiration;


    /**
     * Unique Token Identifier
     * @required
     *
     * @return string|int
     */
    function getAccessToken()
    {
        return $this->accessToken;
    }

    /**
     * Set Token Identifier
     * 
     * @param string|int $accessToken
     * 
     * @return $this
     */
    function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
        return $this;
    }

    /**
     * Client Identifier That Token Issued To
     *
     * @return string|int
     */
    function getClientId()
    {
        return $this->clientId;
    }

    /**
     * Set Client Identifier That Token Issued To
     * 
     * @param string|int $clientId
     * 
     * @return $this
     */
    function setClientId($clientId)
    {
        $this->clientId = $clientId;
        return $this;
    }

    /**
     * //TODO Datetime instead of DateTime
     * Get the token's expiry date time
     *
     * @return \DateTime
     */
    function getDateTimeExpiration()
    {
        if (! $this->datetimeExpiration ) {
            if ($this->expiresIn) {
                // Maybe not include this field
                $exprDateTime = __( new \DateTime() )
                    ->add( new \DateInterval(sprintf('PT%sS', $this->expiresIn)) );

                $this->datetimeExpiration = $exprDateTime;
            }

        }


        return $this->datetimeExpiration;
    }

    /**
     * Set the token's expiry date time
     *
     * @param \DateTime $expiration
     *
     * @return $this
     */
    function setDatetimeExpiration(\DateTime $expiration)
    {
        $this->datetimeExpiration = $expiration;
        return $this;
    }

    /**
     * Set Expiry DateTime
     * 
     * @param \DateTime $expiry
     * 
     * @return $this
     */
    function setExpiresIn($expiry)
    {
        $this->expiresIn = $expiry;
        $this->datetimeExpiration = $this->getDateTimeExpiration();
        return $this;
    }

    /**
     * Get Issued Scopes
     * 
     * @return string[]
     */
    function getScopes()
    {
        return $this->scopes;
    }

    /**
     * Set Issued Scopes
     * 
     * @param string[] $scopes
     * 
     * @return $this
     */
    function setScopes($scopes)
    {
        $this->scopes = $scopes;
        return $this;
    }

    /**
     * Note: oauth server return scopes in "scope" field in response
     * {
     *   "access_token": "{\"resource_owner\":null,\"meta\":[]}",
     *   "scope": "profile",
     *   ..
     *
     */
    function setScope($scope)
    {
        $this->setScopes($scope);
        return $this;
    }

    /**
     * @return mixed
     */
    function getRefreshToken()
    {
        return $this->refreshToken;
    }

    /**
     * @param mixed $refreshToken
     */
    function setRefreshToken($refreshToken)
    {
        $this->refreshToken = $refreshToken;
    }

    /**
     * @return mixed
     */
    function getTokenType()
    {
        return $this->tokenType;
    }

    /**
     * @param mixed $tokenType
     */
    function setTokenType($tokenType)
    {
        $this->tokenType = $tokenType;
    }
}
