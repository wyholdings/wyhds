<?php
namespace App;

use Twig\Environment;

class Router {
    protected array $routes = [];
    protected Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function get(string $uri, array $action) {
        $this->routes['GET'][] = ['uri' => $uri, 'action' => $action];
    }

    public function post(string $uri, array $action) {
        $this->routes['POST'][] = ['uri' => $uri, 'action' => $action];
    }

    public function dispatch(string $uri) {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($uri, PHP_URL_PATH);

        foreach ($this->routes[$method] ?? [] as $route) {
            // 🔥 여기만 수정
            $pattern = preg_replace(
                '#\{[a-zA-Z_][a-zA-Z0-9_]*\}#',
                '([^/]+)',      // 숫자뿐 아니라 ebook_... 같은 문자열도 허용
                $route['uri']
            );
            $pattern = "#^" . $pattern . "$#";

            if (preg_match($pattern, $path, $matches)) {
                array_shift($matches); // 첫 번째는 전체 매칭된 문자열이니까 제거
                [$class, $methodName] = $route['action'];
                $controller = new $class($this->twig);
                return call_user_func_array([$controller, $methodName], $matches);
            }
        }

        http_response_code(404);
        echo $this->twig->render('errors/404.html.twig');
        exit;
    }
}
?>
