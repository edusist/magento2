<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\GraphQl\Quote;

use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\SalesRule\Model\ResourceModel\Rule\Collection;
use Magento\SalesRule\Model\Rule;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\GraphQlAbstract;
use Magento\Tax\Model\ClassModel as TaxClassModel;
use Magento\Tax\Model\ResourceModel\TaxClass\CollectionFactory as TaxClassCollectionFactory;

/**
 * Test cases for applying cart promotions to items in cart
 */
class CartPromotionsTest extends GraphQlAbstract
{
    /**
     * Test adding single cart rule to multiple products in a cart
     *
     * @magentoApiDataFixture Magento/Catalog/_files/multiple_products.php
     * @magentoApiDataFixture Magento/SalesRule/_files/rules_category.php
     */
    public function testCartPromotionSingleCartRule()
    {
        $objectManager = Bootstrap::getObjectManager();
        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $objectManager->get(ProductRepositoryInterface::class);
        /** @var Product $prod2 */
        $prod1 = $productRepository->get('simple1');
        $prod2 = $productRepository->get('simple2');
        $productsInCart = [$prod1, $prod2];
        $prod2->setVisibility(Visibility::VISIBILITY_BOTH);
        $productRepository->save($prod2);
        $skus =['simple1', 'simple2'];
        $categoryId = 66;
        /** @var \Magento\Catalog\Api\CategoryLinkManagementInterface $categoryLinkManagement */
        $categoryLinkManagement = $objectManager->create(CategoryLinkManagementInterface::class);
        foreach ($skus as $sku) {
            $categoryLinkManagement->assignProductToCategories(
                $sku,
                [$categoryId]
            );
        }
        /** @var Collection $ruleCollection */
        $ruleCollection = $objectManager->get(Collection::class);
        $ruleLabels = [];
        /** @var Rule $rule */
        foreach ($ruleCollection as $rule) {
            $ruleLabels =  $rule->getStoreLabels();
        }
        $qty = 2;
        $cartId = $this->createEmptyCart();
        $this->addMultipleSimpleProductsToCart($cartId, $qty, $skus[0], $skus[1]);
        $query = $this->getCartItemPricesQuery($cartId);
        $response = $this->graphQlMutation($query);
        $this->assertCount(2, $response['cart']['items']);
        //validating the line item prices, quantity and discount
        $this->assertLineItemDiscountPrices($response, $productsInCart, $qty, $ruleLabels);
    }

    /**
     * Assert the row total discounts and individual discount break down and cart rule labels
     *
     * @param $response
     * @param $productsInCart
     * @param $qty
     * @param $ruleLabels
     */
    private function assertLineItemDiscountPrices($response, $productsInCart, $qty, $ruleLabels)
    {
        $productsInResponse = array_map(null, $response['cart']['items'], $productsInCart);
        $count = count($productsInCart);
        for ($itemIndex = 0; $itemIndex < $count; $itemIndex++) {
            $this->assertNotEmpty($productsInResponse[$itemIndex]);
            $this->assertResponseFields(
                $productsInResponse[$itemIndex][0],
                [
                    'quantity' => $qty,
                    'prices' => [
                        'row_total' => ['value' => $productsInCart[$itemIndex]->getSpecialPrice()*$qty],
                        'row_total_including_tax' => ['value' => $productsInCart[$itemIndex]->getSpecialPrice()*$qty],
                        'total_item_discount' => ['value' => $productsInCart[$itemIndex]->getSpecialPrice()*$qty*0.5],
                        'discounts' => [
                            0 =>[
                                'amount' =>
                                    ['value' => $productsInCart[$itemIndex]->getSpecialPrice()*$qty*0.5],
                                'label' => $ruleLabels[0]
                            ]
                        ]
                    ],
                ]
            );
        }
    }

