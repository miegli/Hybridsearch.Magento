<?php

require 'Hybridsearch/Magento/vendor/autoload.php';

use Neoslive\Hybridsearch\Factory\SearchIndexFactory;
use Firebase\FirebaseLib;


class Hybridsearch_Magento_Model_Observer extends SearchIndexFactory
{


    /**
     * Hybridsearch_Magento_Model_Observer constructor.
     */
    public function __construct()
    {
        Mage::getConfig()->loadModules();

        $this->output = new Hybridsearch_Magento_Helper_Data();
        $this->firebase = new FirebaseLib(Mage::getStoreConfig('magento/info/endpoint'), Mage::getStoreConfig('magento/info/token'));
        $this->firebase->setTimeOut(0);
        $this->createFullIndex = false;
        $this->branch = "master";
        $this->branchSwitch = "slave";
        $this->temporaryDirectory = Mage::getBaseDir('cache') . "/hybridsearch/";
        mkdir($this->temporaryDirectory, 0755, true);

    }


    /**
     * @param Mage_Catalog_Model_Product $product
     */
    public function syncProduct($product, $batch = false)
    {


        if (!$product || $product->getData('visibility') < 3) {
            return null;
        }

        $workspaceHash = "live";
        $workspacename = $workspaceHash;
        $dimensionConfigurationHash = "1";

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

        if ($batch == false) {
            $this->firebase->set("/lastsync/$workspacename/" . $this->branch, time());
            $this->save();
            $this->proceedQueue();
        }

    }


    public function syncAll()
    {

        if (!$this->getArg('hybridsearch')) {
            return true;
        }

        ini_set('display_errors', 1);
        ini_set('memory_limit', '9096M');

        $workspacename = 'live';

        $this->unlockReltimeIndexer();
        $this->proceedQueue();

        $this->getBranch($workspacename);
        $this->switchBranch($workspacename);

        $this->deleteQueue();
        $this->lockReltimeIndexer();

        $this->firebase->set("/lastsync/$workspacename/" . $this->branch, time());
        $this->creatingFullIndex = true;

        $products = Mage::getModel('catalog/product')->getCollection();
        //$products = Mage::getModel('catalog/product')->getCollection()->setPageSize(10)->setCurPage(1);
        $counter = 0;
        foreach ($products as $prod) {
            $product = Mage::getModel('catalog/product')->load($prod->getId());
            $this->syncProduct($product, true);
            echo $counter."\n";
            $counter++;
            if ($counter % 100 == 0) {
                $this->save();
            }
        }

        $this->save();
        $this->unlockReltimeIndexer();
        $this->proceedQueue();

        // remove old sites data
        $this->switchBranch($workspacename);
        $this->deleteIndex($this->getSiteIdentifier(), $this->branch);

        // remove trash
        $this->firebase->delete("/trash", array('print' => 'silent'));
        $this->updateFireBaseRules();
        $this->unlockReltimeIndexer();


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
    public function convertNodeToSearchIndexResult($product)
    {

        $data = new \stdClass();
        $data->node = new \stdClass();
        $data->nodeType = $this->getNodeTypeName($product);
        $data->identifier = $product->getId();
        $data->node->identifier = $product->getId();
        $data->node->uri = (parse_url($product->getUrlModel()->getUrl($product)));
        $data->node->url = $product->getUrlModel()->getUrl($product);
        $data->node->nodeType = $data->nodeType;
        $data->node->breadcrumb = $product->getUrlModel()->getUrl($product);
        $data->node->hash = $product->getId();
        $data->node->properties = new \stdClass();
        $data->node->properties->_nodeLabel = $product->getName();
        $tn = Mage::helper('catalog/image')->init($product, 'thumbnail')->resize(50, 50);
        if (strlen($tn) > 15) {
            $data->node->properties->thumbnail = $tn;
        }

        foreach ($product->getAttributes() as $attribute) {
            $k = $this->getAttributeName($attribute, $product);
            $data->node->properties->$k = $attribute->getFrontend()->getValue($product);
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
        return mb_strtolower(preg_replace("/[^A-z0-9]/", "-", "product-" . $product->getDefaultAttributeSetId()));
    }

    /**
     * gets node attribute name
     * @param $attribute
     * @param Mage_Catalog_Model_Product $product
     * @return string
     */
    public function getAttributeName($attribute, $product)
    {
        return mb_strtolower(preg_replace("/[^A-z0-9]/", "-", $this->getNodeTypeName($product) . "-" . $attribute->getName()));
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