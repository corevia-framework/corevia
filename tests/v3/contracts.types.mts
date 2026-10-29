import {createClient, type Type_Greeting, type ErrorCode} from '../../contracts/generated/js/client.js';
const client = createClient(async (_name, _payload, _auth) => ({message:'Hello'}));
const result: Type_Greeting = await client.action('demo.greet', {name:'Ada'});
const message: string = result.message;
const code: ErrorCode = 'UNAVAILABLE';
client.validateError('demo.greet', code, true);
client.validateEvent('demo.greeted', {message});
// @ts-expect-error Input shape is generated from the manifest.
await client.action('demo.greet', {wrong:123});
// @ts-expect-error Action names are generated from the manifest.
await client.action('missing.action', {});
// @ts-expect-error Error codes are generated from the manifest.
client.validateError('demo.greet', 'MADE_UP', false);
// @ts-expect-error Required output fields have concrete types.
const invalid: number = result.message;
