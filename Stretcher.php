<?php declare( strict_types = 1 );

require_once __DIR__ . '/vendor/autoload.php';

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Promise\Deferred;
use React\Socket\SocketServer;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use React\Stream\WritableStreamInterface;

class HalfBuffered
{
    private $maxbytes;
    private $buffer = '';
    private $buffering = true;
    public $overflow = false;
    private WritableStreamInterface $stream;

    public function __construct( $maxbytes, ReadableStreamInterface $stream )
    {
        $this->maxbytes = $maxbytes;
        $stream->on( 'data', function( $chunk ){ $this->onData( $chunk ); } );
        $stream->on( 'end', function(){ $this->onEnd(); } );
    }

    public function onData( $chunk )
    {
        if( $this->buffering && !$this->overflow )
        {
            if( strlen( $this->buffer ) + strlen( $chunk ) < $this->maxbytes )
                $this->buffer .= $chunk;
            else
                $this->overflow = true;
        }
        else
            $this->stream->write( $chunk );
    }

    public function onEnd()
    {
        $this->buffering = false;
    }

    public function onConnected()
    {
        $this->buffering = false;
        if( $this->buffer !== '' && isset( $this->stream ) )
            $this->stream->write( $this->buffer );
    }

    public function getBuffered()
    {
        if( $this->buffering === false )
            return $this->buffer;
        return false;
    }

    public function getStream()
    {
        $this->stream = new ThroughStream;
        return $this->stream;
    }
}

class Stretcher
{
    private $monotonic = 0; // for monotonic id
    private $ttccdd; // [ last put time, last consumed, last delay ]
    private $ttwindow;
    private $cctarget;
    private $hardlimit;
    private $hardtimeout;
    private $maxbytes;
    private $quant;
    private $baskets; // [ deffered, active ]
    private $loop;
    private $debug;

    function proc( $key, $id, $action, $deferred = false )
    {
        $ttaction = microtime( true );
        if( $deferred !== false )
            $active = false;
        else
            [ $deferred, $active ] = $this->baskets[$key][$id];
        $deferred->promise()->then( function() use ( $key, $id, $ttaction )
        {
            $ttnow = microtime( true );
            $cc = $ttnow - $ttaction;
            [ $ttlast, $cclast, $ddlast ] = $this->ttccdd[$key];
            $ttdiff = $ttnow - $ttlast;
            if( $ttdiff < $this->ttwindow )
            {
                $fading = 1 - ( $ttdiff / $this->ttwindow );
                $cclast *= $fading;
                $ddlast *= $fading;
            }
            else
            {
                $cclast = 0;
                $ddlast = 0;
            }
            $this->ttccdd[$key] = [ $ttnow, $cclast + $cc, $ddlast ];
            if( count( $this->baskets[$key] ) === 1 )
                unset( $this->baskets[$key] );
            else
                unset( $this->baskets[$key][$id] );
        } );

        if( $active !== false )
            $action( $deferred );
        else
            $deferred->resolve( $this->responseTimeout );
    }

