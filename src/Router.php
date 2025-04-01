<?php

namespace Nexus\Application;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use function FastRoute\simpleDispatcher;

class Router
{
    private Dispatcher $dispatcher;
    private $container;

    public function __construct(array $routes, $container = null)
    {
        $this->container = $container;
        $this->buildDispatcher($routes);
    }

    private function buildDispatcher(array $routes): void
    {
        $this->dispatcher = simpleDispatcher(function (RouteCollector $r) use ($routes) {
            foreach ($routes as $prefix => $groupRoutes) {
                foreach ($groupRoutes as $route) {
                    $method = strtoupper($route['method']);
                    $uri = $prefix === '/' ? $route['uri'] : $prefix . $route['uri'];
                    $handler = [
                        'controller'  => $route['controller'],
                        'action'      => $route['action'],
                        'template'    => $route['template'] ?? null,
                        'is_public'   => $route['is_public'] ?? false,
                        'accept'      => $route['accept'] ?? [],
                        'validations' => $route['validations'] ?? []
                    ];
                    $r->addRoute($method, $uri, $handler);
                }
            }
        });
    }

    public function dispatch(string $httpMethod, string $uri, array $data = []): array
    {
        if (false !== $pos = strpos($uri, '?')) {
            $uri = substr($uri, 0, $pos);
        }

        $uri = rawurldecode($uri);

        $routeInfo = $this->getDispatcher()->dispatch($httpMethod, $uri);
        return match ($routeInfo[0]) {
            Dispatcher::NOT_FOUND => $this->handleNotFound(),
            Dispatcher::METHOD_NOT_ALLOWED => $this->handleMethodNotAllowed($routeInfo[1]),
            Dispatcher::FOUND => $this->handleFound($routeInfo[1], $routeInfo[2], $data),
            default => [],
        };
    }

    public function getDispatcher(): Dispatcher
    {
        return $this->dispatcher;
    }

    public function handleNotFound(): array
    {
        return [
            'code'    => 404,
            'message' => 'Not found!'
        ];
    }

    public function handleMethodNotAllowed($allowedMethods): array
    {
        return [
            'code'    => 405,
            'message' => 'Method not allowed',
            'detail'  => 'Allowed methods: ' . implode(', ', $allowedMethods)
        ];
    }

    public function handleFound($handler, $vars, array $data = [])
    {
        if (!$handler['is_public'] && !$this->isAuthenticated()) {
            echo "Not Authenticated";
            exit;
        }

        $controllerClass = $handler['controller'];
        $action = $handler['action'];

        if (!empty($data) && !empty($handler['validations'])) {
            $this->validateRequest($handler['accept'] ?? [], $handler['validations'], $data);
        }

        if ($this->container) {
            $controller = $this->container->get($controllerClass);
        } else {
            $controller = new $controllerClass();
            $controller->setData($data);
        }

        if (method_exists($controller, 'setTemplate') && isset($handler['template'])) {
            $controller->setTemplate($handler['template']);
        }

        $response = call_user_func_array([$controller, $action], $vars);
        unset($controller, $action, $vars);
        return $response;
    }

    private function validateRequest(array $acceptFields, array $validations, array $data): void
    {
        $errors = [];

        foreach ($acceptFields as $field) {
            $value = $data[$field] ?? null;

            if (isset($validations[$field])) {
                foreach ($validations[$field] as $validator => $config) {
                    $validatorInstance = new $validator();
                    $params = $config['params'] ?? [];
                    $message = $config['message'] ?? 'Validation error';

                    if (!$validatorInstance->validate($value, $params)) {
                        $errors[$field] = $this->parseMessage($message, $params);
                        break;
                    }
                }
            }
        }

        if (!empty($errors)) {
            $this->handleValidationErrors($errors);
        }
    }

    private function parseMessage($message, $params)
    {
        foreach ($params as $key => $value) {
            $message = str_replace('{{' . $key . '}}', $value, $message);
        }
        return $message;
    }

    private function handleValidationErrors($errors): void
    {
        $_SESSION['validation_errors'] = $errors;

        $_SESSION['form_data'] = $_POST;

        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit;
    }

    private function isAuthenticated(): bool
    {
        return isset($_SESSION['user_id']);
    }
}