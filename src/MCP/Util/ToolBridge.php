<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Util;

use Mcp\Capability\Attribute\McpTool;
use Psr\Container\ContainerInterface;

/**
 * Extracts MCP tool definitions via reflection and exposes them in the Ollama "tools" format,
 * plus an `execute()` entry point that calls the underlying tool method via the Symfony container.
 *
 * Read-only tools, including dangerous ones, are exposed if `dangerous_tools_enabled` is on;
 * otherwise dangerous tools are skipped from the agentic surface.
 */
class ToolBridge
{
    private const TOOL_CLASSES = [
        \XC\MCP\MCP\Tools\ProductTools::class,
        \XC\MCP\MCP\Tools\OrderTools::class,
        \XC\MCP\MCP\Tools\CategoryTools::class,
        \XC\MCP\MCP\Tools\SearchTools::class,
        \XC\MCP\MCP\Tools\ReportTools::class,
    ];

    private const DANGEROUS = [
        'product_delete',
        'product_bulk_update_prices',
    ];

    /** @var array<string, array{class: class-string, method: string, attr: McpTool, reflection: \ReflectionMethod}>|null */
    private ?array $cache = null;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /**
     * Tool list in Ollama format. Filters out dangerous tools unless module config allows them.
     *
     * @return list<array<string, mixed>>
     */
    public function describeForOllama(): array
    {
        $mcp = \XLite\Core\Config::getInstance()->XC?->MCP;
        $allowDangerous = (bool) ($mcp?->dangerous_tools_enabled ?? false);

        $out = [];
        foreach ($this->discover() as $name => $info) {
            if (!$allowDangerous && in_array($name, self::DANGEROUS, true)) {
                continue;
            }
            $out[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $name,
                    'description' => $info['attr']->description ?? '',
                    'parameters'  => $this->parametersForOllama($info['reflection']),
                ],
            ];
        }
        return $out;
    }

    /**
     * Execute a tool by name with the given associative arguments.
     */
    public function execute(string $name, array $args): mixed
    {
        $tools = $this->discover();
        if (!isset($tools[$name])) {
            throw new \RuntimeException("Unknown tool: {$name}");
        }
        $info     = $tools[$name];
        $instance = $this->container->get($info['class']);
        $method   = $info['reflection'];

        $positional = [];
        foreach ($method->getParameters() as $param) {
            $pname = $param->getName();
            if (array_key_exists($pname, $args)) {
                $positional[] = $this->coerce($args[$pname], $param->getType());
            } elseif ($param->isOptional()) {
                $positional[] = $param->getDefaultValue();
            } else {
                throw new \RuntimeException("Missing required argument '{$pname}' for tool '{$name}'");
            }
        }
        return $instance->{$info['method']}(...$positional);
    }

    /**
     * @return array<string, array{class: class-string, method: string, attr: McpTool, reflection: \ReflectionMethod}>
     */
    private function discover(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $out = [];
        foreach (self::TOOL_CLASSES as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $ref = new \ReflectionClass($class);
            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                $attrs = $m->getAttributes(McpTool::class);
                if ($attrs === []) {
                    continue;
                }
                /** @var McpTool $attr */
                $attr = $attrs[0]->newInstance();
                $out[$attr->name] = [
                    'class'      => $class,
                    'method'     => $m->getName(),
                    'attr'       => $attr,
                    'reflection' => $m,
                ];
            }
        }
        $this->cache = $out;
        return $out;
    }

    private function parametersForOllama(\ReflectionMethod $m): array
    {
        $properties = [];
        $required   = [];
        foreach ($m->getParameters() as $p) {
            $type = $p->getType();
            $jsonType = $this->phpToJsonType($type);
            $schema   = ['type' => $jsonType];
            if ($jsonType === 'array') {
                $schema['items'] = ['type' => 'string'];
            }
            $properties[$p->getName()] = $schema;
            if (!$p->isOptional() && !($type instanceof \ReflectionNamedType && $type->allowsNull())) {
                $required[] = $p->getName();
            }
        }
        $params = ['type' => 'object', 'properties' => (object) $properties];
        if ($required !== []) {
            $params['required'] = $required;
        }
        return $params;
    }

    private function phpToJsonType(?\ReflectionType $type): string
    {
        if (!$type instanceof \ReflectionNamedType) {
            return 'string';
        }
        return match ($type->getName()) {
            'int'             => 'integer',
            'float', 'double' => 'number',
            'bool', 'boolean' => 'boolean',
            'array'           => 'array',
            default           => 'string',
        };
    }

    private function coerce(mixed $value, ?\ReflectionType $type): mixed
    {
        if (!$type instanceof \ReflectionNamedType || $value === null) {
            return $value;
        }
        return match ($type->getName()) {
            'int'             => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => is_string($value) ? in_array(strtolower($value), ['1','true','yes','on'], true) : (bool) $value,
            'array'           => is_array($value) ? $value : (is_string($value) ? (json_decode($value, true) ?? [$value]) : [$value]),
            default           => (string) $value,
        };
    }
}
