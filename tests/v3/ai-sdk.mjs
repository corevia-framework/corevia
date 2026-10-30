import assert from 'node:assert/strict';
import {streamAi} from '../../sdk/js/ai.js';
function fixture(wire){let cancelled=false;return {cancelled:()=>cancelled,fetchImpl:async()=>new Response(new ReadableStream({start(controller){for(const byte of new TextEncoder().encode(wire))controller.enqueue(new Uint8Array([byte]));controller.close();},cancel(){cancelled=true;}}),{headers:{'Content-Type':'text/event-stream'}})};}
const sample=fixture('event: delta\r\ndata: {"text":"hello 🌍"}\r\n\r\nevent: done\r\ndata: []\r\n\r\n');let text='';for await(const chunk of streamAi('https://app.example/ai/chat',{message:'x',csrf:'fixture',fetchImpl:sample.fetchImpl}))text+=chunk;assert.equal(text,'hello 🌍');
for(const wire of ['event: delta\ndata: {"text":"partial"}\n\n','event: error\ndata: {"code":"PRIVATE_ERROR"}\n\n'])await assert.rejects(async()=>{for await(const chunk of streamAi('https://app.example/ai/chat',{fetchImpl:fixture(wire).fetchImpl})){};});
console.log('PASS frontend fragmented UTF-8 SSE, completion, truncation and generic error handling');
