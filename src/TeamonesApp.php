<?php

namespace teamones\tracker;

use think\App;
use think\exception\ErrorCode;
use think\exception\HttpResponseException;
use think\Hook;
use think\Response;
use Workerman\Connection\TcpConnection;

class TeamonesApp extends App
{
    /**
     * @param TcpConnection $connection
     * @param \think\Request $request
     * @return null
     */
    public function onMessage(TcpConnection $connection, $request)
    {
        static $request_count = 0;

        if (++$request_count > static::$_maxRequestCount) {
            static::tryToGracefulExit();
        }

        // 应用初始化标签
        Hook::listen('app_init');

        // 赋值
        static::$_request = $request;
        static::$_connection = $connection;
        $path = $request->path();
        $key = $request->method() . $path;

        // swoole tracker 被调用开始前执行
        $tickRetOk = false;
        $tickRetNo = ErrorCode::ERROR_404;
        if ((bool)config('server.use_swoole_tracker') && class_exists("SwooleTracker\Stats")) {
            $tick = \SwooleTracker\Stats::beforeExecRpc($path, C('belong_system'), $request->getLocalIp());
        }

        // 请求开始HOOK
        Hook::listen("request", $request);

        // 设置参数过滤规则
        $request->filter(C('DEFAULT_FILTER'));

        // 获取配置
        $config = C();

        // 返回header参数
        $header = [];

        try {
            // 应用开始标签
            Hook::listen('app_begin');

            // 进行 URL 路由检测
            if (isset(static::$_callbacks[$key]) && !empty(static::$_callbacks[$key])) {
                // 直接读取缓存对象
                list($callback, $request->app, $request->controller, $request->action) = static::$_callbacks[$key];

                // 执行路由方法
                $data = $callback($request);
            } else {
                // 检测路由
                $dispatch = self::routeCheck($request, $config);

                // 保存路由解析参数
                $request->dispatch($dispatch);

                // 执行路由方法
                $data = static::exec($key, $request, $dispatch, $config);
            }

            // 对象超过 1024 回收
            if (\count(static::$_callbacks) > 1024) {
                static::clearCache();
            }

            // swoole tracker 正常返回值
            $tickRetOk = true;
            $tickRetNo = 200;

        } catch (HttpResponseException $exception) {
            $data = $exception->getResponse();
        } catch (\Throwable $e) {
            $data = Response::create(["code" => $e->getCode(), "msg" => $e->getMessage()], "json");;
        }


        // 输出数据到客户端
        if ($data instanceof Response) {
            $response = $data->header($header);
        } elseif (!is_null($data)) {
            // 默认自动识别响应输出类型
            $type = $request->isAjax() ?
                $config['DEFAULT_AJAX_RETURN'] :
                $config['DEFAULT_RETURN_TYPE'];

            $response = Response::create($data, $type)->header($header);
        } else {
            $response = Response::create()->header($header);
        }

        // 应用结束标签
        Hook::listen('app_end');

        static::send($connection, $response->renderWorkermanData(), $request);

        // swoole tracker 被调用结束后执行
        if ((bool)config('server.use_swoole_tracker') && class_exists("SwooleTracker\Stats")) {
            \SwooleTracker\Stats::afterExecRpc($tick, $tickRetOk, $tickRetNo);
        }

        return null;
    }
}
