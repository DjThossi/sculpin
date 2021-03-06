<?php

declare(strict_types=1);

/*
 * This file is a part of Sculpin.
 *
 * (c) Dragonfly Development Inc.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sculpin\Bundle\PaginationBundle;

use Sculpin\Core\DataProvider\DataProviderManager;
use Sculpin\Core\Generator\GeneratorInterface;
use Sculpin\Core\Source\SourceInterface;
use Sculpin\Core\Permalink\SourcePermalinkFactory;

/**
 * Pagination Generator.
 *
 * @author Beau Simensen <beau@dflydev.com>
 */
class PaginationGenerator implements GeneratorInterface
{
    /**
     * Data Provider Manager
     *
     * @var DataProviderManager
     */
    protected $dataProviderManager;

    /**
     * Source permalink factory
     *
     * @var SourcePermalinkFactory
     */
    protected $permalinkFactory;

    /**
     * Max per page (default)
     *
     * @var int
     */
    protected $maxPerPage;

    /**
     * Constructor
     *
     * @param DataProviderManager    $dataProviderManager Data Provider Manager
     * @param SourcePermalinkFactory $permalinkFactory    Factory for generating permalinks
     * @param int                    $maxPerPage          Max items per page
     */
    public function __construct(DataProviderManager $dataProviderManager, SourcePermalinkFactory $permalinkFactory, $maxPerPage)
    {
        $this->dataProviderManager = $dataProviderManager;
        $this->permalinkFactory = $permalinkFactory;
        $this->maxPerPage = $maxPerPage;
    }

    /**
     * {@inheritdoc}
     */
    public function generate(SourceInterface $source)
    {
        $data = null;
        $config = $source->data()->get('pagination') ?: array();
        if (!isset($config['provider'])) {
            $config['provider'] = 'data.posts';
        }
        if (preg_match('/^(data|page|filtered)\.(.+)$/', $config['provider'], $matches)) {
            switch ($matches[1]) {
                case 'data':
                    $data = $this->dataProviderManager->dataProvider($matches[2])->provideData();
                    if (!count($data)) {
                        $data = array('');
                    }
                    break;
                case 'page':
                    $data = $source->data()->get($matches[2]);
                    break;
                case 'filtered':
                    $data = $this->filtered($matches);
                    break;
            }
        }

        if (null === $data) {
            return [];
        }

        $maxPerPage = isset($config['max_per_page']) ? $config['max_per_page'] : $this->maxPerPage;

        $slices = array();
        $slice = array();
        $totalItems = 0;
        foreach ($data as $k => $v) {
            if (count($slice) == $maxPerPage) {
                $slices[] = $slice;
                $slice = array();
            }

            $slice[$k] = $v;
            $totalItems++;
        }

        if (count($slice)) {
            $slices[] = $slice;
        }

        $sources = array();
        $pageNumber = 0;
        foreach ($slices as $slice) {
            $pageNumber++;
            $permalink = null;
            if ($pageNumber > 1) {
                $permalink = $this->permalinkFactory->create($source)->relativeFilePath();
                $basename = basename($permalink);
                if (preg_match('/^(.+?)\.(.+)$/', $basename, $matches)) {
                    if ('index' === $matches[1]) {
                        $paginatedPage = '';
                        $index = '/index';
                    } else {
                        $paginatedPage = $matches[1].'/';
                        $index = '';
                    }
                    $permalink = dirname($permalink).'/'.$paginatedPage.'page/'.$pageNumber.$index.'.'.$matches[2];
                } else {
                    $permalink = dirname($permalink).'/'.$basename.'/page/'.$pageNumber.'.html';
                }

                if (0 === strpos($permalink, './')) {
                    $permalink = substr($permalink, 2);
                }
            }

            $generatedSource = $source->duplicate(
                $source->sourceId().':page='.$pageNumber
            );

            if (null !== $permalink) {
                $generatedSource->data()->set('permalink', $permalink);
            }

            $generatedSource->data()->set('pagination.items', $slice);
            $generatedSource->data()->set('pagination.page', $pageNumber);
            $generatedSource->data()->set('pagination.total_pages', count($slices));
            $generatedSource->data()->set('pagination.total_items', $totalItems);

            $sources[] = $generatedSource;
        }

        for ($i = 0; $i < count($sources); $i++) {
            $generatedSource = $sources[$i];
            if (0 === $i) {
                $generatedSource->data()->set('pagination.previous_page', null);
            } else {
                $generatedSource->data()->set('pagination.previous_page', $sources[$i-1]);
            }

            if ($i + 1 < count($sources)) {
                $generatedSource->data()->set('pagination.next_page', $sources[$i+1]);
            } else {
                $generatedSource->data()->set('pagination.next_page', null);
            }
        }

        return $sources;
    }

    /**
     * @param $matches
     *
     * @return array
     */
    private function filtered($matches)
    {
        $parts = explode('.', $matches[2]);
        $name = $parts[0];
        $expectedKey = $parts[1];
        $expectedValue = $parts[2];
        if ($expectedValue === 'true') {
            $expectedValue = '1';
        }

        if ($expectedValue === 'false') {
            $expectedValue = '';
        }

        $data = $this->dataProviderManager->dataProvider($name)->provideData();
        if (!count($data)) {
            $data = array();
        }

        $filteredData = array();
        foreach ($data as $key => $page) {
            if (is_string($page->meta()[$expectedKey]) && $page->meta()[$expectedKey] === $expectedValue) {
                $filteredData[] = $data[$key];
                continue;
            }

            if (is_array($page->meta()[$expectedKey]) && in_array($expectedValue, $page->meta()[$expectedKey])) {
                $filteredData[] = $data[$key];
                continue;
            }
        }

        return $filteredData;
    }
}
