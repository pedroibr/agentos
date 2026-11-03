'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { webcrypto } = require('node:crypto');
const { parseHTML } = require('linkedom');
const root = require('node:path').resolve(__dirname, '..');
const realtime = require(root + '/assets/agentos-realtime.js');
const tools = require(root + '/assets/agentos-tools.js');
const source = fs.readFileSync(root + '/assets/agentos-embed.js', 'utf8');
const deferred = () => { let resolve, reject; const promise = new Promise((a,b) => { resolve=a; reject=b; }); return {promise,resolve,reject}; };
const tick = async () => { for (let i=0;i<8;i++) await Promise.resolve(); };
const waitUntil = async (predicate, label) => { for (let i=0;i<40;i++) { if (predicate()) return; await tick(); } throw new Error('Timed out waiting for ' + label); };
const completionWatchdog = setTimeout(() => { console.error('FAIL integration: realtime embed suite did not reach its completion sentinel'); process.exitCode = 1; }, 10000);
function setup(options={}) {
 const config = { rest:'https://test.invalid/wp-json/agentos/v1',post_id:2,agent_id:'agent01',nonce:'test-nonce',mode:options.mode||'voice',viewer_is_authenticated:true,show_transcript:true,logging:!!options.logging };
 const {window: domWindow,document}=parseHTML(`<html><body><div class="agentos-wrap" data-mode="${config.mode}" data-show-transcript="1"><div class="agentos-shell"></div><button class="agentos-sidebar-toggle"></button><button class="agentos-sidebar__dismiss"></button><button class="agentos-sidebar-backdrop"></button><button class="agentos-sidebar__new"></button><div class="agentos-session-list"></div><div class="agentos-voice-stage__workspace-title"></div><div class="agentos-transcript"><div class="agentos-transcript__title"></div><div class="agentos-transcript__status"></div><div class="agentos-transcript-log"></div><div class="agentos-transcript__hint-text">Save feedback</div></div><div class="agentos-session-meta__summary"></div><div class="agentos-feedback__content">Feedback</div><audio class="agentos-audio"></audio><div class="agentos-toolbar agentos-bar"><button class="agentos-start"><span class="agentos-btn__label">Start voice</span></button><button class="agentos-stop"></button><button class="agentos-save"></button><span class="agentos-status"></span></div><div class="agentos-text-ui"><textarea class="agentos-text-input"></textarea><button class="agentos-text-send"></button><button class="agentos-text-record"></button></div></div></body></html>`);
 const window = {HTMLElement: domWindow.HTMLElement};
 document.querySelector('.agentos-wrap').dataset.config=JSON.stringify(config);
 const listeners=new WeakMap();
 const listen=(element,type,fn)=>{let map=listeners.get(element);if(!map){map={};listeners.set(element,map)};(map[type]??=[]).push(fn)};
 const proto=window.HTMLElement.prototype;
 proto.addEventListener=function(type,fn){listen(this,type,fn)};
 proto.getBoundingClientRect=function(){return {top:0,height:700,bottom:700,width:1000,left:0,right:1000}};
 for(const el of document.querySelectorAll('*')){el.scrollTop=0;Object.defineProperty(el,'scrollHeight',{configurable:true,get:()=>700});Object.defineProperty(el,'clientHeight',{configurable:true,get:()=>600});}
 const windowListeners={};
 window.addEventListener=(type,fn)=>(windowListeners[type]??=[]).push(fn);
 window.matchMedia=()=>({matches:false,addEventListener(){},removeEventListener(){}});
 window.innerWidth=1300;window.innerHeight=900;
 window.location={href:'https://test.invalid/sample-page/'};
 window.AgentOSRealtime=realtime;
 window.AgentOSTools=tools;
 const audio=document.querySelector('audio');audio.pause=()=>{};audio.play=()=>options.playbackRejected?Promise.reject(Object.assign(new Error('Blocked'),{name:'NotAllowedError'})):Promise.resolve();
 const tracks=[],peers=[],calls=[],timers=new Map();let timerId=0,tokenNumber=0,micCalls=0;
 const makeStream=()=>{const track={enabled:true,stopped:0,stop(){this.stopped++},getSettings(){return {echoCancellation:true,noiseSuppression:true}}};tracks.push(track);return {getTracks:()=>[track],getAudioTracks:()=>[track]}};
 const media={getSupportedConstraints:()=>({echoCancellation:true,noiseSuppression:true,autoGainControl:true}),getUserMedia:async()=>{micCalls++;return options.mic?options.mic(micCalls,makeStream):makeStream()}};
 class Channel {constructor(){this.readyState='connecting';this.sent=[];this.events={};this.closeCount=0;}send(value){this.sent.push(JSON.parse(value))}close(){this.closeCount++;this.readyState='closed';this.onclose?.();for(const fn of this.events.close||[])fn()}addEventListener(type,fn){(this.events[type]??=[]).push(fn)}removeEventListener(type,fn){this.events[type]=(this.events[type]||[]).filter(x=>x!==fn)}emit(event){this.onmessage?.({data:JSON.stringify(event)})}}
 class Peer {constructor(){peers.push(this);this.connectionState='new';this.iceConnectionState='new';this.closeCount=0;}createDataChannel(){return this.channel=new Channel()}addTrack(){}async createOffer(){return {sdp:'fake-offer'}}async setLocalDescription(){}async setRemoteDescription(){this.channel.readyState='open';this.connectionState='connected';this.ontrack?.({streams:[{}]});this.channel.onopen?.();for(const fn of this.channel.events.open||[])fn()}close(){this.closeCount++;this.connectionState='closed';this.onconnectionstatechange?.()}}
 const response=(data)=>({ok:true,status:200,json:async()=>data,text:async()=>String(data)});
 const fetch=async(url,args={})=>{calls.push({url,args});if(url.includes('/realtime-token')){tokenNumber++;if(options.token)return options.token(tokenNumber,response);if(options.failToken)throw new Error('Token unavailable');return response({client_secret:'fake-secret',session_id:'server-session-'+tokenNumber,session_ticket:'ticket-'+tokenNumber,model:'gpt-realtime-2.1-mini',voice:'alloy',user_prompt:''})}if(url.endsWith('/usage/report'))return options.usageReport?options.usageReport(JSON.parse(args.body),response):response({allowed:true,realtime_budget:null,session_cap:0});if(url.includes('/realtime/calls'))return response('fake-answer');if(url.includes('/transcript-db?'))return response(options.history||[]);if(url.endsWith('/transcript-db'))return options.save?options.save(response):response({id:91});if(url.endsWith('/mcp-call'))return options.tool?options.tool(JSON.parse(args.body),response):response({output:'{\"ok\":true}'});if(url.endsWith('/text-transcription'))return response({text:'recorded words'});if(url.endsWith('/text-response')&&options.text)return options.text(JSON.parse(args.body),response);if(url.endsWith('/text-response'))return response({text:'text reply',model:'gpt-4.1-mini',session_id:'text-session',session_ticket:'text-ticket',usage:{session_text_tokens:12,session_total_tokens:12}});return response({})};
 const timeout=(fn,delay)=>{const id=++timerId;timers.set(id,{fn,delay});return id};const clearTimeoutMock=id=>timers.delete(id);
 class MockMediaRecorder {constructor(){this.mimeType='audio/webm';this.state='inactive';this.stopped=0;}static isTypeSupported(){return true}start(){this.state='recording'}stop(){if(this.state==='inactive')return;this.state='inactive';this.stopped++;this.ondataavailable?.({data:new Blob(['audio'],{type:'audio/webm'})});this.onstop?.()}}
 if(options.mediaRecorder){window.MediaRecorder=MockMediaRecorder;}
 const navigator={mediaDevices:media,language:'en-US',userAgent:'AgentOS synthetic smoke',sendBeacon:()=>true};
 const logs=[];const capturedConsole=Object.fromEntries(['debug','info','warn','error','log'].map(level=>[level,(...args)=>logs.push({level,args})]));
 const context={window,document,navigator,console:capturedConsole,Intl,URL,URLSearchParams,crypto:webcrypto,fetch,RTCPeerConnection:Peer,MediaRecorder:options.mediaRecorder?MockMediaRecorder:undefined,setTimeout:timeout,clearTimeout:clearTimeoutMock,requestAnimationFrame:()=>++timerId,cancelAnimationFrame(){},Blob,FormData,localStorage:{getItem(){return 'anon-test'},setItem(){}},performance:{now:()=>0}};
 window.setTimeout=timeout;window.clearTimeout=clearTimeoutMock;
 vm.runInNewContext(source,context,{filename:'agentos-embed.js'});
 const find=selector=>document.querySelector(selector);
 const trigger=async(selector,event='click')=>{const el=typeof selector==='string'?find(selector):selector;const hs=listeners.get(el)?.[event]||[];assert.ok(hs.length,`missing handler ${selector} ${event}`);for(const fn of hs)await fn({key:'Enter',shiftKey:false,preventDefault(){}});await tick()};
 const fireTimerDelay=delay=>{const target=[...timers.entries()].find(([,timer])=>timer.delay===delay);assert.ok(target,`missing timer ${delay}`);timers.delete(target[0]);return target[1].fn()};
 const runTimerDelay=async delay=>{await fireTimerDelay(delay);await tick()};
 return {window,document,find,trigger,tracks,peers,calls,logs,makeStream,media,windowListeners,timers,fireTimerDelay,runTimerDelay,status:()=>find('.agentos-status').textContent,messages:()=>[...document.querySelectorAll('.msg')].map(el=>el.textContent)};
}
(async()=>{
 const fail=setup({failToken:true});await fail.trigger('.agentos-start');assert.equal(fail.tracks[0].stopped,1);assert.equal(fail.find('.agentos-start').disabled,false);assert.match(fail.status(),/error/i);console.log('PASS integration: failed token releases microphone and enables retry');
 const active=setup();await active.trigger('.agentos-start');const channel=active.peers[0].channel;
 channel.emit({type:'conversation.item.added',item:{id:'u1',role:'user'},previous_item_id:null});
 channel.emit({type:'conversation.item.input_audio_transcription.delta',item_id:'u1',delta:"I can't"});
 channel.emit({type:'conversation.item.input_audio_transcription.completed',item_id:'u1',transcript:'I can'});
 channel.emit({type:'conversation.item.added',item:{id:'a1',role:'assistant'},previous_item_id:'u1'});
 channel.emit({type:'response.created',response:{id:'r1'}});
 channel.emit({type:'response.output_audio_transcript.delta',item_id:'a1',response_id:'r1',delta:'I can'});
 channel.emit({type:'response.output_audio_transcript.done',item_id:'a1',response_id:'r1',transcript:'I can'});
 channel.emit({type:'output_audio_buffer.started',response_id:'r1'});
 assert.match(active.status(),/speaking/i);
 channel.emit({type:'response.done',response:{id:'r1',status:'completed',usage:{input_tokens:3,output_tokens:2,total_tokens:5}}});assert.match(active.status(),/speaking/i);
 channel.emit({type:'output_audio_buffer.stopped',response_id:'r1'});assert.match(active.status(),/listening/i);
 assert.deepEqual(active.messages(),['I can','I can']);
 await active.trigger('.agentos-save');
 const save=active.calls.find(c=>c.url.endsWith('/transcript-db')&&c.args.method==='POST');assert.deepEqual(JSON.parse(save.args.body).transcript.map(e=>e.text),['I can','I can']);
 assert.equal(active.tracks[0].stopped,1);assert.equal(active.peers[0].channel.closeCount,1,'channel cleanup must not recurse');
 console.log('PASS integration: authoritative captions, legitimate repetition, playback status, save cleanup');
 const delayed=setup({logging:true});await delayed.trigger('.agentos-start');const delayedChannel=delayed.peers[0].channel;
 const spoken='Meu nome é Leonardo. Esta é uma frase privada de teste.';
 delayedChannel.emit({type:'input_audio_buffer.speech_started',item_id:'late-user',audio_start_ms:0});
 delayedChannel.emit({type:'conversation.item.input_audio_transcription.delta',item_id:'late-user',delta:'Meu nome é'});
 delayedChannel.emit({type:'input_audio_buffer.speech_stopped',item_id:'late-user',audio_end_ms:4200});
 delayedChannel.emit({type:'response.created',response:{id:'early-answer'}});
 delayedChannel.emit({type:'response.output_audio_transcript.done',response_id:'early-answer',item_id:'early-assistant',transcript:'Entendi.'});
 delayedChannel.emit({type:'response.done',response:{id:'early-answer',status:'completed',usage:{input_tokens:8,output_tokens:2,total_tokens:10}}});
 delayedChannel.emit({type:'conversation.item.input_audio_transcription.completed',item_id:'late-user',transcript:spoken,usage:{type:'duration',seconds:4.2}});
 assert.deepEqual(delayed.messages(),[spoken,'Entendi.'],'late ASR completion must replace the prefix even after the assistant replies');
 const inputTraces=delayed.logs.flatMap(log=>log.args.filter(arg=>typeof arg==='string'&&arg.startsWith('Realtime input trace ')).map(arg=>JSON.parse(arg.slice('Realtime input trace '.length))));
 const finalTrace=inputTraces.find(trace=>trace.type.endsWith('.completed'));
 assert.equal(finalTrace.finalChars,spoken.length);assert.equal(finalTrace.ledgerChars,spoken.length);assert.equal(finalTrace.savedChars,spoken.length);assert.equal(finalTrace.renderedChars,spoken.length);assert.equal(finalTrace.final,true);
 assert.equal(inputTraces.find(trace=>trace.type.endsWith('speech_stopped')).audioEndMs,4200);
 assert.ok(!JSON.stringify(delayed.logs).includes(spoken));assert.ok(!JSON.stringify(delayed.logs).includes('Leonardo'));assert.ok(!JSON.stringify(delayed.logs).includes('fake-secret'));
 assert.equal(active.logs.length,0,'logging-disabled sessions remain silent');
 console.log('PASS integration: delayed ASR final survives an early answer and diagnostics exclude spoken text and credentials');
 const hybrid=setup({mode:'both'});hybrid.find('.agentos-text-input').value='keep this message';await hybrid.trigger('.agentos-text-send');assert.equal(hybrid.find('.agentos-text-input').value,'keep this message');assert.deepEqual(hybrid.messages(),[]);console.log('PASS integration: hybrid unsent text is preserved');
 const gate=deferred();const race=setup({mic:(n,make)=>n===1?gate.promise:make()});const oldStart=race.trigger('.agentos-start');await tick();await race.trigger('.agentos-sidebar__new');await race.trigger('.agentos-start');const staleStream=race.makeStream();gate.resolve(staleStream);await oldStart;assert.equal(race.tracks[0].stopped,0);assert.equal(race.tracks[1].stopped,1);await race.trigger('.agentos-stop');assert.equal(race.tracks[0].stopped,1);console.log('PASS integration: stale mic acquisition does not replace active capture');
 const disconnect=setup();await disconnect.trigger('.agentos-start');disconnect.peers[0].connectionState='failed';disconnect.peers[0].onconnectionstatechange();assert.equal(disconnect.tracks[0].stopped,1);assert.equal(disconnect.find('.agentos-start').disabled,false);console.log('PASS integration: failed peer releases resources and enables resume');
 for(const fn of disconnect.windowListeners.beforeunload||[])fn();console.log('PASS integration: unload handler has valid session scope');
 const browse=setup({history:[{id:5,created_at:'2026-10-27 10:00:00',transcript:[{role:'user',text:'older history'}]}]});await tick();await browse.trigger('.agentos-start');const bc=browse.peers[0].channel;
 bc.emit({type:'conversation.item.added',item:{id:'bu1',role:'user',content:[{type:'input_audio'}]}});
 bc.emit({type:'conversation.item.input_audio_transcription.completed',item_id:'bu1',transcript:'current voice'});
 let items=browse.document.querySelectorAll('.agentos-session-item');await browse.trigger(items[1]);items=browse.document.querySelectorAll('.agentos-session-item');await browse.trigger(items[0]);
 bc.emit({type:'conversation.item.done',item:{id:'bu1',role:'user',content:[{type:'input_audio'}]}});
 assert.deepEqual(browse.messages(),['current voice'],'returning from history must not duplicate current captions');console.log('PASS integration: history switching keeps one caption per item');
 const partial=setup();await partial.trigger('.agentos-start');const pch=partial.peers[0].channel;pch.emit({type:'conversation.item.input_audio_transcription.delta',item_id:'pending-user',delta:'unfinished phrase'});await partial.trigger('.agentos-stop');assert.ok(partial.messages().some(t=>/unfinished phrase/.test(t)),'pending speech must stay visible after stop');assert.ok(partial.messages().some(t=>/partial|incomplete/i.test(t)),'pending speech must be marked partial');console.log('PASS integration: stopping preserves a marked partial caption');
 const tokenGate=deferred();const tokenRace=setup({token:(n,response)=>n===1?tokenGate.promise:response({client_secret:'fake-secret',session_id:'fresh-session',session_ticket:'fresh-ticket',model:'gpt-realtime-2.1-mini'})});const pendingStart=tokenRace.trigger('.agentos-start');await tick();await tokenRace.trigger('.agentos-sidebar__new');await tokenRace.trigger('.agentos-start');tokenRace.peers[0].channel.emit({type:'conversation.item.input_audio_transcription.completed',item_id:'fresh-user',transcript:'fresh conversation'});tokenGate.resolve({ok:true,json:async()=>({client_secret:'old-secret',session_id:'stale-session',session_ticket:'old-ticket',model:'old-model'})});await pendingStart;await tokenRace.trigger('.agentos-save');const tokenSave=tokenRace.calls.find(c=>c.url.endsWith('/transcript-db')&&c.args.method==='POST');assert.equal(JSON.parse(tokenSave.args.body).session_id,'fresh-session');console.log('PASS integration: stale token cannot overwrite current session identity');
 const saveGate=deferred();const saveRace=setup({save:()=>saveGate.promise});await saveRace.trigger('.agentos-start');saveRace.peers[0].channel.emit({type:'conversation.item.input_audio_transcription.completed',item_id:'old-user',transcript:'old conversation'});const oldSave=saveRace.trigger('.agentos-save');await tick();await saveRace.trigger('.agentos-sidebar__new');await saveRace.trigger('.agentos-start');saveRace.peers[1].channel.emit({type:'conversation.item.input_audio_transcription.completed',item_id:'new-user',transcript:'new conversation'});saveGate.resolve({ok:true,json:async()=>({id:99})});await oldSave;assert.equal(saveRace.tracks[1].stopped,0);assert.deepEqual(saveRace.messages(),['new conversation']);await saveRace.trigger('.agentos-stop');console.log('PASS integration: late save does not reset a new voice session');
 const protocol=setup();await protocol.trigger('.agentos-start');protocol.peers[0].channel.emit({type:'error',error:{code:'invalid_event',message:'internal detail'}});assert.match(protocol.status(),/voice service error/i);await protocol.trigger('.agentos-stop');console.log('PASS integration: protocol errors are surfaced without dumping payloads');
 const text=setup({mode:'text'});text.find('.agentos-text-input').value='text question';await text.trigger('.agentos-text-send');assert.deepEqual(text.messages(),['text question','text reply']);assert.equal(text.tracks.length,0);assert.equal(text.find('.agentos-text-input').value,'');console.log('PASS integration: text-only mode remains independent of microphone');
 const blocked=setup({playbackRejected:true});await blocked.trigger('.agentos-start');assert.match(blocked.status(),/playback was blocked/i);assert.equal(blocked.find('audio').controls,true);await blocked.trigger('.agentos-stop');console.log('PASS integration: autoplay rejection shows recovery controls');
 const story=setup();await story.trigger('.agentos-start');const sc=story.peers[0].channel;
 sc.emit({type:'conversation.item.added',item:{id:'question-story',type:'message',role:'user',content:[{type:'input_audio'}]},previous_item_id:null});
 sc.emit({type:'conversation.item.input_audio_transcription.completed',item_id:'question-story',transcript:'Tell me a story.'});
 sc.emit({type:'response.created',response:{id:'story-response'}});
 sc.emit({type:'conversation.item.added',item:{id:'story-item',type:'message',role:'assistant'},previous_item_id:'question-story'});
 const storyText='Once upon a time, a robot found a hidden library.';
 sc.emit({type:'response.output_audio_transcript.delta',response_id:'story-response',item_id:'story-item',delta:storyText});
 assert.equal(story.messages().some(text=>/Partial:|Interrupted/.test(text)),false,'normal streaming is not labelled interrupted');
 sc.emit({type:'input_audio_buffer.committed',item_id:'stop-item',previous_item_id:'story-item'});
 sc.emit({type:'response.done',response:{id:'story-response',status:'cancelled',usage:{input_tokens:8,output_tokens:2,total_tokens:10}}});
 sc.emit({type:'conversation.item.truncated',item_id:'story-item',audio_end_ms:500});
 sc.emit({type:'conversation.item.done',item:{id:'story-item',type:'message',role:'assistant',content:[{type:'output_audio',transcript:''}]},previous_item_id:'question-story'});
 sc.emit({type:'conversation.item.input_audio_transcription.completed',item_id:'stop-item',transcript:'Stop.'});
 sc.emit({type:'response.created',response:{id:'ack-response'}});
 sc.emit({type:'conversation.item.added',item:{id:'ack-item',type:'message',role:'assistant'},previous_item_id:'stop-item'});
 sc.emit({type:'response.output_audio_transcript.done',response_id:'ack-response',item_id:'ack-item',transcript:'Okay, I will stop.'});
 sc.emit({type:'response.done',response:{id:'ack-response',status:'completed',usage:{input_tokens:4,output_tokens:6,total_tokens:10}}});
 sc.emit({type:'output_audio_buffer.stopped',response_id:'ack-response'});
 sc.emit({type:'response.output_audio_transcript.done',response_id:'story-response',item_id:'story-item',transcript:storyText+' This late generated ending must not appear.'});
 const storyMessages=story.messages();assert.equal(storyMessages.length,4);assert.equal(storyMessages[0],'Tell me a story.');assert.ok(storyMessages[1].includes(storyText));assert.ok(storyMessages[1].includes('Interrupted'));assert.equal(storyMessages[1].includes('late generated'),false);assert.equal(storyMessages[2],'Stop.');assert.equal(storyMessages[3],'Okay, I will stop.');
 await story.trigger('.agentos-stop');await story.trigger('.agentos-start');
 const restored=story.peers[1].channel.sent.filter(event=>event.type==='conversation.item.create').map(event=>event.item.content[0].text);
 assert.equal(restored.includes(storyText),false,'interrupted snapshot is not reinjected as heard speech');assert.equal(restored.includes('Stop.'),true);
 await story.trigger('.agentos-save');
 const storySave=story.calls.find(call=>call.url.endsWith('/transcript-db')&&call.args.method==='POST');const archivedStory=JSON.parse(storySave.args.body).transcript;
 assert.deepEqual(archivedStory.map(entry=>entry.text),['Tell me a story.',storyText,'Stop.','Okay, I will stop.']);assert.equal(archivedStory[1].interrupted,true);assert.equal(archivedStory[1].resumeEligible,false);
 const reopened=setup({history:[{id:91,created_at:'2026-10-27 12:00:00',transcript:archivedStory}]});await tick();await reopened.trigger(reopened.document.querySelectorAll('.agentos-session-item')[1]);assert.ok(reopened.messages()[1].includes(storyText));assert.ok(reopened.messages()[1].includes('Interrupted'));assert.equal(reopened.messages()[2],'Stop.');console.log('PASS integration: interrupted story stays ordered, archived, reopened and excluded from resume');



 const toolToken=(n,response)=>response({client_secret:'fake-secret',session_id:'tool-session-'+n,session_ticket:'session-ticket-'+n,tool_ticket:'ticket-'+n,tool_manifest:{mcp_lookup:{connection_label:'Course data'}},model:'gpt-realtime-2.1-mini'});
 const toolVoice=setup({token:toolToken});await toolVoice.trigger('.agentos-start');const tc=toolVoice.peers[0].channel;
 const functionResponse={type:'response.done',response:{id:'tool-response',status:'completed',usage:{input_tokens:10,output_tokens:3,total_tokens:13},output:[{type:'function_call',call_id:'lookup-call',name:'mcp_lookup',arguments:'{"course":2}'}]}};
 tc.emit(functionResponse);await waitUntil(()=>tc.sent.some(e=>e.type==='response.create'),'Realtime tool continuation');tc.emit(functionResponse);await tick();
 assert.equal(toolVoice.calls.filter(c=>c.url.endsWith('/mcp-call')).length,1,'MCP tool request executes once');assert.equal(tc.sent.filter(e=>e.item?.type==='function_call_output').length,1,'MCP output is sent once');assert.equal(tc.sent.filter(e=>e.type==='response.create').length,1,'one response continuation is sent');
 console.log('PASS integration: voice tool calls execute once and continue the conversation');
 const approvalVoice=setup({token:toolToken,tool:(body,response)=>response(body.approval_id?{output:'{"declined":true}'}:{approval_required:true,approval_id:'approval-1',tool:'<img src=x onerror=alert(1)>',connection:'Course data',arguments:{course:2}})});
 await approvalVoice.trigger('.agentos-start');approvalVoice.peers[0].channel.emit(functionResponse);await waitUntil(()=>approvalVoice.find('.agentos-tool-deny'),'tool approval dialog');assert.equal(approvalVoice.document.querySelectorAll('.agentos-tool-approval img').length,0);await approvalVoice.trigger('.agentos-tool-deny');await tick();
 const approvalCalls=approvalVoice.calls.filter(c=>c.url.endsWith('/mcp-call'));assert.equal(JSON.parse(approvalCalls[1].args.body).approve,false);assert.equal(approvalVoice.find('.agentos-tool-approval'),null);
 console.log('PASS integration: approval UI safely displays parameters and sends explicit denial');
 const pendingTool=deferred();const stoppedTool=setup({token:toolToken,tool:()=>pendingTool.promise});await stoppedTool.trigger('.agentos-start');const stc=stoppedTool.peers[0].channel;stc.emit(functionResponse);await tick();await stoppedTool.trigger('.agentos-stop');pendingTool.resolve({ok:true,json:async()=>({output:'{"late":true}'})});await tick();await tick();assert.equal(stc.sent.filter(e=>e.item?.type==='function_call_output').length,0);assert.ok(stoppedTool.calls.some(c=>c.url.endsWith('/mcp-session')&&c.args.method==='DELETE'));
 console.log('PASS integration: stopping revokes tool access and discards late voice results');

 const batched=setup();await batched.trigger('.agentos-start');const bch=batched.peers[0].channel;
 const measured={type:'response.done',response:{id:'measured-1',status:'incomplete',usage:{input_tokens:17,output_tokens:6,total_tokens:23,input_token_details:{text_tokens:12,audio_tokens:5,cached_tokens:4,cached_tokens_details:{text_tokens:3,audio_tokens:1}},output_token_details:{text_tokens:2,audio_tokens:4}}}};
 bch.emit(measured);bch.emit(measured);
 bch.emit({type:'conversation.item.input_audio_transcription.completed',event_id:'asr-duration-1',item_id:'audio-item',usage:{type:'duration',seconds:1.75},transcript:'spoken words'});
 assert.equal(batched.calls.filter(c=>c.url.endsWith('/usage/report')).length,0,'Realtime usage is not sent per response');
 await batched.runTimerDelay(60000);
 const batchCall=batched.calls.find(c=>c.url.endsWith('/usage/report'));const usageBatch=JSON.parse(batchCall.args.body);
 assert.equal(usageBatch.events.length,2,'duplicate response.done is deduped before batching');
 assert.deepEqual(usageBatch.events[0],{event_id:'measured-1',category:'realtime',response_status:'incomplete',usage:{input_tokens:17,output_tokens:6,total_tokens:23,details:{input_token_details:{text_tokens:12,audio_tokens:5,cached_tokens:4,cached_tokens_details:{text_tokens:3,audio_tokens:1}},output_token_details:{text_tokens:2,audio_tokens:4}}}});
 assert.deepEqual(usageBatch.events[1].usage,{type:'duration',seconds:1.75},'duration usage stays seconds and is not converted to invented tokens');
 console.log('PASS integration: actual interrupted Realtime and duration-only ASR usage batch once every minute');

 let batchAttempts=0;const replay=setup({usageReport:(body,response)=>{batchAttempts++;return batchAttempts===1?{ok:false,status:503,json:async()=>({message:'retry'})}:response({allowed:true,realtime_budget:null})}});
 await replay.trigger('.agentos-start');replay.peers[0].channel.emit({type:'response.done',response:{id:'retry-event',status:'completed',usage:{input_tokens:2,output_tokens:3,total_tokens:5}}});
 await replay.runTimerDelay(60000);assert.equal(replay.status().toLowerCase().includes('paused'),false,'one report failure does not cut voice');
 await replay.runTimerDelay(60000);assert.equal(batchAttempts,2);const retryBodies=replay.calls.filter(c=>c.url.endsWith('/usage/report')).map(c=>JSON.parse(c.args.body));assert.deepEqual(retryBodies[0].events,retryBodies[1].events,'an uncertain report retries the identical idempotent batch');
 console.log('PASS integration: uncertain usage batch retries the same event IDs without losing reports');

 const delayedDecision=deferred();let delayedOnce=false;const staleHeartbeat=setup({usageReport:(body)=>{if(!delayedOnce){delayedOnce=true;return delayedDecision.promise}return {ok:true,status:200,json:async()=>({allowed:true,realtime_budget:null})}}});
 await staleHeartbeat.trigger('.agentos-start');const oldHeartbeat=staleHeartbeat.fireTimerDelay(60000);await tick();const openAfterOldHeartbeat=staleHeartbeat.trigger('.agentos-sidebar__new');
 delayedDecision.resolve({ok:true,status:200,json:async()=>({allowed:false,message:'old session expired'})});await Promise.all([oldHeartbeat,openAfterOldHeartbeat]);await staleHeartbeat.trigger('.agentos-start');
 await tick();
 assert.equal(staleHeartbeat.peers[1].channel.readyState,'open','a delayed denial for an old session cannot close the new conversation');assert.match(staleHeartbeat.status(),/listening/i);
 console.log('PASS integration: delayed denial from old usage heartbeat does not pause a new conversation');

 const finalUsage=setup();await finalUsage.trigger('.agentos-start');const finalChannel=finalUsage.peers[0].channel;finalChannel.emit({type:'response.created',response:{id:'last-response'}});const openingNewChat=finalUsage.trigger('.agentos-sidebar__new');await waitUntil(()=>finalChannel.sent.some(event=>event.type==='response.cancel'),'final response cancellation');finalChannel.emit({type:'response.done',response:{id:'last-response',status:'cancelled',usage:{input_tokens:7,output_tokens:2,total_tokens:9}}});await finalUsage.runTimerDelay(50);await openingNewChat;
 const finalReports=finalUsage.calls.filter(call=>call.url.endsWith('/usage/report'));assert.equal(finalReports.length,1,'New chat drains and reports the final interrupted response before reset');assert.equal(JSON.parse(finalReports[0].args.body).events[0].event_id,'last-response');assert.equal(finalChannel.readyState,'closed');assert.match(finalUsage.status(),/ready/i);console.log('PASS integration: New chat cancels, captures final usage, flushes it, then resets');

 const drainAll=setup();await drainAll.trigger('.agentos-start');const drainChannel=drainAll.peers[0].channel;for(let index=0;index<205;index++){drainChannel.emit({type:'response.done',response:{id:'drain-'+index,status:'completed',usage:{input_tokens:1,output_tokens:0,total_tokens:1}}});}await drainAll.trigger('.agentos-sidebar__new');const drainBatches=drainAll.calls.filter(call=>call.url.endsWith('/usage/report')).map(call=>JSON.parse(call.args.body).events.length);assert.deepEqual(drainBatches,[100,100,5],'lifecycle drain sends every batch before reset');console.log('PASS integration: New chat drains every usage batch over the 100-event request limit');

 const budgeted=setup({token:(n,response)=>response({client_secret:'fake-secret',session_id:'budget-session',session_ticket:'budget-ticket',model:'gpt-realtime-2.1-mini',session_cap:0,realtime_budget:20}),usageReport:(body,response)=>response({allowed:true,session_cap:0,realtime_budget:8})});
 await budgeted.trigger('.agentos-start');const budgetChannel=budgeted.peers[0].channel;
 budgetChannel.emit({type:'response.done',response:{id:'budget-a',status:'completed',usage:{input_tokens:8,output_tokens:4,total_tokens:12}}});
 await budgeted.runTimerDelay(60000);
 budgetChannel.emit({type:'response.done',response:{id:'budget-b',status:'completed',usage:{input_tokens:5,output_tokens:3,total_tokens:8}}});
 await waitUntil(()=>budgetChannel.readyState==='closed','finite voice budget shutdown');
 assert.equal(budgetChannel.readyState,'closed','the local copy of a finite period budget cuts voice as soon as actual usage reaches it');assert.match(budgeted.status(),/budget|limit/i);
 console.log('PASS integration: browser-enforced operational budget stops at the last confirmed period allowance');

 const warningBudget=setup({token:(n,response)=>response({client_secret:'fake-secret',session_id:'warn-session',session_ticket:'warn-ticket',model:'gpt-realtime-2.1-mini',session_cap:0,realtime_budget:null,warnings:[{reasons:['realtime_tokens']}]})});
 await warningBudget.trigger('.agentos-start');const warningChannel=warningBudget.peers[0].channel;warningChannel.emit({type:'response.done',response:{id:'warn-a',status:'completed',usage:{input_tokens:2000,output_tokens:2000,total_tokens:4000}}});
 assert.equal(warningChannel.readyState,'open','a warning-only plan does not become a local hard cap');assert.match(warningBudget.status(),/allow.*continued use/i,'warning-only overage appears in the existing status region');console.log('PASS integration: warning-only period usage stays non-blocking and visible');

 const cappedRecording=setup({mode:'text',mediaRecorder:true});await cappedRecording.trigger('.agentos-text-record');await cappedRecording.runTimerDelay(60000);assert.ok(cappedRecording.calls.some(c=>c.url.endsWith('/text-transcription')));assert.match(cappedRecording.status(),/60-second limit/i);
 console.log('PASS integration: text dictation auto-stops and uploads only a 60-second audio clip');

 let textRound=0;
 const toolText=setup({mode:'text',text:(body,response)=>{textRound++;return response(textRound===1?{text:'',session_id:body.session_id,tool_ticket:'text-ticket',tool_continuation:'continue-1',tool_calls:[{call_id:'text-call',name:'mcp_lookup',arguments:'{}'}]}:{text:'Your course is complete.',session_id:body.session_id,tool_ticket:'text-ticket',usage:{session_text_tokens:30,session_total_tokens:30}})},tool:(body,response)=>response(body.approval_id?{output:'{"progress":100}'}:{approval_required:true,approval_id:'approval-text',tool:'Read progress',connection:'Course data',arguments:{}})});
 toolText.find('.agentos-text-input').value='How is my course?';const sendingToolText=toolText.trigger('.agentos-text-send');await tick();await tick();assert.ok(toolText.find('.agentos-tool-approve'));await toolText.trigger('.agentos-tool-approve');await sendingToolText;
 assert.deepEqual(toolText.messages(),['How is my course?','Your course is complete.']);const textCalls=toolText.calls.filter(c=>c.url.endsWith('/text-response'));const continuationPayload=JSON.parse(textCalls[1].args.body);assert.equal(continuationPayload.tool_continuation,'continue-1');assert.equal('tool_outputs' in continuationPayload,false);assert.equal(toolText.tracks.length,0);
 console.log('PASS integration: text tool approval resumes with server-owned continuation and no microphone');
 console.log('PASS integration: realtime embed suite reached completion sentinel');
 clearTimeout(completionWatchdog);
})().catch(error=>{clearTimeout(completionWatchdog);console.error(error);process.exitCode=1});
