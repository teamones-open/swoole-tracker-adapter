<?php

namespace teamones\tracker;

use Webman\App;
use Webman\Http\Request;
use Webman\Route;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response;

class WebmanApp extends App
{
    /**
     * @param TcpConnection $connection
     * @param Request $request
     * @return null
     */
    public function onMessage(TcpConnection $connection, $request)
    {
        static $request_count = 0;
        if (++$request_count > static::$_maxRequestCount) {
            static::tryToGracefulExit();
        }

        $tick = null;
        $tickRetOk = false;
        $tickRetNo = 404;

        try {
            static::$_request = $request;
            static::$_connection = $connection;
            $path = $request->path();
            $key = $request->method() . $path;

            // swoole tracker 被调用开始前执行
            $tick = \SwooleTracker\Stats::beforeExecRpc($path, env("belong_system", ''), $request->getLocalIp());

            if (isset(static::$_callbacks[$key])) {
                list($callback, $request->app, $request->controller, $request->action) = static::$_callbacks[$key];
                static::send($connection, $callback($request), $request);
                return null;
            }

            if (static::findRoute($connection, $path, $key, $request)) {
                return null;
            }

            if (static::findFile($connection, $path, $key, $request)) {
                return null;
            }

            $controller_and_action = static::parseControllerAction($path);
            if (!$controller_and_action || Route::hasDisableDefaultRoute()) {
                // when route, controller and action not found, try to use Route::fallback
                $callback = Route::getFallback() ?: function () {
                    static $response_404;
                    if (!$response_404) {
                        $response_404 = new Response(404, [], \file_get_contents(static::$_publicPath . '/404.html'));
                    }
                    return $response_404;
                };
                static::$_callbacks[$key] = [$callback, '', '', ''];
                list($callback, $request->app, $request->controller, $request->action) = static::$_callbacks[$key];
                static::send($connection, $callback($request), $request);
                return null;
            }
            $app = $controller_and_action['app'];
            $controller = $controller_and_action['controller'];
            $action = $controller_and_action['action'];
            $callback = static::getCallback($app, [$controller_and_action['instance'], $action]);
            static::$_callbacks[$key] = [$callback, $app, $controller, $action];
            list($callback, $request->app, $request->controller, $request->action) = static::$_callbacks[$key];
            static::send($connection, $callback($request), $request);

            // swoole tracker 正常返回值
            $tickRetOk = true;
            $tickRetNo = 200;

        } catch (\Throwable $e) {
            static::send($connection, static::exceptionResponse($e, $request), $request);
        }

        // swoole tracker 被调用结束后执行
        \SwooleTracker\Stats::afterExecRpc($tick, $tickRetOk, $tickRetNo);

        return null;
    }
}