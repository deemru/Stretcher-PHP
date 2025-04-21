<?php declare( strict_types = 1 );

require_once __DIR__ . '/vendor/autoload.php';

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Promise\Deferred;
use React\Socket\SocketServer;

$version = 'PHP';

$listen = '127.0.0.1:8080';
$upstream = '127.0.0.1:80';
$timeout = 12;
$ttwindow = 12;
$cctarget = 4;
$concurrency = 64;
$maxbytes = 65536;
$debug = true;

$options = getopt( '',
[
    'listen::',
    'upstream::',
    'timeout::',
    'window::',
    'target::',
    'concurrency::',
    'maxbytes::',
    'debug::'
] );

if( isset( $options['listen'] ) )
    $listen = $options['listen'];

if( isset( $options['upstream'] ) )
    $upstream = $options['upstream'];

if( isset( $options['timeout'] ) )
    $timeout = (int)$options['timeout'];

if( isset( $options['window'] ) )
    $ttwindow = (float)$options['window'];

if( isset( $options['target'] ) )
    $cctarget = (float)$options['target'];

if( isset( $options['concurrency'] ) )
    $concurrency = (int)$options['concurrency'];

if( isset( $options['maxbytes'] ) )
    $maxbytes = (int)$options['maxbytes'];

if( isset( $options['debug'] ) )
    $debug = (bool)$options['debug'];

$log = new \Monolog\Logger( 'Stretcher' );
$formatter = new \Monolog\Formatter\LineFormatter( "%datetime% %message%\n", 'Y.m.d H:i:s' );
$stream = new \Monolog\Handler\StreamHandler( 'php://stdout', \Monolog\Level::Debug );
$stream->setFormatter( $formatter );
$log->pushHandler( $stream );

$log->info( sprintf( 'Stretcher (%s)', $version ) );
$log->info( sprintf( 'Stretching %s -> %s (timeout: %d, window: %.0f, target: %.0f, concurrency: %d, maxbytes: %d, debug: %s)',
    $listen,
    $upstream,
    $timeout,
    $ttwindow,
    $cctarget,
    $concurrency,
    $maxbytes,
    $debug ? 'true' : 'false'
) );

class RequestEvent
{
    public $req;
    public $deferred;
    public $method;
    public $uri;
    public $headers;
    public $body;

    public function __construct( $req, $method, $uri, $headers, $body )
    {
        $this->req = $req;
        $this->deferred = false;
        $this->method = $method;
        $this->uri = $uri;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function tie( $deferred )
    {
        $this->deferred = $deferred;
    }

    public function untie()
    {
        $this->deferred = false;
    }

    public function complete( $response )
    {
        if( $this->deferred !== false )
        {
            $this->deferred->resolve( $response );
            $this->deferred = false;
        }
    }

    public function active()
    {
        return $this->deferred !== false;
    }
}

class IPState
{
    public $queue = [];
    public $queued = 0;
    public $ttlast = 0;
    public $cclast = 0;
    public $ddlast = 0;
    public $ip;

    public function __construct($ip)
    {
        $this->ip = $ip;
    }
}

class Stretcher
{
    private $ipStates = [];

    private $log;
    private $ttwindow;
    private $cctarget;
    private $concurrency;
    private $timeout;
    private $maxbytes;
    private $debug;

    private $quant;
    private $loop;

    private $responseHeaders;
    private $host;
    private $hostUri;
    private $receiver;

    private HttpServer $http;
    private SocketServer $socket;

    private Response $responseNotAllowed;
    private Response $responseTooMany;
    private Response $responseUnavailable;
    private Response $responseNotActive;

    function processRequest( $state, $event )
    {
        $ttaction = microtime( true );
        if( !$event->active() )
            return $this->completeRequest( $state, $event, $ttaction, $this->responseNotActive );

        $this->receiver->request( $event->method, $event->uri, $event->headers, $event->body )->then(
            function( ResponseInterface $response ) use ( $event, $ttaction, $state )
            {
                $this->completeRequest( $state, $event, $ttaction, $response );
            },
            function( Exception $e ) use ( $event, $ttaction, $state )
            {
                $code = $e->getCode();
                $this->log->error( $code . ': ' . $e->getMessage() . ': ' . substr( $event->uri, strlen( $this->hostUri ) + 1 ) );
                $response = ( $code >= 400 && $code < 600 )
                    ? $this->response( $code )
                    : $this->responseUnavailable;
                $this->completeRequest( $state, $event, $ttaction, $response );
            }
        );
    }

    function completeRequest( $state, $event, $ttaction, $response )
    {
        $ttnow = microtime( true );
        $cc = $ttnow - $ttaction;

        $ttdiff = $ttnow - $state->ttlast;
        if( $ttdiff < $this->ttwindow )
        {
            $fading = 1 - ( $ttdiff / $this->ttwindow );
            $state->cclast *= $fading;
            $state->ddlast *= $fading;
        }
        else
        {
            $state->cclast = 0;
            $state->ddlast = 0;
        }

        $state->cclast += $cc;
        $state->ttlast = $ttnow;

        if( $this->debug )
        {
            $uri = $this->formatURI( $event->uri, $event->method, $event->body );
            $statusCode = $response->getStatusCode();
            $this->logRequestComplete( $state, $uri, $cc, $statusCode );
        }

        array_shift( $state->queue );
        $state->queued--;
        $event->complete( $response );
        $this->processNextInQueue( $state );
    }

