<?php
// Path: _core/Container.php
/**
 * -----------------------------------------------------------------------------
 * Lightweight Service Container 📦
 * -----------------------------------------------------------------------------
 * Simple dependency injection container for managing service instances and
 * factories. Designed to coexist with the existing static singleton pattern,
 * enabling a gradual migration toward injectable dependencies.
 *
 * Usage:
 *   $c = Container::getInstance();
 *   $c->set('db', fn() => new mysqli(...));
 *   $db = $c->get('db'); // lazy instantiation, cached
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.1
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/81
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Container
{
    /** @var self|null Singleton instance */
    private static ?self $instance = null;

    /** @var array<string, callable> Factory closures keyed by service name */
    private array $factories = [];

    /** @var array<string, mixed> Resolved instances (singletons within container) */
    private array $resolved = [];

    /**
     * 📦 Get the global container instance.
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 📋 Register a service factory.
     * The factory is called once on first `get()`, then the result is cached.
     *
     * @param string   $name    Service identifier
     * @param callable $factory Closure that returns the service instance
     * @return void
     */
    public function set(string $name, callable $factory): void
    {
        $this->factories[$name] = $factory;
        // 📋 Clear any previously resolved instance so new factory takes effect
        unset($this->resolved[$name]);
    }

    /**
     * 📋 Register a pre-built instance directly (no factory needed).
     *
     * @param string $name     Service identifier
     * @param mixed  $instance The service instance
     * @return void
     */
    public function instance(string $name, mixed $instance): void
    {
        $this->resolved[$name] = $instance;
    }

    /**
     * 📋 Retrieve a service by name.
     * Resolves the factory on first call, then returns the cached instance.
     *
     * @param string $name Service identifier
     * @return mixed
     * @throws \RuntimeException If the service is not registered
     */
    public function get(string $name): mixed
    {
        // 📋 Return cached instance if already resolved
        if (array_key_exists($name, $this->resolved) === true) {
            return $this->resolved[$name];
        }

        // 📋 Resolve from factory
        if (array_key_exists($name, $this->factories) === true) {
            $this->resolved[$name] = ($this->factories[$name])($this);
            return $this->resolved[$name];
        }

        throw new \RuntimeException('Service "' . $name . '" is not registered in the container.');
    }

    /**
     * 📋 Check if a service is registered (factory or instance).
     *
     * @param string $name Service identifier
     * @return bool
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->resolved) === true
            || array_key_exists($name, $this->factories) === true;
    }

    /**
     * 🔄 Reset the container (useful for testing).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * 🚫 Prevent cloning.
     */
    private function __clone()
    {
    }

    /**
     * 🚫 Private constructor — use getInstance().
     */
    private function __construct()
    {
    }
}
