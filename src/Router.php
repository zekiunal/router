<?php

namespace Nexus\Application;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use function FastRoute\simpleDispatcher;

class Router
{
    private Dispatcher $dispatcher;
    private $container;
    private array $listeners = [];

    public function __construct(array $routes, $container = null)
    {
        $this->container = $container;
        $this->buildDispatcher($routes);
    }

    /**
     * Register an event listener
     *
     * @param string $event Event name
     * @param callable $callback Function to execute
     * @return self
     */
    public function on(string $event, callable $callback): self
    {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }

        $this->listeners[$event][] = $callback;
        return $this;
    }

    /**
     * Trigger an event and execute all registered listeners
     *
     * @param string $event Event name
     * @param array $params Parameters to pass to listeners
     * @return mixed Result from listeners
     */
    public function trigger(string $event, array $params = []): mixed
    {
        $result = null;

        if (isset($this->listeners[$event])) {
            foreach ($this->listeners[$event] as $callback) {
                $callbackResult = call_user_func_array($callback, $params);

                // Allow middleware to stop execution chain by returning false
                if ($callbackResult === false) {
                    return false;
                }

                if ($callbackResult !== null) {
                    $result = $callbackResult;
                }
            }
        }

        return $result;
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
                        'validations' => $route['validations'] ?? [],
                        'middlewares' => $route['middlewares'] ?? []
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

        // Trigger route.dispatch event
        $this->trigger('route.dispatch', [$httpMethod, $uri, $data]);

        $routeInfo = $this->getDispatcher()->dispatch($httpMethod, $uri);

        return match ($routeInfo[0]) {
            Dispatcher::NOT_FOUND => $this->handleNotFound($uri),
            Dispatcher::METHOD_NOT_ALLOWED => $this->handleMethodNotAllowed($routeInfo[1], $uri),
            Dispatcher::FOUND => $this->handleFound($routeInfo[1], $routeInfo[2], $data, $uri),
            default => [],
        };
    }

    public function getDispatcher(): Dispatcher
    {
        return $this->dispatcher;
    }

    public function handleNotFound(string $uri = ''): array
    {
        // Trigger route.notFound event
        $customResponse = $this->trigger('route.notFound', [$uri]);

        if ($customResponse !== null && $customResponse !== false) {
            return $customResponse;
        }

        return [
            'code'    => 404,
            'message' => 'Not found!'
        ];
    }

    public function handleMethodNotAllowed($allowedMethods, string $uri = ''): array
    {
        return [
            'code'    => 405,
            'message' => 'Method not allowed',
            'detail'  => 'Allowed methods: ' . implode(', ', $allowedMethods)
        ];
    }

    public function handleFound($handler, $vars, array $data = [], string $uri = '')
    {
        // Trigger route.matched event
        $this->trigger('route.matched', [$handler, $vars, $uri]);

        if (!$handler['is_public'] && !$this->isAuthenticated()) {
            echo "Not Authenticated";
            exit;
        }

        $controllerClass = $handler['controller'];
        $action = $handler['action'];

        if (!empty($data) && !empty($handler['validations'])) {
            $this->validateRequest($handler['accept'] ?? [], $handler['validations'], $data);
        }

        // Process route middlewares
        if (isset($handler['middlewares']) && !empty($handler['middlewares'])) {
            foreach ($handler['middlewares'] as $middleware) {
                // Trigger route.middleware event
                $this->trigger('route.middleware', [$middleware, $handler, $vars, $data]);

                if ($this->container) {
                    $middlewareInstance = $this->container->get($middleware);
                } else {
                    $middlewareInstance = new $middleware();
                }

                if (method_exists($middlewareInstance, 'handle')) {
                    $result = $middlewareInstance->handle($handler, $vars, $data);

                    // Middleware can return false to stop the chain
                    if ($result === false) {
                        return [
                            'code' => 403,
                            'message' => 'Forbidden by middleware'
                        ];
                    }

                    // Middleware can modify data
                    if (is_array($result)) {
                        $data = array_merge($data, $result);
                    }
                }
            }
        }

        // Trigger route.before event
        $beforeResult = $this->trigger('route.before', [$handler, $vars, $data]);

        // Allow route.before event to modify or prevent route execution
        if ($beforeResult === false) {
            return [
                'code' => 403,
                'message' => 'Forbidden by before event'
            ];
        } else if (is_array($beforeResult)) {
            return $beforeResult;
        }

        try {
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

            // Trigger route.after event
            $afterResult = $this->trigger('route.after', [$response, $handler, $vars]);

            // Allow route.after event to modify response
            if ($afterResult !== null && $afterResult !== false) {
                $response = $afterResult;
            }

            unset($controller, $action, $vars);
            return $response;
        } catch (\Exception $e) {
            // Trigger route.error event
            $errorResult = $this->trigger('route.error', [$e, $handler, $vars]);

            if ($errorResult !== null && $errorResult !== false) {
                return $errorResult;
            }

            return [
                'code' => 500,
                'message' => 'Internal Server Error',
                'detail' => $e->getMessage()
            ];
        }
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