    function processNextInQueue( $state )
    {
        $event = reset( $state->queue );
        if( $event === false )
            return;

        if( !$event->active() )
            return $this->completeRequest( $state, $event, microtime( true ), $this->responseNotActive );

        $ttnow = microtime( true );
        $ttdiff = $ttnow - $state->ttlast;
        if( $ttdiff < $this->ttwindow )
        {
            $fading = 1 - ( $ttdiff / $this->ttwindow );
            $state->cclast *= $fading;
            $state->ddlast *= $fading;
            $target = $state->cclast * $this->quant - $state->cclast;

            if( $ttdiff > $target )
                $delay = 0;
            else
            {
                $delay = $target - $ttdiff;
                if( $delay > $this->ttwindow )
                    $delay = $this->ttwindow;
            }

            $delay = ( $state->ddlast + $delay ) / 2;
            $state->ddlast = $delay;
        }
        else
        {
            $delay = 0;
            $state->cclast = 0;
            $state->ddlast = 0;
        }

        $state->ttlast = $ttnow;

        if( $delay > 0.001 )
        {
            $this->loop->addTimer( $delay, function() use ( $state, $event )
            {
                $this->processRequest( $state, $event );
            } );
        }
        else
        {
            $this->processRequest( $state, $event );
        }
    }

    function enqueueHandler( ServerRequestInterface $request )
    {
        $method = $request->getMethod();
        switch( $method )
        {
            case 'GET':
            case 'OPTIONS':
            case 'POST':
                break;
            default:
                return $this->responseNotAllowed;
        }

        $uri = $request->getUri();
        $query = $uri->getQuery();
        $targetUri = $this->hostUri . $uri->getPath() . ( $query !== '' ? ( '?' . $query ) : '' );

        $headers = $request->getHeaders();
        $headers['Host'] = $this->host;

        $body = $request->getBody();

        $clientIP = $headers['Cf-Connecting-Ip'][0] ?? $headers['X-Forwarded-For'][0] ?? $request->getServerParams()['REMOTE_ADDR'];

        if( !isset( $this->ipStates[$clientIP] ) )
        {
            $state = new IPState( $clientIP );
            $this->ipStates[$clientIP] = $state;
        }
        else
        {
            $state = $this->ipStates[$clientIP];
        }

        if( count( $state->queue ) >= $this->concurrency )
            return $this->responseTooMany;

        $event = new RequestEvent(
            $request,
            $method,
            $targetUri,
            $headers,
            $body
        );

        $deferred = new Deferred( function() use ( $event )
        {
            $event->untie();
        } );
        $event->tie( $deferred );

        array_push( $state->queue, $event );
        $state->queued++;
        if( $state->queued === 1 )
            $this->processNextInQueue( $state );

        return $deferred->promise();
    }

    function response( $code )
    {
        return new Response( $code, $this->responseHeaders, '' );
    }

    function formatURI( $uri, $method, $body )
    {
        $formattedUri = substr( $uri, strlen( $this->hostUri ) );

        if( $method === 'POST' )
        {
            if( $formattedUri !== '/' )
                $formattedUri .= ' (POST)';
            else
            {
                $json = @json_decode( (string)$body, true );
                $jsonMethod = $json['method'] ?? false;
                if( $jsonMethod === false )
                    $formattedUri .= ' (POST)';
                else
                    $formattedUri = $jsonMethod;
            }
        }

        return $formattedUri;
    }

    function logRequestComplete( $state, $uri, $cc, $statusCode )
    {
        $this->log->debug( sprintf(
            "%s: %d (%d/%d/%d/%d): %s",
            $state->ip,
            $statusCode,
            $state->queued,
            intval( 1000 * $state->cclast ),
            intval( 1000 * $state->ddlast ),
            intval( 1000 * $cc ),
            $uri,
        ) );
    }

    function __construct( $log, $listen, $upstream, $timeout, $ttwindow, $cctarget, $concurrency, $maxbytes, $debug )
    {
        $this->log = $log;

        $this->ttwindow = $ttwindow;
        $this->cctarget = $cctarget;
        $this->concurrency = $concurrency;
        $this->timeout = $timeout;
        $this->maxbytes = $maxbytes;
        $this->debug = $debug;

        $this->quant = $this->ttwindow / $this->cctarget;
        $this->loop = Loop::get();

        $this->responseHeaders = [ 'Server' => 'Stretcher' ];
        $this->responseNotAllowed = $this->response( Response::STATUS_METHOD_NOT_ALLOWED );
        $this->responseTooMany = $this->response( Response::STATUS_TOO_MANY_REQUESTS );
        $this->responseUnavailable = $this->response( Response::STATUS_SERVICE_UNAVAILABLE );
        $this->responseNotActive = $this->response( 0 );

        $this->host = $upstream;
        $this->hostUri = 'http://' . $upstream;
        $this->receiver = ( new Browser )->withTimeout( $this->timeout )->withFollowRedirects( false );

        $middleware = new RequestBodyBufferMiddleware( $this->maxbytes );
        $this->http = new HttpServer( $middleware, [ $this, 'enqueueHandler' ] );
        $this->socket = new SocketServer( $listen );
        $this->http->listen( $this->socket );

        $this->setupPeriodicCleanup();
    }

    function setupPeriodicCleanup()
    {
        $this->loop->addPeriodicTimer( $this->ttwindow, function()
        {
            $this->cleanupIPStates();
        } );
    }

    function cleanupIPStates()
    {
        if( count( $this->ipStates ) > 0 )
        {
            $ttnow = microtime( true );
            $toRemove = [];

            foreach( $this->ipStates as $ip => $state )
                if( empty( $state->queue ) && $ttnow - $state->ttlast > $this->ttwindow )
                    $toRemove[] = $ip;

            foreach( $toRemove as $ip )
                unset( $this->ipStates[$ip] );
        }
    }
}

new Stretcher( $log, $listen, $upstream, $timeout, $ttwindow, $cctarget, $concurrency, $maxbytes, $debug );
