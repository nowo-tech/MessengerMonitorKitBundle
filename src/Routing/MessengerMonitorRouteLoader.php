<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Routing;

use Nowo\MessengerMonitorKitBundle\Controller\DashboardController;
use Nowo\MessengerMonitorKitBundle\Controller\HistoryController;
use Nowo\MessengerMonitorKitBundle\Controller\SettingsController;
use Nowo\MessengerMonitorKitBundle\Controller\TransportsController;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Attribute\Route as RouteAttribute;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function is_string;
use function rtrim;

/**
 * Loads the admin routes (`type: nowo_messenger_monitor_kit`) when the web UI is enabled.
 *
 * The routes are declared with #[Route] on the bundle controllers (path relative to the prefix, name, methods,
 * requirements); this loader reads those attributes and prepends `web_ui.path_prefix`, so the prefix stays
 * configurable while the definitions live on the controllers (REQ-SF-004). The controllers are not autoconfigured, so
 * a host `routing.controllers` import never exposes them (REQ-SF-004 rule 7).
 */
final class MessengerMonitorRouteLoader extends Loader
{
    /** Routing resource type. */
    public const TYPE = 'nowo_messenger_monitor_kit';

    /** Controllers whose #[Route] methods are loaded. */
    public const CONTROLLERS = [DashboardController::class, HistoryController::class, TransportsController::class, SettingsController::class];

    private bool $loaded = false;

    /**
     * @param bool $webUiEnabled `web_ui.enabled`
     * @param string $pathPrefix `web_ui.path_prefix`
     */
    public function __construct(
        private readonly bool $webUiEnabled,
        private readonly string $pathPrefix,
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    /**
     * Builds the route collection (once per router warm-up).
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new RuntimeException('Do not add the "nowo_messenger_monitor_kit" route loader twice.');
        }
        // @igor-ignore - Route loader builds routes at cache warm-up; not per-request state.
        $this->loaded = true;

        $collection = new RouteCollection();
        if (!$this->webUiEnabled) {
            return $collection;
        }

        $prefix = rtrim($this->pathPrefix, '/');
        foreach (self::CONTROLLERS as $controller) {
            foreach ((new ReflectionClass($controller))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(RouteAttribute::class) as $attribute) {
                    $declared = $attribute->newInstance();
                    $path     = is_string($declared->path) ? $declared->path : '';
                    $collection->add((string) $declared->name, new Route(
                        $prefix . $path,
                        ['_controller' => $controller . '::' . $method->getName()] + $declared->defaults,
                        $declared->requirements,
                        methods: $declared->methods,
                    ));
                }
            }
        }

        return $collection;
    }

    /**
     * Supports the `nowo_messenger_monitor_kit` type.
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
