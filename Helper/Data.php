<?PHP

/*
*
 */


class Hybridsearch_Magento_Helper_Data extends Mage_Core_Helper_Abstract
{
    const WEBSITES_SCOPE = 'websites';

    const STORES_SCOPE = 'stores';

    const DEFAULT_SCOPE = 'default';

    const WEBSITE_ID = 'website_id';



    /**
     * Return Magento version
     *
     * @return string
     */
    public function getMageVersion()
    {
        return Mage::getVersion();
    }

    /**
     * Return Extension version
     *
     * @return string
     */
    public function getVersion()
    {
        return (string) Mage::getConfig()
            ->getModuleConfig('Autocompleteplus_Autosuggest')
            ->version;
    }



    /**
     * Prepare grouped product price
     *
     * @param mixed $groupedProduct comment
     *
     * @return void
     */
    public function prepareGroupedProductPrice($groupedProduct)
    {
        $aProductIds = $groupedProduct
            ->getTypeInstance()
            ->getChildrenIds($groupedProduct->getId());

        $prices = array();
        foreach ($aProductIds as $ids) {
            foreach ($ids as $id) {
                try {
                    $aProduct = Mage::getModel('catalog/product')->load($id);
                    $prices[] = $aProduct->getPriceModel()->getPrice($aProduct);
                } catch (Exception $e) {
                    continue;
                }
            }
        }

        krsort($prices);
        try {
            if (count($prices) > 0) {
                $groupedProduct->setPrice($prices[0]);
            } else {
                $groupedProduct->setPrice(0);
            }
        } catch (Exception $e) {
            $groupedProduct->setPrice(0);
        }

        /**
         * Or you can return price
         */
    }

    /**
     * Get bundled product price
     *
     * @param mixed $product comment
     *
     * @return float
     */
    public function getBundlePrice($product)
    {
        $optionCol = $product->getTypeInstance(true)
            ->getOptionsCollection($product);
        $selectionCol = $product->getTypeInstance(true)
            ->getSelectionsCollection(
                $product->getTypeInstance(true)->getOptionsIds($product),
                $product
            );
        $optionCol->appendSelections($selectionCol);
        $price = $product->getPrice();

        foreach ($optionCol as $option) {
            if ($option->required) {
                $selections = $option->getSelections();
                $selPricesArr = array();

                if (is_array($selections)) {
                    foreach ($selections as $s) {
                        $selPricesArr[] = $s->price;
                    }

                    $minPrice = min($selPricesArr);

                    if ($product->getSpecialPrice() > 0) {
                        $minPrice *= $product->getSpecialPrice() / 100;
                    }

                    $price += round($minPrice, 2);
                }
            }
        }

        return $price;
    }



    public function outputline()
    {
        return null;
    }

    public function progressStart()
    {
        return null;
    }

    public function progressAdvance()
    {
        return null;
    }

    public function progressFinish()
    {
        return null;
    }

}