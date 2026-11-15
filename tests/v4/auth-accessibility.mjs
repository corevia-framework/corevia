import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFile,mkdir,writeFile} from 'node:fs/promises';
import {join} from 'node:path';
const require=createRequire(import.meta.url);
let source;
export async function scanAuth(page,label,output){
  source??=await readFile(require.resolve('../../tools/node_modules/axe-core/axe.min.js'),'utf8');
  await page.evaluate(source);
  const result=await page.evaluate(async()=>{
    const report=await window.axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']}});
    const summarize=items=>items.map(item=>({id:item.id,impact:item.impact,targets:item.nodes.map(node=>node.target),contrast:item.nodes.flatMap(node=>[...node.any,...node.all]).filter(check=>check.id==='color-contrast').map(check=>({foreground:check.data?.fgColor,background:check.data?.bgColor,ratio:check.data?.contrastRatio}))}));
    return {violations:summarize(report.violations),needs_manual_review:summarize(report.incomplete),passed_rules:report.passes.length};
  });
  await mkdir(output,{recursive:true});await writeFile(join(output,label+'.json'),JSON.stringify(result,null,2)+'\n');
  assert.deepEqual(result.violations,[],`Accessibility violations: ${label}`);
}
async function changeLocale(page,locale){await Promise.all([page.waitForEvent('load'),page.locator('[data-auth-locale]').selectOption(locale)]);await page.locator('input[name="email"]').waitFor();await page.waitForLoadState('networkidle');}
async function changeTheme(page,theme){for(let attempt=0;attempt<3;attempt++){if(await page.locator('html').getAttribute('data-theme')===theme)return;await page.locator('[data-auth-theme]').click();}assert.equal(await page.locator('html').getAttribute('data-theme'),theme);}
export async function inspectAuthPresentation(page,base,stack,output){
  await page.goto(base+'/login');await page.waitForLoadState('networkidle');
  for(const width of [390,768,1440]){await page.setViewportSize({width,height:1000});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${stack} overflow at ${width}`);}
  await page.goto(base+'/login');await page.waitForLoadState('networkidle');await page.keyboard.press('Tab');assert.ok(await page.getByRole('link',{name:'Skip to content'}).evaluate(node=>node===document.activeElement));await page.keyboard.press('Enter');assert.equal(await page.evaluate(()=>document.activeElement.id),'auth-main');
  for(const locale of ['en','ur','ar']){
    if(locale!=='en')await changeLocale(page,locale);
    assert.equal(await page.locator('html').getAttribute('lang'),locale);assert.equal(await page.locator('html').getAttribute('dir'),locale==='en'?'ltr':'rtl');
    if(locale!=='en'){assert.ok(!(await page.locator('h1').innerText()).includes('Welcome back.'));}
    for(const theme of ['light','dark']){await changeTheme(page,theme);await page.setViewportSize({width:390,height:900});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await scanAuth(page,`${stack}-${locale}-${theme}`,output);}
    if(locale==='ur')await page.screenshot({path:join(output,stack+'-urdu-dark-mobile.png'),fullPage:true});
    await page.locator('input[name="email"]').fill(`${stack}-${locale}@example.invalid`);await page.locator('input[name="password"]').fill('incorrect-password');await page.locator('form').filter({has:page.locator('input[name="email"]')}).locator('button[type="submit"],button:not([type])').click();
    await page.getByRole('alert').waitFor();await page.waitForFunction(()=>document.querySelector('[role="alert"]')===document.activeElement);await scanAuth(page,`${stack}-${locale}-validation`,output);
    await page.goto(base+'/login');await page.waitForLoadState('networkidle');
  }
  await changeLocale(page,'en');await page.evaluate(()=>localStorage.removeItem('corevia-theme'));await page.reload();await page.waitForLoadState('networkidle');
  await page.setViewportSize({width:1440,height:1000});await page.screenshot({path:join(output,stack+'-desktop.png'),fullPage:true});await page.emulateMedia({reducedMotion:'reduce'});assert.equal(await page.evaluate(()=>matchMedia('(prefers-reduced-motion:reduce)').matches),true);
}
