<?php

namespace App;

use Twig\Environment;

class Router
{
    private Environment $twig;
    private array $routes = [];

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function get(string $path, $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function add(string $method, string $path, $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler,
        ];
    }

    public function dispatch(string $uri): void
    {
        $requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $requestPath = parse_url($uri, PHP_URL_PATH) ?: '/';
        $requestPath = rtrim($requestPath, '/') ?: '/';

        foreach ($this->routes as $route) {
            if ($route['method'] !== $requestMethod) {
                continue;
            }

            $params = $this->match($route['path'], $requestPath);
            if ($params === null) {
                continue;
            }

            $handler = $this->resolveHandler($route['handler']);
            call_user_func_array($handler, $params);
            return;
        }

        http_response_code(404);
        echo $this->twig->render('errors/404.html.twig');
    }

    private function resolveHandler($handler): callable
    {
        if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0]) && class_exists($handler[0])) {
            $className = $handler[0];
            $method = $handler[1];
            $controller = $this->instantiateController($className);
            return [$controller, $method];
        }

        if (is_callable($handler)) {
            return $handler;
        }

        throw new \RuntimeException('Invalid route handler.');
    }

    private function instantiateController(string $className): object
    {
        $reflection = new \ReflectionClass($className);
        $constructor = $reflection->getConstructor();

        if (!$constructor || $constructor->getNumberOfRequiredParameters() === 0) {
            return $reflection->newInstance();
        }

        $firstParameter = $constructor->getParameters()[0] ?? null;
        $type = $firstParameter ? $firstParameter->getType() : null;
        if ($type instanceof \ReflectionNamedType && $type->getName() === Environment::class) {
            return $reflection->newInstance($this->twig);
        }

        return $reflection->newInstance($this->twig);
    }

    private function match(string $routePath, string $requestPath): ?array
    {
        $routePath = rtrim($routePath, '/') ?: '/';
        $params = [];

        $quoted = preg_quote($routePath, '#');
        $pattern = preg_replace_callback('/\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}/', static function ($matches) use (&$params) {
            $params[] = $matches[1];
            return '([^/]+)';
        }, $quoted);

        $pattern = '#^' . $pattern . '$#';

        if (!preg_match($pattern, $requestPath, $matches)) {
            return null;
        }

        array_shift($matches);
        return $matches;
    }
}
