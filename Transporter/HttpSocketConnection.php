<?php
namespace Poirot\ApiClient\Transporter;

use Poirot\ApiClient\AbstractTransporter;
use Poirot\ApiClient\Exception\ApiCallException;
use Poirot\Core\OpenCall;
use Poirot\Core\Traits\CloneTrait;
use Poirot\Stream\Interfaces\iStreamable;
use Poirot\Stream\Streamable;
use Poirot\Stream\StreamClient;

/*
$httpRequest = new HttpRequest([
    'uri' => '/payam/',
    'headers' => [
        'Host'            => '95.211.189.240',
        'Accept-Encoding' => 'gzip',
        'Cache-Control'   => 'no-cache',
    ]
]);

$stream = new HttpSocketConnection(['server_url' => 'http://95.211.189.240/']);
$startTime = microtime(true);
$res = $stream->send($httpRequest->toString());
printf("HttpSocket: %f<br/>", microtime(true) - $startTime);

$body = $res->body;
$body->getResource()->appendFilter(new PhpRegisteredFilter('zlib.inflate'), STREAM_FILTER_READ);
### skip the first 10 bytes for zlib
$body = new SegmentWrapStream($body, -1, 10);
echo $body->read();
*/

class HttpSocketConnection extends AbstractTransporter
{
    use CloneTrait;

    /** @var Streamable When Connected */
    protected $streamable;

    /**
     * the options will not changed when connected
     * @var HttpSocketOptions
     */
    protected $connected_options;

    /** @var bool  */
    protected $lastReceive = false;

    /**
     * Write Received Server Data To It Until Complete
     * @var Streamable\TemporaryStream */
    protected $_buffer;
    protected $_buffer_seek = 0;

    # events
    protected $_on__request_send_prepare;
    protected $_on__response_header_received;
    protected $_on__response_received;

    /** @var \StdClass (object) ['headers'=> .., 'body'=>stream_offset] latest request expression to receive on events */
    protected $_tmp_expr;


    // Events:

    /**
     * Prepare Expression Before Send
     *
     * - the closure functions will bind to this object
     *
     * closure:
     * mixed function($expression) {
     *   // $this will point to HttpSocketConnection (current-class)
     * }
     *
     *
     * @return OpenCall
     */
    function onRequestSendPrepare()
    {
        if (!$this->_on__request_send_prepare)
            $this->_on__request_send_prepare = new OpenCall($this);

        return $this->_on__request_send_prepare;
    }

    /**
     * Header Response Received
     *
     * - the closure functions will bind to this object
     *
     * closure:
     * $continue (object) ['isDone' => false] ## is done true cause not given body part
     * mixed function($headers, $expression, $continue) {
     *   // $this will point to HttpSocketConnection (current-class)
     * }
     *
     *
     * @return OpenCall
     */
    function onResponseHeaderReceived()
    {
        if (!$this->_on__response_header_received)
            $this->_on__response_header_received = new OpenCall($this);

        return $this->_on__response_header_received;
    }

    /**
     * Response Fully Received
     *
     * - the closure functions will bind to this object
     *
     * closure:
     * mixed function($headers, $body, $expression) {
     *   // $this will point to HttpSocketConnection (current-class)
     * }
     *
     *
     * @return OpenCall
     */
    function onResponseReceived()
    {
        if (!$this->_on__response_received)
            $this->_on__response_received = new OpenCall($this);

        return $this->_on__response_received;
    }

    /**
     * TODO ssl connection
     *
     * Get Prepared Resource Transporter
     *
     * - prepare resource with options
     *
     * @throws \Exception
     * @return mixed Transporter Resource
     */
    function getConnect()
    {
        if ($this->isConnected())
            ## close current transporter if connected
            $this->close();


        # apply options to resource

        ## options will not take an affect after connect
        $this->connected_options = clone $this->inOptions();

        ## determine protocol

        if (!$serverUrl = $this->inOptions()->getServerUrl())
            throw new \RuntimeException('Server Url is Mandatory For Connect.');

        $parsedServerUrl = parse_url($serverUrl);
        $parsedServerUrl['scheme'] = 'tcp';
        (isset($parsedServerUrl['port'])) ?: $parsedServerUrl['port'] = 80;
        $serverUrl = $this->__unparse_url($parsedServerUrl);

        $stream = new StreamClient(
            \Poirot\Core\array_merge(
                $this->inOptions()->toArray()
                , ['socket_uri' => $serverUrl]
            )
        );

        ### options
        $stream->setTimeout($this->inOptions()->getTimeout());
        $stream->setPersist($this->inOptions()->isPersist());

        try{
            $resource = $stream->getConnect();
        } catch(\Exception $e)
        {
            throw new \Exception(sprintf(
                'Cannot connect to (%s).'
                , $serverUrl
                , $e->getCode()
                , $e ## as previous exception
            ));
        }

        $this->streamable = new Streamable($resource);
        return $this->streamable;
    }

        protected function __unparse_url($parsed_url) {
            $scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
            $host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
            $port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
            $user     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
            $pass     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
            $pass     = ($user || $pass) ? "$pass@" : '';
            $path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
            $query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
            $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';
            return "$scheme$user$pass$host$port$path$query$fragment";
        }

