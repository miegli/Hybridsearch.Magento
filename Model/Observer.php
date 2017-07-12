<?php

require 'Hybridsearch/Magento/vendor/autoload.php';

use Neoslive\Hybridsearch\Factory\SearchIndexFactory;
use Firebase\FirebaseLib;


class Hybridsearch_Magento_Model_Observer extends SearchIndexFactory
{

    protected $imagehelper = null;
    protected $corehelper = null;

    protected $isrealtime = false;

    /**
     * Hybridsearch_Magento_Model_Observer constructor.
     */
    public function __construct()
    {

        Mage::getConfig()->loadModules();
        ini_set('memory_limit', '128000M');
        $this->output = new Hybridsearch_Magento_Helper_Data();
        $this->firebase = new FirebaseLib(Mage::getStoreConfig('magento/info/endpoint'), Mage::getStoreConfig('magento/info/token'));
        $this->firebase->setTimeOut(0);
        $this->storeid = 1;
        $this->createFullIndex = false;
        $this->branch = "master";
        $this->branchSwitch = "slave";
        $this->temporaryDirectory = Mage::getBaseDir('cache') . "/hybridsearch/";
        $this->staticCacheDirectory = Mage::getBaseDir('base') . "/_Hybridsearch/";
        mkdir($this->temporaryDirectory, 0755, true);
        $this->additionalAttributeData = explode(",", Mage::getStoreConfig('magento/info/additionAttributeData'));
        $this->isrealtime = Mage::getStoreConfig('magento/info/realtime') == "1" ? true : false;
        $this->corehelper = Mage::helper('core');

    }


    /**
     * @param Mage_Catalog_Model_Product $product
     */
    public function syncProduct($product, $batch = false)
    {

        $dimensionConfigurationHash = $this->storeid;
        $workspaceHash = "live";
        $workspacename = $workspaceHash;

        if ($product->getStatus() == 2 || $product->getData('visibility') < 3) {
            $this->removeSingleIndex($product->getId(), $workspaceHash, $this->getBranch(), $dimensionConfigurationHash);
            return true;
        }

        Mage::app()->setCurrentStore($this->storeid);

        $attributes = $product->getAttributes();

        $properties = array();

        foreach ($attributes as $attribute) {
            $properties[$attribute->getName()] = $attribute->getFrontend()->getValue($product);
        }


        if (isset($this->index->$workspaceHash) === false) {
            $this->index->$workspaceHash = new \stdClass();
        }

        if (isset($this->index->$workspaceHash->$dimensionConfigurationHash) === false) {
            $this->index->$workspaceHash->$dimensionConfigurationHash = new \stdClass();
        }


        if (isset($this->keywords->$workspaceHash) === false) {
            $this->keywords->$workspaceHash = new \stdClass();
        }

        if (isset($this->keywords->$workspaceHash->$dimensionConfigurationHash) === false) {
            $this->keywords->$workspaceHash->$dimensionConfigurationHash = array();
        }

        $keywords = $this->generateSearchIndexFromProperties($properties);
        $indexData = $this->convertNodeToSearchIndexResult($product);


        $identifier = $indexData->identifier;
        $nt = "__" . $this->getNodeTypeName($product);


        if (isset($this->nodetypes->$nt)) {
            $this->nodetypes->$nt++;
        } else {
            $this->nodetypes->$nt = 1;
        }

        $keywords->$nt = true;


        $keywords->$identifier = true;


        $keywordsOfNode = array();

        foreach ($keywords as $keyword => $val) {

            $k = strval($keyword);


            if (substr($k, 0, 2) !== "__") {
                array_push($keywordsOfNode, $k);
            }

            if (substr($k, 0, 9) === "_nodetype") {
                $k = "_" . $this->getNodeTypeName($product) . mb_substr($k, 9);
            }

            if ($k) {
                if (isset($this->keywords->$workspaceHash->$dimensionConfigurationHash[$k]) == false) {
                    $this->keywords->$workspaceHash->$dimensionConfigurationHash[$k] = array();
                }
                if (is_array($val) == false) {
                    $val = array($k);
                }
                foreach ($val as $kek => $vev) {
                    $this->keywords->$workspaceHash->$dimensionConfigurationHash[$k][$kek] = $vev;
                }

            }

            if (isset($this->index->$workspaceHash->$dimensionConfigurationHash->$k) === false) {
                $this->index->$workspaceHash->$dimensionConfigurationHash->$k = new \stdClass();
            }

            if (substr($k, 0, 2) == '__') {
                $this->index->$workspaceHash->$dimensionConfigurationHash->$k->$identifier = array('node' => $indexData->node, 'nodeType' => $indexData->nodeType);
            } else {
                $this->index->$workspaceHash->$dimensionConfigurationHash->$k->$identifier = array('node' => null, 'nodeType' => $indexData->nodeType);
            }


        }

        if (isset($this->index->$workspaceHash->$dimensionConfigurationHash->___keywords) === false) {
            $this->index->$workspaceHash->$dimensionConfigurationHash->___keywords = new \stdClass();
        }

        $this->index->$workspaceHash->$dimensionConfigurationHash->___keywords->$identifier = $keywordsOfNode;

        if ($batch) {
            $product->clearInstance();
            unset($product);
            gc_collect_cycles();
        }

        if ($batch == false) {
            $this->firebase->set("/lastsync/$workspacename/" . $this->branch, time());
            $this->save();
            $this->proceedQueue();
        }

        return true;

    }

