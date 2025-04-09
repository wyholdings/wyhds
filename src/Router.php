<?php
namespace App;

class Router {
    protected $routes = [];

    public function get($uri, $action) {
        $this->routes['GET'][$uri] = $action;
    }

    public function post($uri, $action) {
        $this->routes['POST'][$uri] = $action;
    }

    public function dispatch($uri) {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($uri, PHP_URL_PATH);

        if (isset($this->routes[$method][$path])) {
            [$class, $method] = $this->routes[$method][$path];
            $controller = new $class;
            return call_user_func([$controller, $method]);
        }

        http_response_code(404);
        echo "404 Not Found";
    }
}
?>