    /**
     * Test applying multiple cart rules to multiple products in a cart
     *
     * @magentoApiDataFixture Magento/Catalog/_files/multiple_products.php
     * @magentoApiDataFixture Magento/SalesRule/_files/rules_category.php
     * @magentoApiDataFixture Magento/SalesRule/_files/cart_rule_10_percent_off_qty_more_than_2_items.php
     */
    public function testCartPromotionsMultipleCartRules()
    {
        $objectManager = Bootstrap::getObjectManager();
        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $objectManager->get(ProductRepositoryInterface::class);
        /** @var Product $prod2 */
        $prod1 = $productRepository->get('simple1');
        $prod2 = $productRepository->get('simple2');
        $productsInCart = [$prod1, $prod2];
        $prod2->setVisibility(Visibility::VISIBILITY_BOTH);
        $productRepository->save($prod2);
        $skus =['simple1', 'simple2'];
        $categoryId = 66;
        /** @var \Magento\Catalog\Api\CategoryLinkManagementInterface $categoryLinkManagement */
        $categoryLinkManagement = $objectManager->create(CategoryLinkManagementInterface::class);
        foreach ($skus as $sku) {
            $categoryLinkManagement->assignProductToCategories(
                $sku,
                [$categoryId]
            );
        }
        /** @var Collection $ruleCollection */
        $ruleCollection = $objectManager->get(Collection::class);
        $ruleLabels = [];
        /** @var Rule $rule */
        foreach ($ruleCollection as $rule) {
            $ruleLabels[] =  $rule->getStoreLabels();
        }
        $qty = 2;
        $cartId = $this->createEmptyCart();
        $this->addMultipleSimpleProductsToCart($cartId, $qty, $skus[0], $skus[1]);
        $query = $this->getCartItemPricesQuery($cartId);
        $response = $this->graphQlMutation($query);
        $this->assertCount(2, $response['cart']['items']);

        //validating the individual discounts per product and aggregate discount per product
        $productsInResponse = array_map(null, $response['cart']['items'], $productsInCart);
        $count = count($productsInCart);
        for ($itemIndex = 0; $itemIndex < $count; $itemIndex++) {
            $this->assertNotEmpty($productsInResponse[$itemIndex]);
            $lineItemDiscount = $productsInResponse[$itemIndex][0]['prices']['discounts'];
            $expectedTotalDiscountValue = ($productsInCart[$itemIndex]->getSpecialPrice()*$qty*0.5) +
                ($productsInCart[$itemIndex]->getSpecialPrice()*$qty*0.5*0.1);
            $this->assertEquals(
                $productsInCart[$itemIndex]->getSpecialPrice()*$qty*0.5,
                current($lineItemDiscount)['amount']['value']
            );
            $this->assertEquals('TestRule_Label', current($lineItemDiscount)['label']);

            $lineItemDiscountValue = next($lineItemDiscount)['amount']['value'];
            $this->assertEquals(
                round($productsInCart[$itemIndex]->getSpecialPrice()*$qty*0.5)*0.1,
                $lineItemDiscountValue
            );
            $this->assertEquals('10% off with two items_Label', end($lineItemDiscount)['label']);
            $actualTotalDiscountValue = $lineItemDiscount[0]['amount']['value']+$lineItemDiscount[1]['amount']['value'];
            $this->assertEquals(round($expectedTotalDiscountValue, 2), $actualTotalDiscountValue);

            //removing the elements from the response so that the rest of the response values can be compared
            unset($productsInResponse[$itemIndex][0]['prices']['discounts']);
            unset($productsInResponse[$itemIndex][0]['prices']['total_item_discount']);
            $this->assertResponseFields(
                $productsInResponse[$itemIndex][0],
                [
                    'quantity' => $qty,
                    'prices' => [
                        'row_total' => ['value' => $productsInCart[$itemIndex]->getSpecialPrice()*$qty],
                        'row_total_including_tax' => ['value' => $productsInCart[$itemIndex]->getSpecialPrice()*$qty]
                    ],
                ]
            );
        }
    }

    /**
     * Test applying single cart rules to multiple products in a cart with tax settings
     * Tax settings are : Including and Excluding tax for Price Display and Shopping cart display settings
     * Discount on Prices Includes Tax
     * Tax rate = 7.5%
     * Cart rule to apply 50% for products assigned to a specific category
     *
     * @magentoApiDataFixture Magento/Catalog/_files/multiple_products.php
     * @magentoApiDataFixture Magento/GraphQl/Tax/_files/tax_rule_for_region_1.php
     * @magentoApiDataFixture Magento/GraphQl/Tax/_files/tax_calculation_price_and_cart_display_settings.php
     * @magentoApiDataFixture Magento/SalesRule/_files/rules_category.php
     *
     */
    public function testCartPromotionsSingleCartRulesWithTaxes()
    {
        $objectManager = Bootstrap::getObjectManager();
        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $objectManager->get(ProductRepositoryInterface::class);
        /** @var Product $prod2 */
        $prod1 = $productRepository->get('simple1');
        $prod2 = $productRepository->get('simple2');
        $productsInCart = [$prod1, $prod2];
        $skus =['simple1', 'simple2'];

        /** @var TaxClassCollectionFactory $taxClassCollectionFactory */
        $taxClassCollectionFactory = $objectManager->get(TaxClassCollectionFactory::class);
        $taxClassCollection = $taxClassCollectionFactory->create();

        /** @var TaxClassModel $taxClass */
        $taxClassCollection->addFieldToFilter('class_type', TaxClassModel::TAX_CLASS_TYPE_PRODUCT);
        $taxClass = $taxClassCollection->getFirstItem();
        foreach ($productsInCart as $product) {
            $product->setCustomAttribute('tax_class_id', $taxClass->getClassId());
            $productRepository->save($product);
        }
        $categoryId = 66;
        /** @var \Magento\Catalog\Api\CategoryLinkManagementInterface $categoryLinkManagement */
        $categoryLinkManagement = $objectManager->create(CategoryLinkManagementInterface::class);
        foreach ($skus as $sku) {
            $categoryLinkManagement->assignProductToCategories(
                $sku,
                [$categoryId]
            );
        }
        $qty = 1;
        $cartId = $this->createEmptyCart();
        $this->addMultipleSimpleProductsToCart($cartId, $qty, $skus[0], $skus[1]);
        $this->setShippingAddressOnCart($cartId);
        $query = $this->getCartItemPricesQuery($cartId);
        $response = $this->graphQlMutation($query);
        $this->assertCount(2, $response['cart']['items']);
        $productsInResponse = array_map(null, $response['cart']['items'], $productsInCart);
        $count = count($productsInCart);
        for ($itemIndex = 0; $itemIndex < $count; $itemIndex++) {
            $this->assertNotEmpty($productsInResponse[$itemIndex]);
            $rowTotalIncludingTax = round(
                $productsInCart[$itemIndex]->getSpecialPrice()*$qty +
                $productsInCart[$itemIndex]->getSpecialPrice()*$qty*.075,
                2
            );
            $this->assertResponseFields(
                $productsInResponse[$itemIndex][0],
                [
                    'quantity' => $qty,
                    'prices' => [
                        // row_total is the line item price without the tax
                        'row_total' => ['value' => $productsInCart[$itemIndex]->getSpecialPrice()*$qty],
                        // row_total including tax is the price + price * tax rate
                        'row_total_including_tax' => ['value' => $rowTotalIncludingTax],
                        // discount from cart rule after tax is applied : 50% of row_total_including_tax
                        'total_item_discount' => ['value' => round($rowTotalIncludingTax/2, 2)],
                        'discounts' => [
                            0 =>[
                                'amount' =>
                                    ['value' => round($rowTotalIncludingTax/2, 2)],
                                'label' => 'TestRule_Label'
                            ]
                        ]
                    ],
                ]
            );
        }
    }