    /*
     * @var Mage_Catalog_Model_Observer $observer
     */
    public function syncOne($observer)
    {

        if ($this->isrealtime) {
            /* @var Mage_Core_Model_App $app */
            $app = Mage::app();
            if ($app->getLayout()->getArea() == 'adminhtml') {
                $this->syncProduct($observer->getProduct());
            }

        }

        return false;

    }

    /*
     * @var Mage_Catalog_Model_Observer $observer
     */
    public function removeOne($observer)
    {

        if ($this->isrealtime) {
            /* @var Mage_Core_Model_App $app */
            $app = Mage::app();
            if ($app->getLayout()->getArea() == 'adminhtml') {
                $dimensionConfigurationHash = $this->storeid;
                $workspaceHash = "live";
                $this->removeSingleIndex($observer->getProduct()->getId(), $workspaceHash, $this->getBranch(), $dimensionConfigurationHash);
                return true;
            }

        }

        return false;

    }


    public function syncAll()
    {


        /* @var Mage_Core_Model_App $app */
        $app = Mage::app();
        if ($app->getLayout()->getArea() == 'adminhtml') {
            return true;
        }

        if ($this->getArg('proceed')) {
            $this->unlockReltimeIndexer();
            $this->proceedQueue();
            $this->updateStaticCache();
            return true;
        }

        $workspacename = 'live';

        $this->getBranch($workspacename);
        $this->switchBranch($workspacename);

        $this->deleteQueue();
        $this->lockReltimeIndexer();

        $this->firebase->set("/lastsync/$workspacename/" . $this->branch, time());
        $this->creatingFullIndex = true;


        $stores = Mage::app()->getStores();


        foreach ($stores as $store) {
            $this->storeid = $store->getId();
            Mage::app()->setCurrentStore($this->storeid);
            $products = Mage::getSingleton('catalog/product')->getCollection()->addStoreFilter($this->storeid);

            $counter = 0;
            foreach ($products as $prod) {
                $product = Mage::getSingleton('catalog/product')->load($prod->getId());
                $this->syncProduct($product, true);
                $counter++;
                if ($counter % 100 == 0) {
                    $this->save();
                }
            }
            unset($products);
        }


        $this->save();
        $this->unlockReltimeIndexer();
        $this->proceedQueue();

        // create static file cache
        $this->updateStaticCache();

        // remove old sites data
        $this->switchBranch($workspacename);
        $this->deleteIndex($this->getSiteIdentifier(), $this->branch);

        // remove trash
        $this->firebase->delete("/trash", array('print' => 'silent'));
        $this->updateFireBaseRules();
        $this->unlockReltimeIndexer();

        return true;


    }

    public function productUpdate(Varien_Event_Observer $observer)
    {
        // Retrieve the product being updated from the event observer
        $product = $observer->getEvent()->getProduct();
        $this->syncProduct($product);

    }

