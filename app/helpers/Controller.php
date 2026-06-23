<?php
/**
 * Controller - base class with view rendering and JSON helpers.
 */

declare(strict_types=1);

abstract class Controller
{
    /** Render a view inside the main layout. */
    protected function view(string $name, array $data = [], ?string $layout = 'layout'): void
    {
        extract($data, EXTR_SKIP);
        $viewFile = APP_PATH . '/views/' . $name . '.php';

        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        if ($layout === null) {
            echo $content;
            return;
        }
        require APP_PATH . '/views/' . $layout . '.php';
    }

    /** Emit a JSON response and stop. */
    protected function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    protected function redirect(string $route): void
    {
        header('Location: ' . BASE_URL . '/?route=' . $route);
        exit;
    }

    /** Read and json_decode a raw JSON request body. */
    protected function jsonInput(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    protected function url(string $route, array $params = []): string
    {
        $q = array_merge(['route' => $route], $params);
        return BASE_URL . '/?' . http_build_query($q);
    }
}
