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

namespace Taxjar\SalesTax\Controller\Adminhtml\Config;

use Magento\Backend\App\Action\Context;

class SyncRates extends \Magento\Backend\App\AbstractAction
{
    const ADMIN_RESOURCE = 'Magento_Tax::manage_tax';

    /**
     * @var \Magento\Framework\App\Config\ReinitableConfigInterface
     */
    protected $_eventManager;

    /**
     * @param Context $context
     */
    public function __construct(
        Context $context
    ) {
        $this->_eventManager = $context->getEventManager();
        parent::__construct($context);
    }

    /**
     * Sync backup rates
     *
     * @return void
     */
    public function execute()
    {
        try {
            $this->_eventManager->dispatch('taxjar_salestax_import_data');
            $this->_eventManager->dispatch('taxjar_salestax_import_rates');    
        } catch (\Exception $e) {
            $this->messageManager->addError($e->getMessage());
        }
    }
}