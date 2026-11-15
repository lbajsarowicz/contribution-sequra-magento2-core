import { ProductPage as BaseProductPage } from "playwright-fixture-for-plugins";
import { waitForSyncedFormKey } from "../utils/formKey.js";
import { waitForInitializedCustomerData } from "../utils/customerData.js";

/**
 * Product page
 */
export default class ProductPage extends BaseProductPage {

     /**
    * Init the locators with the locators available
    * 
    * @returns {Object}
    */
     initLocators() {
        return {
           ...super.initLocators(),
           messageSuccess: () => this.page.locator('.message-success'),
           filledMinicartCounter: () => this.page.locator('.minicart-wrapper .counter.qty:not(.empty) .counter-number'),
        };
    }

    async addToCart(options) {
        await this.goto(options);
        const { qtyInput, addToCartButton } = this.locators;
        await qtyInput(options).fill(`${options.quantity || 1}`);
        await waitForSyncedFormKey(this.page);
        await waitForInitializedCustomerData(this.page);
        const cartUpdated = this.page.waitForResponse(
            response => response.url().includes('/checkout/cart/add') && response.status() < 400,
            { timeout: 60000 }
        );
        await addToCartButton(options).click();
        await cartUpdated;
        await this.expectProductIsInCart(options);
    }

    /**
    * Provide the product URL
    * @param {Object} options
    * @param {string} options.slug The product slug
    * @returns {string} The product URL
    */
    productUrl(options) {
        const { slug } = options;
        return `${this.baseURL}/${slug}.html`;
    }

    /**
     * Provide the locator for the quantity input
     * 
     * @param {Object} options
     * @returns {import("@playwright/test").Locator}
     */
    qtyLocator(options = {}) {
        return this.page.locator('#qty');
    }
    /**
     * Provide the locator for adding to cart button
     * 
     * @param {Object} options
     * @returns {import("@playwright/test").Locator}
     */
    addToCartLocator(options = {}) {
        return this.page.locator('#product-addtocart-button');
    }

    /**
     * Wait for the product to be in the cart
     * @param {Object} options
     * @returns {Promise<void>}
     */
    async expectProductIsInCart(options = {}) {
        const { messageSuccess, filledMinicartCounter } = this.locators;
        await messageSuccess().or(filledMinicartCounter()).first().waitFor({ timeout: 60000 });
    }
}