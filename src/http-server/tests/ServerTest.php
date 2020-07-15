<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace HyperfTest\HttpServer;

use Hyperf\Contract\ContainerInterface;
use Hyperf\Dispatcher\HttpDispatcher;
use Hyperf\ExceptionHandler\ExceptionHandlerDispatcher;
use Hyperf\HttpMessage\Exception\BadRequestHttpException;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\HttpMessage\Server\Response as Psr7Response;
use Hyperf\HttpServer\ResponseEmitter;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Utils\Coordinator\Constants;
use Hyperf\Utils\Coordinator\CoordinatorManager;
use HyperfTest\HttpServer\Stub\ServerStub;
use Mockery;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * @internal
 * @coversNothing
 */
class ServerTest extends TestCase
{
    protected function tearDown()
    {
        Mockery::close();
        CoordinatorManager::clear(Constants::WORKER_START);
    }

    public function testOnRequest()
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();
        $container = $this->getContainer();
        $dispatcher = Mockery::mock(ExceptionHandlerDispatcher::class);
        $emitter = Mockery::mock(ResponseEmitter::class);
        $server = Mockery::mock(ServerStub::class . '[initRequestAndResponse]', [
            $container,
            Mockery::mock(HttpDispatcher::class),
            $dispatcher,
            $emitter,
        ]);

        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function ($exception) {
            if ($exception instanceof HttpException) {
                return (new Psr7Response())->withStatus($exception->getStatusCode());
            }
            return null;
        });

        $emitter->shouldReceive('emit')->once()->andReturnUsing(function ($response) {
            $this->assertInstanceOf(Psr7Response::class, $response);
            $this->assertSame(400, $response->getStatusCode());
        });

        $server->shouldReceive('initRequestAndResponse')->andReturnUsing(function () {
            // Initialize PSR-7 Request and Response objects.
            throw new BadRequestHttpException();
        });

        $server->onRequest($req = Mockery::mock(Request::class), $res = Mockery::mock(Response::class));
    }

    protected function getContainer()
    {
        $container = Mockery::mock(ContainerInterface::class);
        ApplicationContext::setContainer($container);

        return $container;
    }
}
