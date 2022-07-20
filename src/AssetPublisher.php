<?php

declare(strict_types=1);

namespace Chiron\Assets;

use Chiron\Core\Directories;
use Chiron\Filesystem\Filesystem;
use RuntimeException;

/**
 * AssetPublisher is responsible for executing the publication of the assets
 * from {@see AssetBundle::$sourcePath} to {@see AssetBundle::$basePath}.
 *
 * @psalm-type HashCallback = callable(string):string
 * @psalm-type PublishedBundle = array{0:non-empty-string,1:non-empty-string}
 */
final class AssetPublisher
{
    private Directories $aliases;

    /**
     * @var bool Whether the directory being published should be copied even if it is found in the target directory.
     */
    private bool $forceCopy;

    /**
     * @var bool Whether to use symbolic link to publish asset files.
     */
    private bool $linkAssets; // TODO : à conserver ???

    /**
     * @var int The permission to be set for newly generated asset directories.
     */
    private int $dirMode = 0775;

    /**
     * @var int The permission to be set for newly published asset files.
     */
    private int $fileMode = 0755;

    /**
     * @var callable|null A callback that will be called to produce hash for asset directory generation.
     * @psalm-var HashCallback|null
     */
    private $hashCallback = null; // TODO : à virer ????

    /**
     * @var array Contain published {@see AssetsBundle}.
     * @psalm-var PublishedBundle[]
     */
    private array $published = [];

    /**
     * @param Aliases $aliases The aliases instance.
     * @param bool $forceCopy Whether the directory being published should be copied even
     * if it is found in the target directory. See {@see withForceCopy()}.
     * @param bool $linkAssets Whether to use symbolic link to publish asset files. See {@see withLinkAssets()}.
     */
    public function __construct(Directories $aliases, bool $forceCopy = false, bool $linkAssets = false)
    {
        $this->aliases = $aliases;
        $this->forceCopy = $forceCopy;
        $this->linkAssets = $linkAssets;
    }

    // TODO : ajouter la phpDoc !!!
    public function publish(AssetBundle $bundle): array
    {
        if (empty($bundle->sourcePath)) {
            throw new InvalidConfigException(
                'The sourcePath must be defined in AssetBundle property public ?string $sourcePath = $path.',
            );
        }

        $sourcePath = $this->aliases->get($bundle->sourcePath);

        if (isset($this->published[$sourcePath])) {
            return $this->published[$sourcePath];
        }

        if (empty($bundle->basePath)) {
            throw new InvalidConfigException(
                'The basePath must be defined in AssetBundle property public ?string $basePath = $path.',
            );
        }

        if ($bundle->baseUrl === null) {
            throw new InvalidConfigException(
                'The baseUrl must be defined in AssetBundle property public ?string $baseUrl = $path.',
            );
        }

        if (!file_exists($sourcePath)) {
            throw new InvalidConfigException("The sourcePath to be published does not exist: {$sourcePath}");
        }

        return $this->published[$sourcePath] = $this->publishBundleDirectory($bundle);
    }

    public function getPublishedPath(string $sourcePath): ?string
    {
        $sourcePath = $this->aliases->get($sourcePath);

        if (isset($this->published[$sourcePath])) {
            return $this->published[$sourcePath][0];
        }

        return null;
    }

    public function getPublishedUrl(string $sourcePath): ?string
    {
        $sourcePath = $this->aliases->get($sourcePath);

        if (isset($this->published[$sourcePath])) {
            return $this->published[$sourcePath][1];
        }

        return null;
    }

    /**
     * Returns a new instance with the specified directory mode.
     *
     * @param int $dirMode The permission to be set for newly generated asset directories. This value will be used
     * by PHP `chmod()` function. No umask will be applied. Defaults to 0775, meaning the directory is read-writable
     * by owner and group, but read-only for other users.
     */
    public function withDirMode(int $dirMode): self
    {
        $new = clone $this;
        $new->dirMode = $dirMode;
        return $new;
    }

    /**
     * Returns a new instance with the specified files mode.
     *
     * @param int $fileMode he permission to be set for newly published asset files. This value will be used
     * by PHP `chmod()` function. No umask will be applied. If not set, the permission will be determined
     * by the current environment.
     */
    public function withFileMode(int $fileMode): self
    {
        $new = clone $this;
        $new->fileMode = $fileMode;
        return $new;
    }

    /**
     * Returns a new instance with the specified force copy value.
     *
     * @param bool $forceCopy Whether the directory being published should be copied even if it is found in the target
     * directory. This option is used only when publishing a directory. You may want to set this to be `true` during
     * the development stage to make sure the published directory is always up-to-date. Do not set this to `true`
     * on production servers as it will significantly degrade the performance.
     */
    public function withForceCopy(bool $forceCopy): self
    {
        $new = clone $this;
        $new->forceCopy = $forceCopy;
        return $new;
    }

