export async function waitForSyncedFormKey(page, timeout = 30000) {
    await page.waitForFunction(() => {
        const cookie = document.cookie.split('; ').find(entry => entry.startsWith('form_key='));
        if (!cookie) {
            return false;
        }
        const formKey = cookie.slice('form_key='.length);
        const inputs = [...document.querySelectorAll('input[name="form_key"]')];
        return inputs.length > 0 && inputs.every(input => input.value === formKey);
    }, null, { timeout });
}