    function putWithDelay( $key, $id, $action, $async )
    {
        $ttnow = microtime( true );

        if( $async )
        {
            [ $deferred, $active ] = $this->baskets[$key][$id];
            if( $active === false || $ttnow - $active > $this->hardtimeout )
                return $this->proc( $key, $id, $action, $deferred );
        }

        [ $ttlast, $cclast, $ddlast ] = $this->ttccdd[$key] ?? [ 0, 0, 0 ];
        $ttdiff = $ttnow - $ttlast;
        if( $ttdiff < $this->ttwindow )
        {
            $fading = 1 - ( $ttdiff / $this->ttwindow );
            $cclast *= $fading;
            $ddlast *= $fading;
            $target = $cclast * $this->quant - $cclast;

            if( $ttdiff > $target )
                $delay = 0;
            else
            {
                $delay = $target - $ttdiff;
                if( $delay > $this->ttwindow )
                    $delay = $this->ttwindow;
            }

            $delay = ( $ddlast + $delay ) / 2;
            $this->ttccdd[$key] = [ $ttnow, $cclast, $delay ];
        }
        else
        {
            $delay = 0;
            $this->ttccdd[$key] = [ $ttnow, 0, 0 ];
        }

        if( $this->debug )
        {
            $uri = substr( ( new ReflectionFunction( $action ) )->getStaticVariables()['uri'], strlen( $this->hostUri ) + 1 );
            $post = ( new ReflectionFunction( $action ) )->getStaticVariables()['post'] ?? false;
            if( $post !== false )
            {
                $json = json_decode( (string)$post, true );
                $method = $json['method'] ?? '(no method)';
                $uri .= $method;
            }

            if( $ttdiff >= $this->ttwindow )
            {
                $cclast = 0;
                $ttdiff = 0;
            }
            $this->log->info( $key . ' (' . count( $this->baskets[$key] ) . '; ' . sprintf( '%.02f', 1000 * $cclast ) . '; ' . sprintf( '%.02f', 1000 * $ttdiff ) . '; ' . sprintf( '%.02f', 1000 * $delay ) . '): ' . $uri );
        }
        else
        if( $delay > 0 )
        {
            $uri = substr( ( new ReflectionFunction( $action ) )->getStaticVariables()['uri'], strlen( $this->hostUri ) + 1 );
            $this->log->info( $key . ' (' . count( $this->baskets[$key] ) . '; ' . sprintf( '%.02f', 1000 * $cclast ) . '; ' . sprintf( '%.02f', 1000 * $ttdiff ) . '; ' . sprintf( '%.02f', 1000 * $delay ) . '): ' . $uri );
        }

        if( $delay > 0.001 )
            $this->loop->addTimer( $delay, function() use ( $key, $id, $action ){ $this->proc( $key, $id, $action ); } );
        else
            $this->proc( $key, $id, $action );
    }

    function put( $key, $action )
    {
        $basket = $this->baskets[$key] ?? [];
        if( count( $basket ) >= $this->hardlimit )
            return $this->responseTooMany;
        $tail = end( $basket );

        $id = ++$this->monotonic;
        $deferred = new Deferred( function() use ( $key, $id ){ $this->baskets[$key][$id][1] = false; } );
        $this->baskets[$key][$id] = [ $deferred, microtime( true ) ];

        if( $tail !== false )
            $tail[0]->promise()->then( function() use ( $key, $id, $action ){ $this->loop->futureTick( function() use ( $key, $id, $action ){ $this->putWithDelay( $key, $id, $action, true ); } ); } );
        else
            $this->putWithDelay( $key, $id, $action, false );
        return $deferred->promise();
    }

    private $name;
    private $responseHeaders;
    private $log;
    private $host;
    private $hostUri;
    private $receiver;

    private HttpServer $http;
    private SocketServer $socket;

    private Response $responseNotAllowed;
    private Response $responseTooMany;
    private Response $responseUnavailable;
    private Response $responseTimeout;
    private Response $responseTooLarge;

    function response( $code )
    {
        return new Response( $code, $this->responseHeaders, '' );
    }

    function getlog( $name ) : \Monolog\Logger
    {
        $logger = new \Monolog\Logger( $name );
        $formatter = new \Monolog\Formatter\LineFormatter( "[%datetime%] %channel% %level_name%: %message%\n", 'Y.m.d H:i:s' );

        $stream = new \Monolog\Handler\StreamHandler( 'php://stdout', \Monolog\Level::Debug );
        $stream->setFormatter( $formatter );
        $logger->pushHandler( $stream );

        return $logger;
    }

