const MODULES = ['Magento_Customer/js/customer-data', 'Magento_Customer/js/section-config'];

export async function waitForInitializedCustomerData(page, timeout = 30000) {
    await page.evaluate(modules => new Promise(resolve => window.require(modules, () => resolve())), MODULES);
    await page.waitForFunction(modules => {
        const [customerData, sectionConfig] = modules.map(name => window.require(name));
        return customerData.getInitCustomerData().state() === 'resolved'
            && sectionConfig.getSectionNames().length > 0;
    }, MODULES, { timeout });
}
