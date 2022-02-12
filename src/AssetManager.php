<?php

declare(strict_types=1);

namespace Chiron\Assets;

use RuntimeException;

// TODO : regarder ici pour faire un minify des .js et .css ainsi qu'un join des fichiers !!!!
//https://github.com/fisharebest/laravel-assets

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
     * @psalm-var CssFile[]
     */
    private array $cssFiles = [];

    /**
     * @psalm-var JsFile[]
     */
    private array $jsFiles = [];

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
        $this->registerFiles($name);
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


        // TODO : code ci-dessous à virer !!!!








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



    /**
     * Register assets from a named bundle and its dependencies.
     *
     * @param string $bundleName The asset bundle name.
     *
     * @throws InvalidConfigException If asset files are not found.
     */
    private function registerFiles(string $bundleName): void
    {
        $bundle = $this->registeredBundles[$bundleName];

        /** @var string $dep */
        foreach ($bundle->depends as $dep) {
            $this->registerFiles($dep);
        }

        $this->registrarRegister($bundle);
    }


    /**
     * Registers assets from a bundle considering dependencies.
     *
     * @throws InvalidConfigException If asset files are not found.
     */
    private function registrarRegister(AssetBundle $bundle): void
    {
        /** @var JsFile|string $js */
        foreach ($bundle->js as $key => $js) {
            $this->registerJsFile(
                $bundle,
                is_string($key) ? $key : null,
                $js,
            );
        }

        /** @var CssFile|string $css */
        foreach ($bundle->css as $key => $css) {
            $this->registerCssFile(
                $bundle,
                is_string($key) ? $key : null,
                $css,
            );
        }
    }


    /**
     * Registers a JavaScript file.
     *
     * @param array|string $js
     *
     * @throws InvalidConfigException
     */
    private function registerJsFile(AssetBundle $bundle, ?string $key, $js): void
    {
        if (is_array($js)) {
            if (!array_key_exists(0, $js)) {
                throw new InvalidConfigException('Do not set in array JavaScript URL.');
            }
            $url = $js[0];
        } else {
            $url = $js;
        }

        if (!is_string($url)) {
            throw new InvalidConfigException(
                sprintf(
                    'JavaScript file should be string. Got %s.',
                    $this->getType($url),
                )
            );
        }

        if ($url === '') {
            throw new InvalidConfigException('JavaScript file should be non empty string.');
        }

        $url = $this->loaderGetAssetUrl($bundle, $url);

        if (is_array($js)) {
            $js[0] = $url;
        } else {
            $js = [$url];
        }

        if ($bundle->jsPosition !== null && !isset($js[1])) {
            $js[1] = $bundle->jsPosition;
        }

        /** @psalm-var JsFile */
        $js = $this->mergeOptionsWithArray($bundle->jsOptions, $js);

        $this->jsFiles[$key ?: $url] = $js;
    }

    /**
     * Registers a CSS file.
     *
     * @param array|string $css
     *
     * @throws InvalidConfigException
     */
    private function registerCssFile(AssetBundle $bundle, ?string $key, $css): void
    {
        if (is_array($css)) {
            if (!array_key_exists(0, $css)) {
                throw new InvalidConfigException('Do not set in array CSS URL.');
            }
            $url = $css[0];
        } else {
            $url = $css;
        }

        if (!is_string($url)) {
            throw new InvalidConfigException(
                sprintf(
                    'CSS file should be string. Got %s.',
                    $this->getType($url),
                )
            );
        }

        if ($url === '') {
            throw new InvalidConfigException('CSS file should be non empty string.');
        }

        $url = $this->loaderGetAssetUrl($bundle, $url);

        if (is_array($css)) {
            $css[0] = $url;
        } else {
            $css = [$url];
        }

        if ($bundle->cssPosition !== null && !isset($css[1])) {
            $css[1] = $bundle->cssPosition;
        }

        /** @psalm-var CssFile */
        $css = $this->mergeOptionsWithArray($bundle->cssOptions, $css);

        $this->cssFiles[$key ?: $url] = $css;
    }

    public function loaderGetAssetUrl(AssetBundle $bundle, string $assetPath): string
    {
        if (!$bundle->cdn && empty($bundle->basePath)) {
            throw new InvalidConfigException(
                'basePath must be set in AssetLoader->withBasePath($path) or ' .
                'AssetBundle property public ?string $basePath = $path'
            );
        }

        if (!$bundle->cdn && $bundle->baseUrl === null) {
            throw new InvalidConfigException(
                'baseUrl must be set in AssetLoader->withBaseUrl($path) or ' .
                'AssetBundle property public ?string $baseUrl = $path'
            );
        }

        $asset = $this->UtilsResolveAsset($bundle, $assetPath, []); // TODO : voir pour gerer un array $this->assetMap au lieu d'un [] par défault !!!!

        if (!empty($asset)) {
            $assetPath = $asset;
        }

        if ($bundle->cdn) {
            return $bundle->baseUrl === null
                ? $assetPath
                : $bundle->baseUrl . '/' . $assetPath;
        }

        if (! $this->UtilsIsRelative($assetPath) || strncmp($assetPath, '/', 1) === 0) {
            return $assetPath;
        }

        //$path = "{$this->getBundleBasePath($bundle)}/{$assetPath}";
        //$url = "{$this->getBundleBaseUrl($bundle)}/{$assetPath}";

        $path = directory($bundle->basePath). '/' . $assetPath; // TODO : gérer un objet Directories directement dans cette classe pour faire un ->get()
        $url = directory($bundle->baseUrl). '/' . $assetPath; // TODO : gérer un objet Directories directement dans cette classe pour faire un ->get()

        if (!is_file($path)) {
            throw new InvalidConfigException("Asset files not found: \"{$path}\".");
        }

        return $url;
    }

    /**
     * @throws InvalidConfigException
     */
    // TODO : code bizarre !!! à améliorer je pense !!!!
    private function mergeOptionsWithArray(array $options, array $array): array
    {
        /** @var mixed $value */
        foreach ($options as $key => $value) {
            if (is_int($key)) {
                throw new InvalidConfigException(
                    'JavaScript or CSS options should be list of key/value pairs with string keys. Got integer key.'
                );
            }

            if (!array_key_exists($key, $array)) {
                /** @var mixed */
                $array[$key] = $value;
            }
        }

        return $array;
    }

    /**
     * Returns a value indicating whether a URL is relative.
     *
     * A relative URL does not have host info part.
     *
     * @param string $url The URL to be checked.
     *
     * @return bool Whether the URL is relative.
     */
    public function UtilsIsRelative(string $url): bool
    {
        return strncmp($url, '//', 2) && strpos($url, '://') === false;
    }

    /**
     * Resolves the actual URL for the specified asset.
     *
     * @param AssetBundle $bundle The asset bundle which the asset file belongs to.
     * @param string $assetPath The asset path. This should be one of the assets listed
     * in {@see AssetBundle::$js} or {@see AssetBundle::$css}.
     * @param array $assetMap Mapping from source asset files (keys) to target asset files (values)
     * {@see AssetPublisher::$assetMap}.
     *
     * @psalm-param array<string, string> $assetMap
     *
     * @return string|null The actual URL for the specified asset, or null if there is no mapping.
     */
    public function UtilsResolveAsset(AssetBundle $bundle, string $assetPath, array $assetMap): ?string
    {
        if (isset($assetMap[$assetPath])) {
            return $assetMap[$assetPath];
        }

        if (!empty($bundle->sourcePath) && $this->UtilsIsRelative($assetPath)) {
            $assetPath = $bundle->sourcePath . '/' . $assetPath;
        }

        $n = mb_strlen($assetPath, 'utf-8'); // TODO : ajouter dans le fichier composer qu'il faut supporter le php pluging/dll mb_string

        foreach ($assetMap as $from => $to) {
            $n2 = mb_strlen($from, 'utf-8');
            if ($n2 <= $n && substr_compare($assetPath, $from, $n - $n2, $n2) === 0) {
                return $to;
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    // TODO : utiliser directement la fonction get_debug_type() !!!!
    private function getType($value): string
    {
        return is_object($value) ? get_class($value) : gettype($value);
    }


    /**
     * @return array Config array of CSS files.
     * @psalm-return CssFile[]
     */
    public function getCssFiles(): array
    {
        return $this->cssFiles;
    }

    /**
     * @return array Config array of JavaScript files.
     * @psalm-return JsFile[]
     */
    public function getJsFiles(): array
    {
        return $this->jsFiles;
    }



}
