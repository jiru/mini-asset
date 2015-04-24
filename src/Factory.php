<?php
/**
 * MiniAsset
 * Copyright (c) Mark Story (http://mark-story.com)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Mark Story (http://mark-story.com)
 * @since         0.0.1
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace MiniAsset;

use MiniAsset\AssetCollection;
use MiniAsset\AssetCompiler;
use MiniAsset\AssetConfig;
use MiniAsset\AssetTarget;
use MiniAsset\File\Local;
use MiniAsset\File\Remote;
use MiniAsset\File\Glob;
use MiniAsset\Filter\FilterRegistry;
use MiniAsset\Output\AssetCacher;
use MiniAsset\Output\AssetWriter;
use RuntimeException;

/**
 * A factory for various object using a config file.
 *
 * This class can make AssetCollections and FilterCollections based
 * on the configuration object passed to it.
 */
class Factory
{
    /**
     * The config instance to make objects based on.
     *
     * @var MiniAsset\AssetConfig
     */
    protected $config;

    /**
     * Constructor
     *
     * @param MiniAsset\AssetConfig $config
     */
    public function __construct(AssetConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Create an AssetCompiler
     *
     * @param bool $debug Whether or not to enable debugging mode for the compiler.
     * @return MiniAsset\AssetCompiler
     */
    public function compiler($debug = false)
    {
        return new AssetCompiler($this->filterRegistry(), $debug);
    }

    /**
     * Create an AssetWriter
     *
     * @param string $tmpPath The path where the build timestamp lookup should be stored.
     * @return MiniAsset\AssetWriter
     */
    public function writer($tmpPath = '')
    {
        if (!$tmpPath) {
            $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
        }
        $timestamp = [
            'js' => $this->config->get('js.timestamp'),
            'css' => $this->config->get('css.timestamp'),
        ];
        $writer = new AssetWriter($timestamp, $tmpPath, $this->config->theme());
        $writer->configTimestamp($this->config->modifiedTime());
        $writer->filterRegistry($this->filterRegistry());
        return $writer;
    }

    /**
     * Create an AssetCacher
     *
     * @param string $path The path to cache assets into.
     * @return MiniAsset\AssetCacher
     */
    public function cacher($path = '')
    {
        if (!$path) {
            $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
        }
        $cache = new AssetCacher($path, $this->config->theme());
        $cache->configTimestamp($this->config->modifiedTime());
        $cache->filterRegistry($this->filterRegistry());
        return $cache;
    }

    /**
     * Create an AssetCollection with all the configured assets.
     *
     * @return MiniAsset\AssetCollection
     */
    public function assetCollection()
    {
        $assets = $this->config->targets();
        return new AssetCollection($assets, $this);
    }

    /**
     * Create a new scanner instance for the provided paths.
     *
     * @param array $paths The paths to scan.
     * @return MiniAsset\AssetScanner
     */
    public function scanner($paths)
    {
        return new AssetScanner($paths, $this->config->theme());
    }

    /**
     * Create a single build target
     *
     * @param string $name The name of the target to build
     * @return MiniAsset\AssetTarget
     */
    public function target($name)
    {
        $ext = $this->config->getExt($name);

        $paths = $this->config->paths($ext, $name);
        $themed = $this->config->isThemed($name);
        $filters = $this->config->targetFilters($name);
        $target = $this->config->cachePath($ext) . $name;

        $files = [];
        $scanner = $this->scanner($paths);
        foreach ($this->config->files($name) as $file) {
            if (preg_match('#^https?://#', $file)) {
                $files[] = new Remote($file);
            } else {
                if (preg_match('/(.*\/)(\*.*?)$/U', $file, $matches)) {
                    $path = $scanner->find($matches[1]);
                    if ($path === false) {
                        throw new RuntimeException("Could not locate folder $file for $name in any configured path.");
                    }

                    $glob = new Glob($path, $matches[2]);
                    $files = array_merge($files, $glob->files());
                } else {
                    $path = $scanner->find($file);
                    if ($path === false) {
                        throw new RuntimeException("Could not locate $file for $name in any configured path.");
                    }
                    $files[] = new Local($path);
                }
            }
        }

        return new AssetTarget($target, $files, $filters, $paths, $themed);
    }

    /**
     * Create a filter registry containing all the configured filters.
     *
     * @return MiniAsset\Filter\FilterRegistry
     */
    public function filterRegistry()
    {
        $filters = [];
        foreach ($this->config->allFilters() as $name) {
            $filters[$name] = $this->buildFilter($name, $this->config->filterConfig($name));
        }
        return new FilterRegistry($filters);
    }

    /**
     * Create a single filter
     *
     * @param string $name The name of the filter to build.
     * @param array $config The configuration for the filter.
     * @return MiniAsset\Filter\AssetFilterInterface
     */
    protected function buildFilter($name, $config)
    {
        $className = $name;
        if (!class_exists($className)) {
            $className = 'MiniAsset\Filter\\' . $name;
        }
        if (!class_exists($className)) {
            throw new RuntimeException(sprintf('Cannot load filter "%s".', $name));
        }
        $filter = new $className();
        $filter->settings($config);
        return $filter;
    }
}