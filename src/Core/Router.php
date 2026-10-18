<?php
namespace App\Core;

/**
 * Minimal router: exact match on the path relative to the application base
 * ('' is the home page), dispatching to [ControllerClass, 'method'].
 */
class Router
{
    private array $routes = [];

    public function add(array $methods, string $path, array $handler): self
    {
        foreach ($methods as $method) {
            $this->routes[$path][strtoupper($method)] = $handler;
        }
        return $this;
    }

    public function get(string $path, array $handler): self
    {
        return $this->add(['GET'], $path, $handler);
    }

    public function post(string $path, array $handler): self
    {
        return $this->add(['POST'], $path, $handler);
    }

    public function dispatch(App $app): void
    {
        $handlers = $this->routes[$app->route] ?? null;
        if ($handlers === null) {
            $app->notFound();
            return;
        }
        $handler = $handlers[$app->request->method()] ?? null;
        if ($handler === null) {
            header('Allow: ' . implode(', ', array_keys($handlers)));
            $app->error(405, 'Méthode non autorisée.');
            return;
        }
        [$class, $method] = $handler;
        (new $class($app))->$method();
    }
}
