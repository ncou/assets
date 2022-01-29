<?php

declare(strict_types=1);

namespace Chiron\Assets;

use RuntimeException;

/**
 * AssetManager manages asset configuration and loading.
 *
 * @psalm-type CssFile = array{0:string,1?:int}&array
 * @psalm-type CssString = array{0:mixed,1?:int}&array
 * @psalm-type JsFile = array{0:string,1?:int}&array
 * @psalm-type JsString = array{0:mixed,1?:int}&array
 * @psalm-type JsVar = array{0:string,1:mixed,2?:int}
 * @psalm-type CustomizedBundles = array<string, AssetBundle|array<string, mixed>|false>
 */
final class AssetManager
{
    /**
     * @var string[] List of names of allowed asset bundles. If the array is empty, then any asset bundles are allowed.
     */
    private array $allowedBundleNames;

    /**
     * @var array The asset bundle configurations. This property is provided to customize asset bundles.
     * @psalm-var CustomizedBundles
     */
    private array $customizedBundles;

    /**
     * @var AssetBundle[] list of the registered asset bundles.
     * The keys are the bundle names, and the values are the registered {@see AssetBundle} objects.
     *
     * {@see registerAssetBundle()}
     *
     * @psalm-var array<string, AssetBundle>
     */
    private array $registeredBundles = [];

    /**
     * @var true[] List of the asset bundles in register process. Use for detect circular dependency.
     * @psalm-var array<string, true>
     */
    private array $bundlesInRegisterProcess = [];

    /**
     * @var AssetBundle[]
     * @psalm-var array<string, AssetBundle>
     */
    private array $loadedBundles = [];

    /**
     * @var AssetBundle[]
     * @psalm-var array<string, AssetBundle>
     */
    private array $dummyBundles = [];

    private ?AssetPublisher $publisher = null;

    /**
     * @param Aliases $aliases The aliases instance.
     * @param AssetLoaderInterface $loader The loader instance.
     * @param string[] $allowedBundleNames List of names of allowed asset bundles. If the array is empty, then any
     * asset bundles are allowed. If the names of allowed asset bundles were specified, only these asset bundles
     * or their dependencies can be registered {@see register()} and obtained {@see getBundle()}. Also, specifying
     * names allows to export {@see export()} asset bundles automatically without first registering them manually.
     * @param array $customizedBundles The asset bundle configurations. Provided to customize asset bundles.
     * When a bundle is being loaded by {@see getBundle()}, if it has a corresponding configuration specified
     * here, the configuration will be applied to the bundle. The array keys are the asset class bundle names
     * (without leading backslash). If a value is false, it means the corresponding asset bundle is disabled
     * and {@see getBundle()} should return an instance of the specified asset bundle with empty property values.
     *
     * @psalm-param CustomizedBundles $customizedBundles
     */
    public function __construct(AssetPublisher $publisher)
    {
        $this->publisher = $publisher;
    }

    /**
     * Registers asset bundle by name.
     *
     * @param string $name The class name of the asset bundle (without the leading backslash).
     * @param int|null $jsPosition {@see AssetBundle::$jsPosition}
     * @param int|null $cssPosition {@see AssetBundle::$cssPosition}
     *
     * @throws InvalidConfigException
     * @throws RuntimeException
     */
    public function register(string $name, ?int $jsPosition = null, ?int $cssPosition = null): void
    {
        $this->registerAssetBundle($name, $jsPosition, $cssPosition);

    }