    /**
     * @param string $cartId
     * @return string
     */
    private function getCartItemPricesQuery(string $cartId): string
    {
        return <<<QUERY
{
  cart(cart_id:"{$cartId}"){
    items{
      quantity
      prices{
        row_total{
          value
        }
        row_total_including_tax{
          value
        }
        total_item_discount{value}
        discounts{
          amount{value}
          label
        }
      }
      }
    }
  }

QUERY;
    }

    /**
     * @return string
     */
    private function createEmptyCart(): string
    {
        $query = <<<QUERY
mutation {
  createEmptyCart
}
QUERY;
        $response = $this->graphQlMutation($query);
        $cartId = $response['createEmptyCart'];
        return $cartId;
    }

    /**
     * @param string $cartId
     * @param int $sku1
     * @param int $qty
     * @param string $sku2
     */
    private function addMultipleSimpleProductsToCart(string $cartId, int $qty, string $sku1, string $sku2): void
    {
        $query = <<<QUERY
mutation {
  addSimpleProductsToCart(input: {
    cart_id: "{$cartId}", 
    cart_items: [
      {
        data: {
          quantity: $qty
          sku: "$sku1"
        }
      } 
      {
        data: {
          quantity: $qty
          sku: "$sku2"
        }
      }    
    ]
  }
  ) {
    cart {
      items {
        product{sku}
        quantity       
            }
         }
      }
}
QUERY;

        $response = $this->graphQlMutation($query);

        self::assertArrayHasKey('cart', $response['addSimpleProductsToCart']);
        self::assertEquals($qty, $response['addSimpleProductsToCart']['cart']['items'][0]['quantity']);
        self::assertEquals($sku1, $response['addSimpleProductsToCart']['cart']['items'][0]['product']['sku']);
        self::assertEquals($qty, $response['addSimpleProductsToCart']['cart']['items'][1]['quantity']);
        self::assertEquals($sku2, $response['addSimpleProductsToCart']['cart']['items'][1]['product']['sku']);
    }

    /**
     * Set shipping address for the region for which tax rule is set
     *
     * @param string $cartId
     * @return void
     */
    private function setShippingAddressOnCart(string $cartId) :void
    {
        $query = <<<QUERY
mutation {
  setShippingAddressesOnCart(
    input: {
      cart_id: "$cartId"
      shipping_addresses: [
        {
          address: {
            firstname: "John"
            lastname: "Doe"
            company: "Magento"
            street: ["test street 1", "test street 2"]
            city: "Montgomery"
            region: "AL"
            postcode: "36043"
            country_code: "US"
            telephone: "88776655"
            save_in_address_book: false
          }
        }
      ]
    }
  ) {
    cart {
      shipping_addresses {
        city
        region{label}
      }
    }
  }
}
QUERY;
        $response = $this->graphQlMutation($query);
        self::assertEquals(
            'Montgomery',
            $response['setShippingAddressesOnCart']['cart']['shipping_addresses'][0]['city']
        );
        self::assertEquals(
            'Alabama',
            $response['setShippingAddressesOnCart']['cart']['shipping_addresses'][0]['region']['label']
        );
    }
}
