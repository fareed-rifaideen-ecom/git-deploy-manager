<?php
if (!defined('ABSPATH')) {
    exit;
}

class GDM_Loader {
    private array $actions = [];

    public function add_action(string $hook, object $component, string $callback, int $priority = 10, int $accepted_args = 1): void {
        $this->actions[] = compact('hook', 'component', 'callback', 'priority', 'accepted_args');
    }

    public function run(): void {
        foreach ($this->actions as $action) {
            add_action(
                $action['hook'],
                [$action['component'], $action['callback']],
                $action['priority'],
                $action['accepted_args']
            );
        }
    }
}