    /**
     * Registers the named asset bundle.
     *
     * All dependent asset bundles will be registered.
     *
     * @param string $name The class name of the asset bundle (without the leading backslash).
     * @param int|null $jsPosition If set, this forces a minimum position for javascript files.
     * This will adjust depending assets javascript file position or fail if requirement can not be met.
     * If this is null, asset bundles position settings will not be changed.
     *
     * {@see AssetRegistrar::registerJsFile()} For more details on javascript position.
     *
     * @throws InvalidConfigException If the asset or the asset file paths to be published does not exist.
     * @throws RuntimeException If the asset bundle does not exist or a circular dependency is detected.
     */
    private function registerAssetBundle(string $name, ?int $jsPosition = null, ?int $cssPosition = null): void
    {
        if (isset($this->bundlesInRegisterProcess[$name])) {
            throw new RuntimeException("A circular dependency is detected for bundle \"{$name}\".");
        }

        if (!isset($this->registeredBundles[$name])) {
            $bundle = $this->publishBundle($this->loadBundle($name));

            $this->bundlesInRegisterProcess[$name] = true;

            /** @var string $dep */
            foreach ($bundle->depends as $dep) {
                $this->registerAssetBundle($dep, $bundle->jsPosition, $bundle->cssPosition);
            }

            unset(
                $this->bundlesInRegisterProcess[$name], // Remove bundle from list bundles in register process
                $this->registeredBundles[$name], // Remove bundle from registered bundles for add him to end of list in next code
            );

            $this->registeredBundles[$name] = $bundle;
        } else {
            $bundle = $this->registeredBundles[$name];
        }

        if ($jsPosition !== null || $cssPosition !== null) {
            if ($jsPosition !== null) {
                if ($bundle->jsPosition === null) {
                    $bundle->jsPosition = $jsPosition;
                } elseif ($bundle->jsPosition > $jsPosition) {
                    throw new RuntimeException(
                        "An asset bundle that depends on \"{$name}\" has a higher JavaScript file " .
                        "position configured than \"{$name}\"."
                    );
                }
            }

            if ($cssPosition !== null) {
                if ($bundle->cssPosition === null) {
                    $bundle->cssPosition = $cssPosition;
                } elseif ($bundle->cssPosition > $cssPosition) {
                    throw new RuntimeException(
                        "An asset bundle that depends on \"{$name}\" has a higher CSS file " .
                        "position configured than \"{$name}\"."
                    );
                }
            }

            // update position for all dependencies
            /** @var string $dep */
            foreach ($bundle->depends as $dep) {
                $this->registerAssetBundle($dep, $bundle->jsPosition, $bundle->cssPosition);
            }
        }
    }

    /**
     * Publishes a asset bundle.
     *
     * @param AssetBundle $bundle The asset bundle to publish.
     *
     * @throws InvalidConfigException If the asset or the asset file paths to be published does not exist.
     *
     * @return AssetBundle The published asset bundle.
     */
    private function publishBundle(AssetBundle $bundle): AssetBundle
    {
        if (!$bundle->cdn && $this->publisher !== null && !empty($bundle->sourcePath)) {
            [$bundle->basePath, $bundle->baseUrl] = $this->publisher->publish($bundle);
        }

        return $bundle;
    }

    /**
     * Loads an asset bundle class by name.
     *
     * @param string $name The asset bundle name.
     *
     * @throws InvalidConfigException For invalid asset bundle configuration.
     *
     * @return AssetBundle The asset bundle instance.
     */
    private function loadBundle(string $name): AssetBundle
    {
        if (isset($this->loadedBundles[$name])) {
            return $this->loadedBundles[$name];
        }

        /** @psalm-suppress UnsafeInstantiation */
        return $this->loadedBundles[$name] = is_subclass_of($name, AssetBundle::class) ? new $name() : new AssetBundle();











        if (!isset($this->customizedBundles[$name])) {
            return $this->loadedBundles[$name] = $this->loader->loadBundle($name);
        }

        if ($this->customizedBundles[$name] instanceof AssetBundle) {
            return $this->loadedBundles[$name] = $this->customizedBundles[$name];
        }

        if (is_array($this->customizedBundles[$name])) {
            return $this->loadedBundles[$name] = $this->loader->loadBundle($name, $this->customizedBundles[$name]);
        }

        /** @psalm-suppress RedundantConditionGivenDocblockType */
        if ($this->customizedBundles[$name] === false) {
            /** @psalm-suppress MixedArgumentTypeCoercion */
            return $this->dummyBundles[$name] ??= $this->loader->loadBundle($name, (array) (new AssetBundle()));
        }

        throw new InvalidConfigException("Invalid configuration of the \"{$name}\" asset bundle.");
    }


}
