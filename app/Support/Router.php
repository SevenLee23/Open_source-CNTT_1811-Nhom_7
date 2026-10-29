<?php
namespace App\Support;

final class Router
{
    private $routes = [];

    public function add($method, $path, $handler)
    {
        $this->routes[] = [strtoupper($method), trim($path, '/'), $handler];
    }

    public function dispatch($method, $path)
    {
        $method = strtoupper($method);
        $path = trim($path, '/');
        foreach ($this->routes as $route) {
            if ($route[0] !== $method) continue;
            $names = [];
            $pattern = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function ($match) use (&$names) {
                $names[] = $match[1];
                return '([^/]+)';
            }, $route[1]);
            if (!preg_match('#^' . $pattern . '$#', $path, $matches)) continue;
            array_shift($matches);
            $params = [];
            foreach ($names as $index => $name) $params[$name] = rawurldecode($matches[$index]);
            $handler = $route[2];
            if (is_array($handler)) {
                $class = $handler[0];
                $instance = new $class();
                return call_user_func_array([$instance, $handler[1]], array_values($params));
            }
            return call_user_func_array($handler, array_values($params));
        }
        http_response_code(404);
        echo 'Không tìm thấy trang.';
    }
}
