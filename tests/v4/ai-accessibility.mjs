import assert from 'node:assert/strict';
import {join} from 'node:path';
import {scanAuth} from './auth-accessibility.mjs';

export async function inspectAiPresentation(page,base,stack,output){
  await page.waitForLoadState('networkidle');
  for(const width of [390,768,1440]){await page.setViewportSize({width,height:1000});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${stack} AI overflow at ${width}`);}
  await page.goto(base+'/ai');await page.waitForLoadState('networkidle');await page.keyboard.press('Tab');assert.ok(await page.getByRole('link',{name:'Skip to content'}).evaluate(node=>node===document.activeElement));await page.keyboard.press('Enter');assert.equal(await page.evaluate(()=>document.activeElement.id),'auth-main');
  for(const locale of ['en','ur','ar']){
    if(locale!=='en'){await Promise.all([page.waitForEvent('load'),page.locator('[data-auth-locale]').selectOption(locale)]);await page.waitForLoadState('networkidle');}
    assert.equal(await page.locator('html').getAttribute('lang'),locale);assert.equal(await page.locator('html').getAttribute('dir'),locale==='en'?'ltr':'rtl');
    for(const theme of ['light','dark']){
      for(let i=0;i<3&&await page.locator('html').getAttribute('data-theme')!==theme;i++)await page.locator('[data-auth-theme]').click();
      assert.equal(await page.locator('html').getAttribute('data-theme'),theme);await page.setViewportSize({width:390,height:1000});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await scanAuth(page,`${stack}-ai-${locale}-${theme}`,output);
    }
    if(locale==='ur')await page.screenshot({path:join(output,`${stack}-ai-urdu-mobile.png`),fullPage:true});
  }
  await Promise.all([page.waitForEvent('load'),page.locator('[data-auth-locale]').selectOption('en')]);await page.waitForLoadState('networkidle');
  await page.evaluate(()=>localStorage.removeItem('corevia-theme'));await page.reload();await page.waitForLoadState('networkidle');await page.setViewportSize({width:1440,height:1000});await page.screenshot({path:join(output,`${stack}-ai-desktop.png`),fullPage:true});
  await page.route('**/ai/chat',route=>route.fulfill({status:503,contentType:'application/json',body:'{"error":{"code":"AI_NOT_CONFIGURED"}}'}));
  await page.getByLabel('Your message',{exact:true}).fill('fixture error');await page.getByRole('button',{name:'Send',exact:true}).click();await page.getByRole('alert').waitFor();assert.equal(await page.getByRole('alert').evaluate(node=>node===document.activeElement),true);await scanAuth(page,`${stack}-ai-error`,output);await page.unroute('**/ai/chat');await page.getByRole('button',{name:'Clear',exact:true}).click();assert.equal(await page.locator('[data-empty]').isVisible(),true);
}
