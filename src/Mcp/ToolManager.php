<?php

namespace AgentOS\Mcp;

/** Converts an agent's explicit MCP allowlist to model functions and executes them. */
class ToolManager
{
    private ConnectionRepository $connections;
    private Client $client;

    public function __construct(?ConnectionRepository $connections = null, ?Client $client = null)
    {
        $this->connections = $connections ?: new ConnectionRepository();
        $this->client = $client ?: new Client($this->connections);
    }

    public function resolveForAgent(array $agent): array
    {
        $tools = [];
        $bindings = [];
        $instructionSections = [];
        $legacyInstructions = sanitize_textarea_field((string) ($agent['mcp_instructions'] ?? ''));
        if ($legacyInstructions !== '') {
            $instructionSections[] = "General MCP instructions (legacy, preserved):\n" . $legacyInstructions;
        }
        foreach ((array) ($agent['mcp_tools'] ?? []) as $connectionId => $selected) {
            $connection = $this->connections->get((string) $connectionId);
            if (!$connection || empty($connection['enabled'])) {
                continue;
            }
            $selected = array_values(array_unique(array_map('strval', (array) $selected)));
            $connectionTools = [];
            foreach (($connection['tools'] ?? []) as $tool) {
                if (!in_array((string) ($tool['name'] ?? ''), $selected, true)) {
                    continue;
                }
                $schema = $this->safeSchema($tool['inputSchema'] ?? []);
                if (!$schema) {
                    continue;
                }
                $name = $this->functionName((string) $connectionId, (string) $tool['name']);
                $tools[] = [
                    'type' => 'function',
                    'name' => $name,
                    'description' => $this->description((string) ($connection['label'] ?? ''), $tool),
                    'parameters' => $schema,
                    'strict' => false,
                ];
                $connectionTools[] = (string) $tool['name'];
                $approval = $agent['mcp_approval'][$connectionId] ?? 'confirm';
                $bindings[$name] = [
                    'connection_id' => (string) $connectionId,
                    'tool_name' => (string) $tool['name'],
                    'connection_label' => (string) $connection['label'],
                    'argument_fields' => array_keys((array) ($schema['properties'] ?? [])),
                    'approval' => $approval === 'auto' ? 'auto' : 'confirm',
                ];
            }
            $connectionInstructions = sanitize_textarea_field((string) ($agent['mcp_connection_instructions'][$connectionId] ?? ''));
            if ($connectionTools && $connectionInstructions !== '') {
                $instructionSections[] = 'MCP connection: ' . (string) ($connection['label'] ?? $connectionId)
                    . "\nSelected tools: " . implode(', ', $connectionTools)
                    . "\nConnection-specific instructions:\n" . $connectionInstructions;
            }
        }
        return ['tools' => $tools, 'bindings' => $bindings, 'instructions' => implode("\n\n", $instructionSections)];
    }

    /** @return array|\WP_Error */
    public function execute(array $agent, string $functionName, array $args, string $requestId = '')
    {
        $resolved = $this->resolveForAgent($agent);
        $binding = $resolved['bindings'][$functionName] ?? null;
        if (!$binding) {
            return new \WP_Error('mcp_tool_forbidden', __('This MCP tool is not enabled for the agent.', 'agentos'));
        }
        $definition = null;
        foreach ($resolved['tools'] as $tool) {
            if ($tool['name'] === $functionName) {
                $definition = $tool;
                break;
            }
        }
        if (!$definition || !$this->argumentsMatch($definition['parameters'], $args)) {
            $issues = $definition ? $this->invalidArgumentIssues($definition['parameters'], $args) : [];
            $fields = array_column($issues, 'field');
            return new \WP_Error('mcp_invalid_arguments', __('MCP tool arguments do not match its schema.', 'agentos'), [
                'status' => 400,
                'mcp_diagnostic' => ['phase' => 'validate_arguments', 'code' => 'mcp_invalid_arguments', 'fields' => $fields, 'issues' => $issues],
            ]);
        }
        return $this->client->call($binding['connection_id'], $binding['tool_name'], $args, $requestId);
    }

    private function functionName(string $connectionId, string $toolName): string
    {
        return 'mcp_' . substr(hash('sha256', $connectionId . "\0" . $toolName), 0, 32);
    }

    private function description(string $label, array $tool): string
    {
        $description = trim((string) ($tool['description'] ?? ''));
        $prefix = ($label ? '[' . $label . '] ' : '') . (string) ($tool['name'] ?? '') . ': ';
        return substr($prefix . $description, 0, 1024);
    }

    private function safeSchema($schema): ?array
    {
        if (!is_array($schema) || ($schema['type'] ?? '') !== 'object'
            || (isset($schema['properties']) && !is_array($schema['properties']))) {
            return null;
        }
        $json = wp_json_encode($schema);
        if (!is_string($json) || strlen($json) > 32768) {
            return null;
        }
        $schema = json_decode($json, true);
        if (!is_array($schema)) {
            return null;
        }
        return $this->normalizeSchemaMaps($schema);
    }

    private function normalizeSchemaMaps(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if ($value === [] && in_array($key, ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'], true)) {
                $schema[$key] = new \stdClass();
            } else {
                $schema[$key] = $this->normalizeSchemaMaps($value);
            }
        }
        return $schema;
    }