    function __construct( $from, $to, $hardtimeout, $ttwindow, $cctarget, $hardlimit, $maxbytes, $debug )
    {
        $this->loop = Loop::get();
        $this->debug = $debug;

        $this->ttwindow = $ttwindow;
        $this->cctarget = $cctarget;
        $this->hardlimit = $hardlimit;
        $this->hardtimeout = $hardtimeout;
        $this->maxbytes = $maxbytes;

        $this->quant = $this->ttwindow / $this->cctarget;

        $this->name = 'Stretcher';
        $this->responseHeaders = [ 'Server' => $this->name ];
        $this->responseNotAllowed = $this->response( Response::STATUS_METHOD_NOT_ALLOWED );
        $this->responseTooMany = $this->response( Response::STATUS_TOO_MANY_REQUESTS );
        $this->responseUnavailable = $this->response( Response::STATUS_SERVICE_UNAVAILABLE );
        $this->responseTimeout = $this->response( Response::STATUS_REQUEST_TIMEOUT );
        $this->responseTooLarge = $this->response( Response::STATUS_PAYLOAD_TOO_LARGE );

        $this->log = $this->getlog( $this->name );
        $this->log->info( $this->name . ' created' );

        $this->host = $to;
        $this->hostUri = 'http://' . $to;
        $this->receiver = ( new Browser )->withTimeout( $this->hardtimeout )->withFollowRedirects( false );

        $middleware = $this->debug ? ( new RequestBodyBufferMiddleware( $this->maxbytes ) ) : ( new StreamingRequestMiddleware );
        $this->http = new HttpServer( $middleware, function( ServerRequestInterface $request )
        {
            $method = $request->getMethod();
            if( $method !== 'GET' && $method !== 'POST' )
                return $this->responseNotAllowed;

            $headers = $request->getHeaders();
            $headers['Host'] = $this->host;

            $uri = $request->getUri();
            $path = $uri->getPath();
            $query = $uri->getQuery();
            $uri = $this->hostUri . $path . ( $query !== '' ? ( '?' . $query ) : '' );

            $key = $headers['Cf-Connecting-Ip'][0] ?? false;
            if( $key === false )
                $key = $headers['X-Forwarded-For'][0] ?? $request->getServerParams()['REMOTE_ADDR'];

            if( $method === 'GET' )
            {
                return $this->put( $key, function( $deferred ) use ( $uri, $headers )
                {
                    $this->receiver->get( $uri, $headers )->then( function( ResponseInterface $response ) use ( $deferred )
                    {
                        $deferred->resolve( $response );
                    },
                    function( Exception $e ) use ( $uri, $deferred )
                    {
                        $code = $e->getCode();
                        $this->log->error( $code . ': ' . $e->getMessage() . ': ' . substr( $uri, strlen( $this->hostUri ) + 1 ) );
                        if( $code >= 400 && $code < 600 )
                            $deferred->resolve( $this->response( $code ) );
                        else
                            $deferred->resolve( $this->responseUnavailable );
                    } );
                } );
            }
            else
            // if( $method === 'POST' )
            {
                $body = $request->getBody();
                if( $body instanceof ReadableStreamInterface )
                    $post = new HalfBuffered( $this->maxbytes, $body );
                else
                    $post = $body;

                return $this->put( $key, function( $deferred ) use ( $uri, $headers, $post )
                {
                    if( $post instanceof HalfBuffered )
                    {
                        if( $post->overflow )
                            return $deferred->resolve( $this->responseTooLarge );

                        $body = $post->getBuffered();
                        if( $body === false )
                            $body = $post->getStream();
                    }
                    else
                        $body = $post;

                    $this->receiver->post( $uri, $headers, $body )->then( function( ResponseInterface $response ) use ( $deferred )
                    {
                        $deferred->resolve( $response );
                    },
                    function( Exception $e ) use ( $uri, $deferred )
                    {
                        $code = $e->getCode();
                        $this->log->error( $code . ': ' . $e->getMessage() . ': ' . substr( $uri, strlen( $this->hostUri ) + 1 ) );
                        if( $code >= 400 && $code < 600 )
                            $deferred->resolve( $this->response( $code ) );
                        else
                            $deferred->resolve( $this->responseUnavailable );
                    } );

                    if( $post instanceof HalfBuffered )
                        $post->onConnected();
                } );
            }
        } );

        $this->socket = new SocketServer( $from );
        $this->http->listen( $this->socket );
    }

    function __destruct()
    {
        if( isset( $this->log ) )
            $this->log->info( $this->name . ' destruct' );
    }
}

if( file_exists( __DIR__ . '/config.php' ) )
    require_once __DIR__ . '/config.php';

$from = $argv[1] ?? '127.0.0.1:8080';
$to = $argv[2] ?? '127.0.0.1:80';
$timeout = (int)( $argv[3] ?? 12 );
$window = (int)( $argv[4] ?? 12 );
$target = (int)( $argv[5] ?? 4 );
$hardlimit = (int)( $argv[6] ?? 64 );
$maxbytes = (int)( $argv[7] ?? 1048576 );
$debug = (bool)( $argv[8] ?? false );

new Stretcher( $from, $to, $timeout, $window, $target, $hardlimit, $maxbytes, $debug );
