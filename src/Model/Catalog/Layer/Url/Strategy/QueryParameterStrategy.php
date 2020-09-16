<?php
/**
 * Tweakwise & Emico (https://www.tweakwise.com/ & https://www.emico.nl/) - All Rights Reserved
 *
 * @copyright Copyright (c) 2017-2019 Tweakwise.com B.V. (https://www.tweakwise.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Emico\Tweakwise\Model\Catalog\Layer\Url\Strategy;

use Emico\Tweakwise\Model\Catalog\Layer\Filter\Item;
use Emico\Tweakwise\Model\Catalog\Layer\Url\CategoryUrlInterface;
use Emico\Tweakwise\Model\Catalog\Layer\Url\FilterApplierInterface;
use Emico\Tweakwise\Model\Catalog\Layer\Url\UrlInterface;
use Emico\Tweakwise\Model\Client\Request\ProductNavigationRequest;
use Emico\Tweakwise\Model\Catalog\Layer\Url\UrlModel;
use Emico\Tweakwise\Model\Client\Request\ProductSearchRequest;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Framework\App\Request\Http as MagentoHttpRequest;
use Magento\Catalog\Model\Category;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Emico\TweakwiseExport\Model\Helper as ExportHelper;


class QueryParameterStrategy implements UrlInterface, FilterApplierInterface, CategoryUrlInterface
{
    /**
     * Separator used in category tree urls
     */
    const CATEGORY_TREE_SEPARATOR = '-';

    /**
     * Extra ignored page parameters
     */
    const PARAM_MODE = 'product_list_mode';
    const PARAM_CATEGORY = 'categorie';

    /**
     * Commonly used query parameters from headers
     */
    const PARAM_LIMIT = 'product_list_limit';
    const PARAM_ORDER = 'product_list_order';
    const PARAM_PAGE = 'p';
    const PARAM_SEARCH = 'q';

    /**
     * Parameters to be ignored as attribute filters
     *
     * @var string[]
     */
    protected $ignoredQueryParameters = [
        self::PARAM_CATEGORY,
        self::PARAM_ORDER,
        self::PARAM_LIMIT,
        self::PARAM_MODE,
        self::PARAM_SEARCH,
    ];

    /**
     * @var CategoryRepositoryInterface
     */
    protected $categoryRepository;

    /**
     * @var ExportHelper
     */
    protected $exportHelper;

    /**
     * @var UrlModel
     */
    protected $url;

    /**
     * Magento constructor.
     *
     * @param UrlModel $url
     * @param CategoryRepositoryInterface $categoryRepository
     * @param ExportHelper $exportHelper
     */
    public function __construct(
        UrlModel $url,
        CategoryRepositoryInterface $categoryRepository,
        ExportHelper $exportHelper
    ) {
        $this->url = $url;
        $this->categoryRepository = $categoryRepository;
        $this->exportHelper = $exportHelper;
    }

    /**
     * {@inheritdoc}
     */
    public function getClearUrl(MagentoHttpRequest $request, array $activeFilterItems): string
    {
        $query = [];
        /** @var Item $item */
        foreach ($activeFilterItems as $item) {
            $filter = $item->getFilter();

            $urlKey = $filter->getUrlKey();
            $query[$urlKey] = $filter->getCleanValue();
        }

        return $this->getCurrentQueryUrl($request, $query);
    }

    /**
     * @param MagentoHttpRequest $request
     * @param array $query
     * @return string
     */
    protected function getCurrentQueryUrl(MagentoHttpRequest $request, array $query)
    {
        $params['_current'] = true;
        $params['_use_rewrite'] = true;
        $params['_query'] = $query;
        $params['_escape'] = false;

        if ($originalUrl = $request->getQuery('__tw_original_url')) {
            return $this->url->getDirectUrl($originalUrl, $params);
        }
        return $this->url->getUrl('*/*/*', $params);
    }

    /**
     * Fetch current selected values
     *
     * @param MagentoHttpRequest $request
     * @param Item $item
     * @return string[]|string|null
     */
    protected function getRequestValues(MagentoHttpRequest $request, Item $item)
    {
        $filter = $item->getFilter();
        $settings = $filter
            ->getFacet()
            ->getFacetSettings();

        $urlKey = $filter->getUrlKey();

        $data = $request->getQuery($urlKey);
        if (!$data) {
            if ($settings->getIsMultipleSelect()) {
                return [];
            }

            return null;
        }

        if ($settings->getIsMultipleSelect()) {
            if (!is_array($data)) {
                $data = [$data];
            }
            return array_map('strval', $data);
        }

        return (string) $data;
    }

    /**
     * {@inheritdoc}
     */
    public function getCategoryFilterSelectUrl(MagentoHttpRequest $request, Item $item): string
    {
        $category = $this->getCategoryFromItem($item);
        if (!$this->getSearch($request)) {
            return $category->getUrl();
        }

        $urlKey = $item
            ->getFilter()
            ->getUrlKey();


        $value[] = $category->getId();
        /** @var Category|CategoryInterface $category */
        while ((int)$category->getParentId() !== 1) {
            $value[] = $category->getParentId();
            $category = $category->getParentCategory();
        }

        $value = implode(self::CATEGORY_TREE_SEPARATOR, array_reverse($value));

        $query = [$urlKey => $value];
        return $this->getCurrentQueryUrl($request, $query);
    }

    /**
     * {@inheritdoc}
     */
    public function getCategoryFilterRemoveUrl(MagentoHttpRequest $request, Item $item): string
    {
        $filter = $item->getFilter();
        $urlKey = $filter->getUrlKey();

        $query = [$urlKey => $filter->getCleanValue()];
        return $this->getCurrentQueryUrl($request, $query);
    }

    /**
     * {@inheritdoc}
     */
    public function getAttributeSelectUrl(MagentoHttpRequest $request, Item $item): string
    {
        $settings = $item
            ->getFilter()
            ->getFacet()
            ->getFacetSettings();
        $attribute = $item->getAttribute();

        $urlKey = $settings->getUrlKey();
        $value = $attribute->getTitle();

        $values = $this->getRequestValues($request, $item);

        if ($settings->getIsMultipleSelect()) {
            $values[] = $value;
            $values = array_unique($values);

            $query = [$urlKey => $values];
        } else {
            $query = [$urlKey => $value];
        }

        return $this->getCurrentQueryUrl($request, $query);
    }

    /**
     * @param MagentoHttpRequest $request
     * @param Item[] $filters
     * @return string
     */
    public function buildFilterUrl(MagentoHttpRequest $request, array $filters = []): string
    {
        $attributeFilters = $this->getAttributeFilters($request);
        return $this->getCurrentQueryUrl($request, $attributeFilters);
    }

    /**
     * {@inheritdoc}
     */
    public function getAttributeRemoveUrl(MagentoHttpRequest $request, Item $item): string
    {
        $filter = $item->getFilter();
        $settings = $filter->getFacet()->getFacetSettings();

        $urlKey = $settings->getUrlKey();

        if ($settings->getIsMultipleSelect()) {
            $attribute = $item->getAttribute();
            $value = $attribute->getTitle();
            $values = $this->getRequestValues($request, $item);

            $index = array_search($value, $values, false);
            if ($index !== false) {
                /** @noinspection OffsetOperationsInspection */
                unset($values[$index]);
            }

            $query = [$urlKey => $values];
        } else {
            $query = [$urlKey => $filter->getCleanValue()];
        }

        return $this->getCurrentQueryUrl($request, $query);
    }

    /**
     * {@inheritdoc}
     */
    protected function getCategoryFilters(MagentoHttpRequest $request)
    {
        $categories = $request->getQuery(self::PARAM_CATEGORY);
        $categories = explode(self::CATEGORY_TREE_SEPARATOR, $categories);
        $categories = array_map('intval', $categories);
        $categories = array_filter($categories);
        $categories = array_unique($categories);

        return $categories;
    }

    /**
     * {@inheritdoc}
     */
    protected function getAttributeFilters(MagentoHttpRequest $request)
    {
        $result = [];
        foreach ($request->getQuery() as $attribute => $value) {
            if (in_array(mb_strtolower($attribute), $this->ignoredQueryParameters, false)) {
                continue;
            }

            $result[$attribute] = $value;
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getSliderUrl(MagentoHttpRequest $request, Item $item): string
    {
        $query = [$item->getFilter()->getUrlKey() => '{{from}}-{{to}}'];

        return $this->getCurrentQueryUrl($request, $query);
    }

    /**
     * {@inheritdoc}
     */
    public function apply(MagentoHttpRequest $request, ProductNavigationRequest $navigationRequest): FilterApplierInterface
    {
        $attributeFilters = $this->getAttributeFilters($request);
        foreach ($attributeFilters as $attribute => $values) {
            if (!is_array($values)) {
                $values = [$values];
            }

            foreach ($values as $value) {
                $navigationRequest->addAttributeFilter($attribute, $value);
            }
        }

        $sortOrder = $this->getSortOrder($request);
        if ($sortOrder) {
            $navigationRequest->setOrder($sortOrder);
        }

        $page = $this->getPage($request);
        if ($page) {
            $navigationRequest->setPage($page);
        }

        $limit = $this->getLimit($request);
        if ($limit) {
            $navigationRequest->setLimit($limit);
        }

        $categories = $this->getCategoryFilters($request);

        if ($categories) {
            $navigationRequest->addCategoryPathFilter($categories);
        }

        $search = $this->getSearch($request);
        if ($navigationRequest instanceof ProductSearchRequest && $search) {
            /** @var ProductSearchRequest $navigationRequest */
            $navigationRequest->setSearch($search);
        }
        return $this;
    }

    /**
     * @param MagentoHttpRequest $request
     * @return string|null
     */
    protected function getSortOrder(MagentoHttpRequest $request)
    {
        return $request->getQuery(self::PARAM_ORDER);
    }

    /**
     * @param MagentoHttpRequest $request
     * @return int|null
     */
    protected function getPage(MagentoHttpRequest $request)
    {
        return $request->getQuery(self::PARAM_PAGE);
    }

    /**
     * @param MagentoHttpRequest $request
     * @return int|null
     */
    protected function getLimit(MagentoHttpRequest $request)
    {
        return $request->getQuery(self::PARAM_LIMIT);
    }

    /**
     * @param MagentoHttpRequest $request
     * @return string|null
     */
    protected function getSearch(MagentoHttpRequest $request)
    {
        return $request->getQuery(self::PARAM_SEARCH);
    }

    /**
     * @param Item $item
     * @return CategoryInterface
     * @throws NoSuchEntityException
     */
    protected function getCategoryFromItem(Item $item): CategoryInterface
    {
        $tweakwiseCategoryId = $item->getAttribute()->getAttributeId();
        $categoryId = $this->exportHelper->getStoreId($tweakwiseCategoryId);

        return $this->categoryRepository->get($categoryId);
    }

    /**
     * Determine if this UrlInterface is allowed in the current context
     *
     * @return boolean
     */
    public function isAllowed(): bool
    {
        return true;
    }
}
