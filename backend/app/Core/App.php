<?php
declare(strict_types=1);

namespace App\Core;

class App
{
    private static ?App $instance = null;
    private Router $router;

    public function __construct()
    {
        $this->router = new Router();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function run(): void
    {
        $this->router->dispatch();
    }
}