    private function argumentsMatch(array $schema, array $args): bool
    {
        if (($schema['type'] ?? '') !== 'object') {
            return false;
        }
        $properties = (array) ($schema['properties'] ?? []);
        foreach (($schema['required'] ?? []) as $required) {
            if (!array_key_exists($required, $args)) {
                return false;
            }
        }
        if (($schema['additionalProperties'] ?? true) === false && array_diff(array_keys($args), array_keys($properties))) {
            return false;
        }
        foreach ($args as $key => $value) {
            if (isset($properties[$key]) && !$this->matchesSchema($value, $properties[$key], 0)) {
                return false;
            }
            if (!isset($properties[$key]) && is_array($schema['additionalProperties'] ?? null)
                && !$this->matchesSchema($value, $schema['additionalProperties'], 0)) {
                return false;
            }
        }
        return true;
    }

    private function matchesSchema($value, $schema, int $depth): bool
    {
        if (!is_array($schema) || $depth > 16) {
            return false;
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return false;
        }
        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            return false;
        }
        $types = $schema['type'] ?? null;
        if ($types !== null) {
            foreach ((array) $types as $type) {
                if ($this->matchesType($value, (string) $type)) {
                    return $this->matchesObjectOrArrayConstraints($value, $schema, $depth);
                }
            }
            return false;
        }
        return $this->matchesObjectOrArrayConstraints($value, $schema, $depth);
    }

    private function matchesObjectOrArrayConstraints($value, array $schema, int $depth): bool
    {
        $schemaTypes = (array) ($schema['type'] ?? []);
        if ((is_array($value) || is_object($value)) && in_array('object', $schemaTypes, true)) {
            $properties = (array) ($schema['properties'] ?? []);
            $objectValue = (array) $value;
            foreach (($schema['required'] ?? []) as $required) {
                if (!array_key_exists($required, $objectValue)) {
                    return false;
                }
            }
            foreach ($objectValue as $key => $item) {
                if (isset($properties[$key]) && !$this->matchesSchema($item, $properties[$key], $depth + 1)) {
                    return false;
                }
                if (!isset($properties[$key]) && ($schema['additionalProperties'] ?? true) === false) {
                    return false;
                }
                if (!isset($properties[$key]) && is_array($schema['additionalProperties'] ?? null)
                    && !$this->matchesSchema($item, $schema['additionalProperties'], $depth + 1)) {
                    return false;
                }
            }
        }
        if (is_array($value) && in_array('array', $schemaTypes, true)) {
            if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
                return false;
            }
            if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
                return false;
            }
            if (isset($schema['items'])) {
                foreach ($value as $item) {
                    if (!$this->matchesSchema($item, $schema['items'], $depth + 1)) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    private function matchesType($value, string $type): bool
    {
        switch ($type) {
            case 'string': return is_string($value);
            case 'integer': return is_int($value);
            case 'number': return is_int($value) || is_float($value);
            case 'boolean': return is_bool($value);
            case 'array': return is_array($value);
            case 'object':
                if (is_object($value)) {
                    return true;
                }
                if (!is_array($value)) {
                    return false;
                }
                // Associative PHP arrays can represent objects. List arrays cannot.
                return $value !== [] && array_keys($value) !== range(0, count($value) - 1);
            case 'null': return $value === null;
            default: return false;
        }
    }

    private function invalidArgumentIssues(array $schema, array $args): array
    {
        return Diagnostics::safeIssues($this->collectInvalidArgumentIssues($schema, $args, '', 0));
    }

    private function collectInvalidArgumentIssues(array $schema, $value, string $path, int $depth): array
    {
        if ($depth > 16) {
            return [['field' => $path ?: 'arguments', 'rule' => 'invalid_type_or_value']];
        }
        $issues = [];
        $types = (array) ($schema['type'] ?? []);
        if (in_array('object', $types, true)) {
            if (!is_array($value) && !is_object($value)) {
                return [['field' => $path ?: 'arguments', 'rule' => 'invalid_type_or_value']];
            }
            $properties = (array) ($schema['properties'] ?? []);
            $items = (array) $value;
            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (!array_key_exists($required, $items)) {
                    $issues[] = ['field' => trim($path . '.' . $required, '.'), 'rule' => 'missing_required'];
                }
            }
            foreach ($items as $key => $item) {
                $fieldPath = trim($path . '.' . (string) $key, '.');
                if (isset($properties[$key])) {
                    $issues = array_merge($issues, $this->collectInvalidArgumentIssues($properties[$key], $item, $fieldPath, $depth + 1));
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $issues[] = ['field' => $fieldPath, 'rule' => 'unknown_field'];
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    $issues = array_merge($issues, $this->collectInvalidArgumentIssues($schema['additionalProperties'], $item, $fieldPath, $depth + 1));
                }
            }
            if (!$issues && !$this->matchesSchema($value, $schema, $depth)) {
                $issues[] = ['field' => $path ?: 'arguments', 'rule' => 'invalid_type_or_value'];
            }
        } elseif (in_array('array', $types, true)) {
            if (!is_array($value)) {
                return [['field' => $path ?: 'arguments', 'rule' => 'invalid_type_or_value']];
            }
            if ((isset($schema['minItems']) && count($value) < (int) $schema['minItems'])
                || (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems'])) {
                $issues[] = ['field' => $path ?: 'arguments', 'rule' => 'invalid_type_or_value'];
            }
            foreach ($value as $index => $item) {
                if (isset($schema['items']) && !$this->matchesSchema($item, $schema['items'], $depth + 1)) {
                    $issues = array_merge($issues, $this->collectInvalidArgumentIssues($schema['items'], $item, $path . '[' . $index . ']', $depth + 1));
                }
            }
        } elseif (!$this->matchesSchema($value, $schema, $depth)) {
            $issues[] = ['field' => $path ?: 'arguments', 'rule' => 'invalid_type_or_value'];
        }
        return $issues;
    }
}
