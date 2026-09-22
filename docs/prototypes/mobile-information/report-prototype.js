// Deterministic display fixtures, not a combat engine. Each step groups related events.
const reportActors = [
  ['a','阿岚','mon_079.gif','我方','前排',100,40],
  ['m','梨央','mon_018.gif','我方','后排',100,40],
  ['h','白露','mon_213.gif','我方','后排',100,40],
  ['r','林间','mon_219rr.gif','我方','后排',100,40],
  ['s','影','mon_216y.gif','我方','前排',100,40],
  ['boss','暗黑龙','mon_013r.gif','敌方','前排',2000,120],
  ['g1','持斧哥布林 · 1','mon_053.gif','敌方','前排',40,20],
  ['g2','持斧哥布林 · 2','mon_053.gif','敌方','前排',90,20],
  ['b1','小蝙蝠 · 1','mon_121.gif','敌方','后排',60,40],
  ['b2','小蝙蝠 · 2','mon_121.gif','敌方','后排',60,40],
  ['g3','持斧哥布林 · 3','mon_053.gif','敌方','前排',100,20],
].map(([id,name,img,side,lane,hp,sp])=>({id,name,img,side,lane,hp,sp,maxHp:hp,maxSp:sp,states:[]}));
const reportSteps = (()=>{
  let actors=structuredClone(reportActors), eventNumber=0, actionCount=0;
  const steps=[];
  function record(title, events, updates=[], options={}) {
    const before=structuredClone(actors);
    if(options.summon)actors.push(structuredClone(options.summon));
    for(const update of updates)Object.assign(actors.find(actor=>actor.id===update.id),update);
    if(!options.waitStart)actionCount++;
    const firstEvent=eventNumber+1;
    eventNumber+=events.length;
    const fixture=reportActionFixtures[steps.length];
    steps.push({...fixture,title,events:events.map((text,index)=>({...fixture.events[index],text})),firstEvent,lastEvent:eventNumber,actionCount,before,after:structuredClone(actors)});
  }
  record('阿岚开始蓄力',['开始蓄力，等待释放。','此时未扣 SP，也未锁定攻击目标。'],[{id:'a',states:['蓄力']}],{waitStart:true});
  record('阿岚攻击持斧哥布林 · 1',['蓄力完成，消耗 4 SP。','本次攻击造成 18 点实际 HP 损失。'],[{id:'a',sp:36,states:[]},{id:'g1',hp:22}]);
  record('小蝙蝠 · 1 攻击阿岚',['阿岚受到 20 点伤害。'],[{id:'a',hp:80}]);
  record('白露治疗阿岚',['白露消耗 4 SP。','阿岚恢复 12 HP。'],[{id:'h',sp:36},{id:'a',hp:92}]);
  record('梨央攻击持斧哥布林 · 1',['梨央消耗 5 SP。','持斧哥布林 · 1 损失 12 HP。'],[{id:'m',sp:35},{id:'g1',hp:10}]);
  record('林间击败持斧哥布林 · 1',['攻击造成 10 点实际 HP 损失。','持斧哥布林 · 1 死亡。','记录示例击杀收益：金钱 120；是否正式到账由本场结算决定。'],[{id:'g1',hp:0}]);
  record('暗黑龙攻击影',['暗黑龙消耗 8 SP。','影受到 30 点伤害。'],[{id:'boss',sp:112},{id:'s',hp:70}]);
  record('影攻击暗黑龙',['暗黑龙受到 35 点伤害。'],[{id:'boss',hp:1965}]);
  record('小蝙蝠 · 2 使阿岚中毒',['小蝙蝠 · 2 消耗 5 SP。','阿岚进入中毒状态。'],[{id:'b2',sp:35},{id:'a',states:['中毒']}]);
  record('暗黑龙召唤随从',['暗黑龙消耗 10 SP。','召唤随从 · 1 加入敌方后排；敌方单位数变为 7。'],[{id:'boss',sp:102}],{summon:{id:'summon1',name:'召唤随从 · 1',img:'mon_053.gif',side:'敌方',lane:'后排',hp:70,maxHp:70,sp:20,maxSp:20,states:[],summoned:true}});
  record('林间攻击召唤随从',['召唤随从 · 1 损失 20 HP。'],[{id:'summon1',hp:50}]);
  record('梨央攻击两名哥布林',['梨央消耗 8 SP。','持斧哥布林 · 2 损失 25 HP。','持斧哥布林 · 3 损失 25 HP。'],[{id:'m',sp:27},{id:'g2',hp:65},{id:'g3',hp:75}]);
  record('阿岚攻击暗黑龙，随后毒发',['暗黑龙损失 30 HP。','本次行动后，阿岚因中毒损失 4 HP。'],[{id:'boss',hp:1935},{id:'a',hp:88}]);
  record('白露治疗影',['白露消耗 6 SP。','影恢复 25 HP。'],[{id:'h',sp:30},{id:'s',hp:95}]);
  record('暗黑龙击败影',['暗黑龙消耗 15 SP。','影损失 95 HP。','影死亡，保留该角色的死亡状态。'],[{id:'boss',sp:87},{id:'s',hp:0}]);
  record('林间攻击小蝙蝠 · 1',['小蝙蝠 · 1 损失 30 HP。'],[{id:'b1',hp:30}]);
  record('梨央待机',['没有命中战术，正常待机。','不自动替换为普通攻击。']);
  record('白露开始咏唱',['开始咏唱，等待释放。','开始等待时未消耗 SP。'],[{id:'h',states:['咏唱']}],{waitStart:true});
  record('持斧哥布林 · 3 攻击阿岚',['阿岚受到 15 点伤害。'],[{id:'a',hp:73}]);
  record('白露释放治疗',['咏唱完成，白露消耗 6 SP。','阿岚恢复 20 HP。'],[{id:'h',sp:24,states:[]},{id:'a',hp:93}]);
  record('小蝙蝠 · 1 攻击林间',['林间受到 10 点伤害。'],[{id:'r',hp:90}]);
  record('阿岚攻击暗黑龙，随后毒发',['暗黑龙损失 25 HP。','阿岚因中毒损失 4 HP。'],[{id:'boss',hp:1910},{id:'a',hp:89}]);
  record('林间击败召唤随从',['召唤随从 · 1 损失 50 HP。','召唤随从 · 1 死亡。','死亡召唤物退出后续通常阵型，事件与本步变化仍保留。'],[{id:'summon1',hp:0}]);
  record('梨央防御，样例结束',['梨央执行防御。','预置展示样例在此结束，结果为平局；24 步不是正式玩法的行动上限。']);
  return steps;
})();
const reportReader={step:null,originStep:0,directoryY:0,directoryOffset:0,presentation:new URLSearchParams(location.search).get('presentation')==='detail'?'detail':'battle'};
function reportFormation(actors,side){
  const visible=actors.filter(actor=>actor.side===side&&!(actor.summoned&&actor.hp===0));
  return `<section><h3>${side} · ${visible.length} 名</h3><div class="formation">${['前排','后排'].map(lane=>`<div class="lane"><strong>${lane}</strong>${visible.filter(actor=>actor.lane===lane).map(actor=>`<div class="unit ${actor.hp===0?'dead':''}">${sprite(actor.img)}<div class="grow"><b>${actor.name}</b><small style="display:block">HP ${actor.hp}/${actor.maxHp}<br>SP ${actor.sp}/${actor.maxSp}<br>${actor.hp===0?'死亡':actor.states.join('、')||'正常'}</small><div class="meter"><span style="width:${100*actor.hp/actor.maxHp}%"></span></div></div></div>`).join('')||'<p class="muted">无单位</p>'}</div>`).join('')}</div></section>`;
}
function stepChanges(step){
  const changed=step.after.filter(actor=>{const old=step.before.find(a=>a.id===actor.id);return !old||old.hp!==actor.hp||old.sp!==actor.sp||old.states.join()!==actor.states.join()});
  if(!changed.length)return '<p>本步没有 HP/SP 或持续状态变化。</p>';
  return `<div class="stack">${changed.map(actor=>{const old=step.before.find(a=>a.id===actor.id);return `<div class="card"><b>${actor.name}</b><p>HP ${old?.hp??'未出现'} → ${actor.hp} / ${actor.maxHp}<br>SP ${old?.sp??'未出现'} → ${actor.sp} / ${actor.maxSp}</p><small>${old?(old.hp===0?'死亡':old.states.join('、')||'正常'):'未出现'} → ${actor.hp===0?'死亡':actor.states.join('、')||'正常'}</small></div>`}).join('')}</div>`;
}
function reportPagingControls(){
  return `<div class="report-paging" aria-label="步骤翻页">${btn('上一步','report-prev',reportReader.step===0?'disabled':'')}${btn('返回目录','report-directory')}${btn('下一步','report-next',reportReader.step===reportSteps.length-1?'disabled':'')}</div>`;
}
function detailReport(){
  const mode=state.report==='formal'?'正式战示例 · 公开 · 保留 90 天':'模拟战示例 · 仅本人及有权限管理员 · 保留 30 天';
  if(reportReader.step===null)return `<div class="eyebrow">Battle report</div><h1>战报目录</h1><div class="tabs">${btn('正式战示例','report-type','data-type="formal"')}${btn('模拟战示例','report-type','data-type="sim"')}</div><div class="card"><span class="tag">${mode}</span><h2>平局 · 预置样例</h2><p>${state.report==='formal'?'示例已结算：金钱 +120。':'不产生正式成长与资产收益。'}</p><p class="muted">共 ${reportSteps.length} 个行动步骤。以下是固定展示数据，不是按钮出战的演算结果。</p></div><p>先浏览摘要，再点某一步查看关联事件与双方状态。</p><div class="report-directory">${reportSteps.map((step,i)=>btn(`<span class="tag">${String(i+1).padStart(2,'0')}</span><span>${step.title}<small>${step.events.length} 条关联事件</small></span>`,'report-step',`id="report-step-${i}" data-index="${i}"`)).join('')}</div>`;
  const step=reportSteps[reportReader.step];
  return `<article class="report-reader"><span class="tag">${mode}</span><h1 id="report-step-title" tabindex="-1">第 ${reportReader.step+1} / ${reportSteps.length} 步</h1><h2>${step.title}</h2><p class="muted">行动计数 ${step.actionCount} · 事件 ${step.firstEvent}–${step.lastEvent} · 页码不等于回合数</p>${reportPagingControls()}<form class="report-jump"><label>跳到第 <input name="step" type="number" inputmode="numeric" min="1" max="${reportSteps.length}" step="1" required value="${reportReader.step+1}"> 步</label><button type="submit">跳转</button><p class="bad" id="report-jump-error" role="alert"></p></form><h2>本步事件</h2><ol class="report-events">${step.events.map(event=>`<li>${event.text}</li>`).join('')}</ol><h2>数值与状态变化</h2>${stepChanges(step)}<h2 style="margin-top:20px">本步结束后的双方阵型</h2><p class="muted">完整显示，可在本页滚动；不缩小文字或裁掉随从。</p><div class="desktop-columns">${reportFormation(step.after,'我方')}${reportFormation(step.after,'敌方')}</div>${reportPagingControls()}</article>`;
}
function openReportStep(index,fromDirectory=false){
  if(!Number.isInteger(index)||index<0||index>=reportSteps.length)return false;
  if(fromDirectory){reportReader.directoryY=window.scrollY;reportReader.originStep=index;reportReader.directoryOffset=document.querySelector(`#report-step-${index}`).getBoundingClientRect().top}
  reportReader.step=index;render();
  const title=document.querySelector('#report-step-title');
  title.focus({preventScroll:true});title.scrollIntoView({block:'start'});
  notice(`第 ${index+1} 步：${reportSteps[index].title}`);
  return true;
}
function returnReportDirectory(){
  reportReader.step=null;render();
  const source=document.querySelector(`#report-step-${reportReader.originStep}`);
  source?.focus({preventScroll:true});
  window.scrollTo({top:source?window.scrollY+source.getBoundingClientRect().top-reportReader.directoryOffset:reportReader.directoryY,behavior:'auto'});
}
