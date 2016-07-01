<?php 
/**
 * Taxjar_SalesTax
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category   Taxjar
 * @package    Taxjar_SalesTax
 * @copyright  Copyright (c) 2016 TaxJar. TaxJar is a trademark of TPS Unlimited, Inc. (http://www.taxjar.com)
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxjar\SalesTax\Model\Tax;

use Magento\Tax\Api\TaxCalculationInterface;
use Magento\Tax\Api\TaxClassManagementInterface;
use Magento\Tax\Api\Data\AppliedTaxInterfaceFactory;
use Magento\Tax\Api\Data\AppliedTaxRateInterfaceFactory;
use Magento\Tax\Api\Data\TaxDetailsItemInterface;
use Magento\Tax\Api\Data\QuoteDetailsItemInterface;
use Magento\Tax\Api\Data\TaxDetailsInterfaceFactory;
use Magento\Tax\Api\Data\TaxDetailsItemInterfaceFactory;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\Calculation\AbstractCalculator;
use Magento\Tax\Model\Calculation\CalculatorFactory;
use Magento\Tax\Model\Config;
use Magento\Tax\Model\TaxDetails\AppliedTax;
use Magento\Tax\Model\TaxDetails\AppliedTaxRate;
use Magento\Tax\Model\TaxDetails\TaxDetails;
use Magento\Store\Model\StoreManagerInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TaxCalculation extends \Magento\Tax\Model\TaxCalculation
{
    /**
     * @var \Magento\Framework\Pricing\PriceCurrencyInterface
     */
    protected $_priceCurrency;
    
    /**
     * @var \Magento\Tax\Api\Data\AppliedTaxInterfaceFactory
     */
    protected $_appliedTaxDataObjectFactory;

    /**
     * @var \Magento\Tax\Api\Data\AppliedTaxRateInterfaceFactory
     */
    protected $_appliedTaxRateDataObjectFactory;
    
    /**
     * @param Calculation $calculation
     * @param CalculatorFactory $calculatorFactory
     * @param Config $config
     * @param TaxDetailsInterfaceFactory $taxDetailsDataObjectFactory
     * @param TaxDetailsItemInterfaceFactory $taxDetailsItemDataObjectFactory
     * @param StoreManagerInterface $storeManager
     * @param TaxClassManagementInterface $taxClassManagement
     * @param \Magento\Framework\Api\DataObjectHelper $dataObjectHelper
     * @param \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency
     * @param AppliedTaxInterfaceFactory $appliedTaxDataObjectFactory
     * @param AppliedTaxRateInterfaceFactory $appliedTaxRateDataObjectFactory
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Calculation $calculation,
        CalculatorFactory $calculatorFactory,
        Config $config,
        TaxDetailsInterfaceFactory $taxDetailsDataObjectFactory,
        TaxDetailsItemInterfaceFactory $taxDetailsItemDataObjectFactory,
        StoreManagerInterface $storeManager,
        TaxClassManagementInterface $taxClassManagement,
        \Magento\Framework\Api\DataObjectHelper $dataObjectHelper,
        \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency,
        AppliedTaxInterfaceFactory $appliedTaxDataObjectFactory,
        AppliedTaxRateInterfaceFactory $appliedTaxRateDataObjectFactory
    ) {
        $this->_priceCurrency = $priceCurrency;
        $this->_appliedTaxDataObjectFactory = $appliedTaxDataObjectFactory;
        $this->_appliedTaxRateDataObjectFactory = $appliedTaxRateDataObjectFactory;
        
        return parent::__construct(
            $calculation,
            $calculatorFactory,
            $config,
            $taxDetailsDataObjectFactory,
            $taxDetailsItemDataObjectFactory,
            $storeManager,
            $taxClassManagement,
            $dataObjectHelper
        );
    }

    /**
     * Calculate sales tax for each item in a quote
     * 
     * @param \Magento\Tax\Api\Data\QuoteDetailsInterface $quoteDetails
     * @param bool $useBaseCurrency
     * @param \Magento\Framework\App\ScopeInterface $scope
     * @return \Magento\Tax\Api\Data\TaxDetailsInterface
     */
    public function calculateTaxDetails(
        \Magento\Tax\Api\Data\QuoteDetailsInterface $quoteDetails,
        $useBaseCurrency,
        $scope
    ) {
        // initial TaxDetails data
        $taxDetailsData = [
            TaxDetails::KEY_SUBTOTAL => 0.0,
            TaxDetails::KEY_TAX_AMOUNT => 0.0,
            TaxDetails::KEY_DISCOUNT_TAX_COMPENSATION_AMOUNT => 0.0,
            TaxDetails::KEY_APPLIED_TAXES => [],
            TaxDetails::KEY_ITEMS => [],
        ];

        $items = $quoteDetails->getItems();

        if (empty($items)) {
            return $this->taxDetailsDataObjectFactory->create()
                ->setSubtotal(0.0)
                ->setTaxAmount(0.0)
                ->setDiscountTaxCompensationAmount(0.0)
                ->setAppliedTaxes([])
                ->setItems([]);
        }

        $keyedItems = [];
        $parentToChildren = [];

        foreach ($items as $item) {
            if ($item->getParentCode() === null) {
                $keyedItems[$item->getCode()] = $item;
            } else {
                $parentToChildren[$item->getParentCode()][] = $item;
            }
        }

        $processedItems = [];
        /** @var QuoteDetailsItemInterface $item */
        foreach ($keyedItems as $item) {
            if (isset($parentToChildren[$item->getCode()])) {
                $processedChildren = [];
                foreach ($parentToChildren[$item->getCode()] as $child) {
                    $processedItem = $this->processItemDetails($child, $useBaseCurrency, $scope);
                    $taxDetailsData = $this->aggregateItemData($taxDetailsData, $processedItem);
                    $processedItems[$processedItem->getCode()] = $processedItem;
                    $processedChildren[] = $processedItem;
                }
                $processedItem = $this->calculateParent($processedChildren, $item->getQuantity());
                $processedItem->setCode($item->getCode());
                $processedItem->setType($item->getType());
            } else {
                $processedItem = $this->processItemDetails($item, $useBaseCurrency, $scope);
                $taxDetailsData = $this->aggregateItemData($taxDetailsData, $processedItem);
            }
            $processedItems[$processedItem->getCode()] = $processedItem;
        }

        $taxDetailsDataObject = $this->taxDetailsDataObjectFactory->create();
        $this->dataObjectHelper->populateWithArray(
            $taxDetailsDataObject,
            $taxDetailsData,
            '\Magento\Tax\Api\Data\TaxDetailsInterface'
        );
        $taxDetailsDataObject->setItems($processedItems);
        return $taxDetailsDataObject;
    }
    
    /**
     * Process a quote item into a tax details item
     * 
     * @param QuoteDetailsItemInterface $item
     * @param bool $useBaseCurrency
     * @param \Magento\Framework\App\ScopeInterface $scope
     * @return \Magento\Tax\Api\Data\TaxDetailsItemInterface
     */
    protected function processItemDetails(
        QuoteDetailsItemInterface $item,
        $useBaseCurrency,
        $scope
    ) {
        $price = $item->getUnitPrice();
        $quantity = $this->getTotalQuantity($item);

        $extensionAttributes = $item->getExtensionAttributes();
        $taxCollectable = $extensionAttributes ? $extensionAttributes->getTaxCollectable() : 0;
        $taxPercent = $extensionAttributes ? $extensionAttributes->getCombinedTaxRate() : 0;
        
        if (!$useBaseCurrency) {
            $taxCollectable = $this->_priceCurrency->convert($taxCollectable, $scope);
        }

        $rowTotal = $price * $quantity;
        $rowTotalInclTax = $rowTotal + $taxCollectable;

        $priceInclTax = $rowTotalInclTax / $quantity;
        $discountTaxCompensationAmount = 0;
        
        $appliedTax = $this->getAppliedTax($item, $scope);
        $appliedTaxes = [
            $appliedTax->getTaxRateKey() => $appliedTax
        ];
        
        return $this->taxDetailsItemDataObjectFactory->create()
             ->setCode($item->getCode())
             ->setType($item->getType())
             ->setRowTax($taxCollectable)
             ->setPrice($price)
             ->setPriceInclTax($priceInclTax)
             ->setRowTotal($rowTotal)
             ->setRowTotalInclTax($rowTotalInclTax)
             ->setDiscountTaxCompensationAmount($discountTaxCompensationAmount)
             ->setAssociatedItemCode($item->getAssociatedItemCode())
             ->setTaxPercent($taxPercent)
             ->setAppliedTaxes($appliedTaxes);
    }
    
    /**
     * Set applied taxes for tax summary based on SmartCalcs response breakdown
     * 
     * @param QuoteDetailsItemInterface $item
     * @param \Magento\Framework\App\ScopeInterface $scope
     * @return \Magento\Tax\Api\Data\AppliedTaxInterface
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function getAppliedTax(
        QuoteDetailsItemInterface $item,
        $scope
    ) {
        $extensionAttributes = $item->getExtensionAttributes();
        $taxCollectable = $extensionAttributes ? $extensionAttributes->getTaxCollectable() : 0;
        $taxCollectable = $this->_priceCurrency->convert($taxCollectable, $scope);
        $taxPercent = $extensionAttributes ? $extensionAttributes->getCombinedTaxRate() : 0;
        $jurisdictionTaxRates = $extensionAttributes ? $extensionAttributes->getJurisdictionTaxRates() : [];
        $rateDataObjects = [];
        
        foreach ($jurisdictionTaxRates as $jurisdiction => $jurisdictionTaxRate) {
            $jurisdictionTitle = (in_array($jurisdiction, ['gst', 'pst', 'qst'])) ? strtoupper($jurisdiction) : ucfirst($jurisdiction) . ' Tax';
            
            $rateDataObjects[$jurisdiction] = $this->_appliedTaxRateDataObjectFactory->create()
                ->setPercent($jurisdictionTaxRate['rate'])
                ->setCode($jurisdiction)
                ->setTitle($jurisdictionTitle);
        }
        
        $appliedTaxDataObject = $this->_appliedTaxDataObjectFactory->create();
        $appliedTaxDataObject->setAmount($taxCollectable);
        $appliedTaxDataObject->setPercent($taxPercent);
        $appliedTaxDataObject->setTaxRateKey(implode(' - ', array_keys($jurisdictionTaxRates)));
        $appliedTaxDataObject->setRates($rateDataObjects);
    
        return $appliedTaxDataObject;
    }
}