    /**
     * @param Mage_Catalog_Model_Product $product
     * @return \stdClass
     */
    public function convertNodeToSearchIndexResult($product, $level = 0)
    {

        $data = new \stdClass();
        $data->node = new \stdClass();
        $data->nodeType = $this->getNodeTypeName($product);
        $data->identifier = $product->getId();
        $data->node->identifier = $product->getId();
        $data->node->url = $this->_getUrl($product);
        $data->node->uri = parse_url($data->node->url);
        $data->node->nodeType = $data->nodeType;
        $data->node->breadcrumb = $product->getUrlModel()->getUrl($product);
        $data->node->hash = $product->getId();
        $data->node->properties = new \stdClass();
        $data->node->properties->_nodeLabel = $product->getName();

        $whiteListAttributes = array("thumbnail" => true, "shortdescription" => true);


        // categories
        $data->node->properties->categories = array();
        $categoryIds = $product->getCategoryIds();
        if (count($categoryIds)) {
            foreach ($categoryIds as $categoryId) {
                $_category = Mage::getSingleton('catalog/category')->load($categoryId);
                array_push($data->node->properties->categories, array('name' => $_category->getName(), 'url' => $_category->getUrl(), 'id' => $_category->getId(), 'image' => $_category->getImageUrl()));
            }
        }


        // stock
        $data->node->properties->stock = array();
        $stock = Mage::getSingleton('cataloginventory/stock_item')->loadByProduct($product);
        if ($stock) {
            $data->node->properties->stock = array('qty' => $stock->getQty(), 'status' => $stock->getManageStock() ? ($stock->getQty() ? $this->corehelper->__('In stock') : $this->corehelper->__('Out of stock')) : $this->corehelper->__('In stock'));
        }


        // related
        if ($level === 0) {
            $data->node->properties->related = array();
            foreach ($product->getRelatedProductIds() as $relatedProductId) {
                $_related = Mage::getSingleton('catalog/product')->load($relatedProductId);
                $level++;
                if ($_related) {
                    array_push($data->node->properties->related, $this->convertNodeToSearchIndexResult($_related, $level));
                }
            }
        }


        // attributes
        foreach ($product->getAttributes() as $attribute) {
            /* @var Mage_Catalog_Model_Entity_Attribute $attribute */
            if (isset($whiteListAttributes[$attribute->getAttributeCode()]) || $attribute->getIsSearchable() || $attribute->getIsComparable() || $attribute->getIsFilterable()) {
                $k = $this->getAttributeName($attribute, $product);

                $labels = $attribute->getStoreLabels();
                $attributeData = array();
                foreach ($this->additionalAttributeData as $d) {
                    if ($attribute->getData($d)) {
                        $attributeData[$d] = $attribute->getData($d);
                    }
                }
                $data->node->properties->$k = array(
                    'data' => $attributeData,
                    'comparable' => $attribute->getIsComparable(),
                    'visible' => $attribute->getIsVisibleOnFront(),
                    'label' => (isset($labels[$this->storeid]) ? $labels[$this->storeid] : $attribute->getStoreLabel()),
                    'value' => $attribute->getFrontend()->getValue($product)
                );


            }
            unset($attribute);
        }


        $k = $this->getAttributeName("thumbnail", $product);
        if (isset($data->node->properties->$k)) {

            /* @var Mage_Catalog_Helper_Image $img */
            $img = Mage::helper('catalog/image');
            $productImage = Mage::getModel('catalog/product_media_config')->getMediaUrl($product->getThumbnail());
            if ($productImage !== '' && substr($productImage, -12, 12) !== 'no_selection') {
                $data->node->properties->$k['value'] = (string)$productImage;
            } else {
                /* @var Mage_Catalog_Helper_Image $img */
                $productImage = Mage::getModel('catalog/product_media_config')->getMediaUrl($product->getSmallImage());
                if ($productImage !== '' && substr($productImage, -12, 12) !== 'no_selection') {
                    $data->node->properties->$k['value'] = (string)$productImage;
                } else {
                    $data->node->properties->$k['value'] = false;
                }
            }
            unset($img);
            gc_collect_cycles();
        }


        $k = $this->getAttributeName("price", $product);
        if (isset($data->node->properties->$k)) {
            $data->node->properties->$k['data'] = array();
            $data->node->properties->$k['value'] = $this->_getPrice($product);
        }


        return $data;


    }


    /**
     * gets node type name
     * @param Mage_Catalog_Model_Product $product
     * @return string
     */
    public function getNodeTypeName($product)
    {
        $cat = $product->getCategoryIds();

        if (is_array($cat)) {
            $cat = sha1(json_encode($cat));
        }
        if (!$cat) {
            $cat = 0;
        }

        return mb_strtolower(preg_replace("/[^A-z0-9]/", "-", "product-" . ($cat) . "-" . $product->getAttributeSetId()));
    }

    /**
     * gets node attribute name
     * @param $attribute
     * @param Mage_Catalog_Model_Product $product
     * @return string
     */
    public function getAttributeName($attribute, $product)
    {
        return mb_strtolower(preg_replace("/[^A-z0-9]/", "-", $this->getNodeTypeName($product) . "-" . (is_string($attribute) ? $attribute : $attribute->getName())));
    }


    /**
     * get db identifier for current site
     * @return string
     */
    public
    function getSiteIdentifier()
    {

        return 'default';


    }


    /**
     * get product url
     * @return string
     */
    protected function _getUrl($product)
    {
        return $product->getProductUrl();
        //return $product->setStoreId($this->storeid)->getProductUrl();

    }

    /**
     * get product price
     * @return double
     */
    protected function _getPrice($product)
    {
        $price = 0;

        if ($product->getTypeId() == 'grouped') {
            $this->output->prepareGroupedProductPrice($product);
            $_minimalPriceValue = $product->getPrice();
            if ($_minimalPriceValue) {
                $price = $_minimalPriceValue;
            }
        } elseif ($product->getTypeId() == 'bundle') {
            if (!$product->getFinalPrice()) {
                $price = $this->output->getBundlePrice($product);
            } else {
                $price = $product->getFinalPrice();
            }
        } else {
            $price = $product->getFinalPrice();
        }
        if (!$price) {
            $price = 0;
        }

        return $price;
    }

    /**
     * Parse input argument
     *
     * @return array
     */
    protected function getArg($argument)
    {
        $current = null;
        $this->_args = array();

        foreach ($_SERVER['argv'] as $arg) {
            $match = array();
            if (preg_match('#^--([\w\d_-]{1,})$#', $arg, $match) || preg_match('#^-([\w\d_]{1,})$#', $arg, $match)) {
                $current = $match[1];
                $this->_args[$current] = true;
            } else {
                if ($current) {
                    $this->_args[$current] = $arg;
                } else if (preg_match('#^([\w\d_]{1,})$#', $arg, $match)) {
                    $this->_args[$match[1]] = true;
                }
            }
        }

        if (isset($this->_args[$argument])) {
            return $this->_args[$argument];
        } else {
            return null;
        }

    }


}

?>
