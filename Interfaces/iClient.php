<?php
namespace Poirot\ApiClient\Interfaces;

use Poirot\ApiClient\Interfaces\Request\iApiCommand;
use Poirot\ApiClient\Interfaces\Response\iResponse;


interface iClient
{
    /**
     * Execute Request
     *
     * - prepare/validate transporter with platform
     * - build expression via method/params with platform
     * - send expression as request with transporter
     *    . build response with platform
     * - return response
     *
     * @param iApiCommand $command Server Exec Method
     *
     * @throws \Exception
     *
     * throws Exception when $method Object is null
     *
     * @return iResponse
     */
    function call(iApiCommand $command);

    /**
     * Get Client Platform
     *
     * - used by request to build params for
     *   server execution call and response
     *
     * @return iPlatform
     */
    function platform();
}