    /**
     * TODO make request and response are too slow
     *
     * Send Expression To Server
     *
     * - send expression to server through transporter
     *   resource
     * - get connect if transporter not stablished yet
     *
     * @param string|iStreamable $expr Expression
     *
     * @throws ApiCallException
     * @return string Response
     */
    function send($expr)
    {
//        $start = microtime(true);

        # prepare new request
        $this->lastReceive = null;

        ## destruct buffer
        $this->_getBufferStream()->getResource()->close();
        $this->_buffer = null;

        # get connect if not
        if (
            !$this->isConnected()
            || !$this->streamable->getResource()->isAlive()
        )
            $this->getConnect();


        # write stream
        try
        {
            // Fire up registered methods to prepare expression
            foreach($this->onRequestSendPrepare()->listMethods() as $method)
                $expr = call_user_func([$this->onRequestSendPrepare(), $method], $expr);

            $response = $this->doHandleRequest($expr);
        } catch (\Exception $e) {
            throw new ApiCallException(sprintf(
                'Request Call Error When Send To Server (%s)'
                , $this->streamable->getResource()->getRemoteName()
            ), 0, 1, __FILE__, __LINE__, $e);
        }

//        printf("get connect: %f<br/>", microtime(true) - $start);

        $this->lastReceive = $response;
        return $response;
    }

        /**
         * Send Request To Server And Receive Response
         *
         * @param iStreamable|string|mixed $expr
         *
         * @throws \Exception
         * @return string
         */
        protected function doHandleRequest($expr)
        {
            if (is_object($expr) && !$expr instanceof iStreamable)
                $expr = (string) $expr;

            if (is_string($expr))
                $expr = (new Streamable\TemporaryStream($expr))->rewind();

            if (!$expr instanceof iStreamable)
                throw new \InvalidArgumentException(sprintf(
                    'Http Expression must instance of iHttpRequest, RequestInterface or string. given: "%s".'
                    , \Poirot\Core\flatten($expr)
                ));

            # send request
            $headers = $this->__readHeadersFromStream($expr);
            $this->_tmp_expr = (object) ['headers' => $headers, 'body'=> $expr->getCurrOffset()];

            $this->streamable->write($headers);
            $expr->pipeTo($this->streamable);

            # receive rest response body
            $response = $this->receive();
            return $response;
        }

    /**
     * Receive Server Response
     *
     * !! return response object if request completely sent
     *
     * - it will executed after a request call to server
     *   from send expression method to receive responses
     * - return null if request not sent or complete
     * - it must always return raw response body from server
     *
     * @throws \Exception No Transporter established
     * @return null|string|Streamable
     */
    function receive()
    {
        if ($this->lastReceive)
            return $this->lastReceive;

        ## so we can read later from latest position to end
        ## in example when we write header we can retrieve header next time
        $curSeek = $this->_buffer_seek;

        $stream = $this->streamable;

        if ($stream->getResource()->meta()->isTimedOut())
            throw new \RuntimeException(
                "Read timed out after {$this->inOptions()->getTimeout()} seconds."
            );

        # read headers:
        $headers = $this->__readHeadersFromStream($stream);

        if (empty($headers))
            throw new \Exception('Server not respond to this request.');

        // Fire up registered methods to prepare expression
        $response = $headers;
        $body     = null;

        $continue = (object) ['isDone' => false];
        foreach($this->onResponseHeaderReceived()->listMethods() as $method)
            $response = call_user_func([$this->onResponseHeaderReceived(), $method], $response, $this->_tmp_expr, $continue);

        if ($continue->isDone)
            // terminate and return response
            goto finalize;

        # read body:
        while(!$stream->isEOF()) {
            $body .= $stream->read(1024);

            $this->_getBufferStream()->seek($this->_buffer_seek);
            $this->_getBufferStream()->write($body);
            $this->_buffer_seek += $this->_getBufferStream()->getTransCount();
        }
        $body = $this->_getBufferStream()->seek($curSeek);

finalize:

        foreach($this->onResponseReceived()->listMethods() as $method)
            $response = call_user_func([$this->onResponseReceived(), $method], $response, $body, $this->_tmp_expr);

        return $response;
    }

        protected function __readHeadersFromStream(iStreamable $stream)
        {
            $headers = '';
            while(!$stream->isEOF() && ($line = $stream->readLine("\r\n")) !== null ) {
                $break = false;
                $headers .= $line."\r\n";
                if (trim($line) === '') {
                    ## http headers part read complete
                    $break = true;
                }

                if ($break) break;
            }

            return $headers;
        }

        protected function _getBufferStream()
        {
            if (!$this->_buffer) {
                $this->_buffer = new Streamable\TemporaryStream();
                $this->_buffer_seek = 0;
            }

            return $this->_buffer;
        }

    /**
     * Is Transporter Resource Available?
     *
     * @return bool
     */
    function isConnected()
    {
        return ($this->streamable !== null && $this->streamable->getResource()->isAlive());
    }

    /**
     * Close Transporter
     * @return void
     */
    function close()
    {
        if (!$this->isConnected())
            return;

        $this->streamable->getResource()->close();
        $this->streamable = null;
        $this->connected_options = null;
    }

    // options

    /**
     * @override just for ide completion
     * @return HttpSocketOptions
     */
    function inOptions()
    {
        if ($this->isConnected())
            ## the options will not changed when connected
            return $this->connected_options;

        return parent::inOptions();
    }

    /**
     * @override
     * @return HttpSocketOptions
     */
    static function newOptions()
    {
        return new HttpSocketOptions;
    }
}
