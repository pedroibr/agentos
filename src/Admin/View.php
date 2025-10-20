<?php

namespace AgentOS\Admin;

class View
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
    }

    public function render(string $template, array $data = []): void
    {
        $file = $this->basePath . '/' . $template . '.php';
        if (!file_exists($file)) {
            return;
        }

        extract($data, EXTR_SKIP);
        include $file;
    }
}