    /**
     * Returns a new instance with the specified force hash callback.
     *
     * @param callable $hashCallback A callback that will be called to produce hash for asset directory generation.
     * The signature of the callback should be as follows:
     *
     * ```
     * function (string $path): string;
     * ```
     *
     * Where `$path` is the asset path. Note that the `$path` can be either directory where the asset files reside or a
     * single file. For a CSS file that uses relative path in `url()`, the hash implementation should use the directory
     * path of the file instead of the file path to include the relative asset files in the copying.
     *
     * If this is not set, the asset manager will use the default CRC32 and filemtime in the `hash` method.
     *
     * Example of an implementation using MD4 hash:
     *
     * ```php
     * function (string $path): string {
     *     return hash('md4', $path);
     * }
     * ```
     * @psalm-param HashCallback $hashCallback
     */
    public function withHashCallback(callable $hashCallback): self
    {
        $new = clone $this;
        $new->hashCallback = $hashCallback;
        return $new;
    }

    /**
     * Returns a new instance with the specified link assets value.
     *
     * @param bool $linkAssets Whether to use symbolic link to publish asset files. Default is `false`,
     * meaning asset files are copied to {@see AssetBundle::$basePath}. Using symbolic links has the benefit
     * that the published assets will always be consistent with the source assets and there is no copy
     * operation required. This is especially useful during development.
     *
     * However, there are special requirements for hosting environments in order to use symbolic links. In particular,
     * symbolic links are supported only on Linux/Unix, and Windows Vista/2008 or greater.
     *
     * Moreover, some Web servers need to be properly configured so that the linked assets are accessible to Web users.
     * For example, for Apache Web server, the following configuration directive should be added for the Web folder:
     *
     * ```apache
     * Options FollowSymLinks
     * ```
     */
    public function withLinkAssets(bool $linkAssets): self
    {
        $new = clone $this;
        $new->linkAssets = $linkAssets;
        return $new;
    }

    /**
     * Publishes a bundle directory.
     *
     * @param AssetBundle $bundle The asset bundle instance.
     *
     * @throws Exception If the asset to be published does not exist.
     *
     * @return array The path directory and the URL that the asset is published as.
     *
     * @psalm-return PublishedBundle
     */
    private function publishBundleDirectory(AssetBundle $bundle): array
    {


        $fs = new Filesystem(); // TODO : le mettre en variable privé de classe initialisé dans le constructeur !!!!





        $src = $this->aliases->get((string) $bundle->sourcePath);
        $dir = $this->hash($src);
        $dstDir = "{$this->aliases->get((string) $bundle->basePath)}/{$dir}";

        if ($this->linkAssets) {
            if (!is_dir($dstDir)) {
                $fs->ensureDirectoryExists(dirname($dstDir), $this->dirMode);
                try { // fix #6226 symlinking multi threaded
                    symlink($src, $dstDir);
                } catch (Exception $e) {
                    if (!is_dir($dstDir)) {
                        throw $e;
                    }
                }
            }
        } elseif (
            !empty($bundle->publishOptions['forceCopy'])
            || ($this->forceCopy && !isset($bundle->publishOptions['forceCopy']))
            || !is_dir($dstDir)
        ) {
            $fs->copy($src, $dstDir); // TODO : attention de vérifier si le flag forceCopy fonctionne !!!!
        }

        return [$dstDir, "{$this->aliases->get((string) $bundle->baseUrl)}/{$dir}"];
    }

    /**
     * Generate a CRC32 hash for the directory path.
     * Collisions are higher than MD5 but generates a much smaller hash string.
     *
     * @param string $path The string to be hashed.
     *
     * @return string The hashed string.
     */
    private function hash(string $path): string
    {
        if (is_callable($this->hashCallback)) {
            return ($this->hashCallback)($path);
        }



        $fs = new Filesystem();


        // TODO : vérifier mais normalement on recois uniquement un fichier (donc is_file toujours à true), donc il suffit de récupérer le "filemtime($path)" pour avoir un numérique qui servira de hash et donc pas besoin de faire un crc32 d'une string. ATTENTION pour cela il faut virer la notion de "linkAssets" dans cette classe, je ne pense pas qu'on utilisera cette fonctionnalité !!!!

        $path = (is_file($path) ? dirname($path) : $path) . $fs->lastModifiedTime($path);

        return sprintf('%x', crc32($path . '|' . $this->linkAssets)); // TODO : faire un hash('crc32b') ????
    }
}
