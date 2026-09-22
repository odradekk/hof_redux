// Disposable comparison: same report and paging, two different reading hierarchies.
// Structured fixture events supply meaning; text and net HP differences are never parsed as actions.
const reportKinds = {
  attack:['攻击','斩'], heal:['治疗','愈'], death:['击败','殁'], summon:['召唤','唤'],
  charge:['蓄力 / 咏唱','蓄'], status:['状态','印'], wait:['待机','候'], defend:['防御','守'],
};
function reportPresentationControls(){
  return `<div class="report-comparison" aria-label="原型战报表现对比"><small>表现对比 · 同一份战报</small><div>${['battle','detail'].map(mode=>btn(mode==='battle'?'战场版':'明细版','report-presentation',`data-presentation="${mode}" aria-pressed="${reportReader.presentation===mode}"`)).join('')}</div></div>`;
}
function pagedReport(){
  return reportPresentationControls()+(reportReader.presentation==='detail'?detailReport():battleReport());
}
function changeReportPresentation(mode){
  // Preserve the visible directory item across layouts with different header/row heights.
  const anchor=reportReader.step===null?[...document.querySelectorAll('[data-action="report-step"]')].map(node=>({id:node.id,top:node.getBoundingClientRect().top})).sort((a,b)=>Math.abs(a.top)-Math.abs(b.top))[0]:null;
  reportReader.presentation=mode;
  const url=new URL(location.href);url.searchParams.set('presentation',mode);history.replaceState({},'',url);
  render();
  document.querySelector(`[data-presentation="${mode}"]`).focus({preventScroll:true});
  if(anchor){const source=document.getElementById(anchor.id);window.scrollTo({top:window.scrollY+source.getBoundingClientRect().top-anchor.top,behavior:'auto'})}
  notice(`已切换${mode==='battle'?'战场版':'明细版'}，阅读步骤不变。`);
}
function battlePortrait(actor,role){
  return `<div class="battle-portrait ${actor.id==='boss'?'boss-portrait':''} ${actor.hp===0?'fallen-portrait':''}">${sprite(actor.img)}<strong>${actor.name}</strong><small>${role}${actor.hp===0?' · 阵亡':''}</small></div>`;
}
function eventImpact(event,step){
  const target=step.after.find(actor=>actor.id===event.targetId);
  const kinds={damage:['伤害',`−${event.amount} HP`],heal:['治疗',`+${event.amount} HP`],sp:['消耗',`−${event.amount} SP`],death:['阵亡','阵亡'],summon:['召唤','加入战场'],status:['状态',event.statusName],charge:['准备','等待释放']};
  const value=kinds[event.kind];
  if(!value)return '';
  return `<span class="battle-impact impact-${event.kind}"><small>${target?.name||step.after.find(a=>a.id===step.actorId).name} · ${value[0]}</small><b>${value[1]}</b></span>`;
}
function battleUnit(actor,step){
  const before=step.before.find(a=>a.id===actor.id);
  const delta=before?actor.hp-before.hp:0;
  const flags=[];
  if(actor.id===step.actorId)flags.push('行动');
  if(step.targetIds.includes(actor.id))flags.push('目标');
  if(!before)flags.push('新加入');
  const changed=!before||actor.hp!==before.hp||actor.sp!==before.sp||actor.states.join()!==before.states.join();
  const fraction=Math.max(0,Math.min(1,actor.hp/actor.maxHp));
  return `<div class="battle-unit ${actor.id==='boss'?'boss-unit':''} ${actor.hp===0?'fallen':''} ${changed?'affected':''}">
    <div class="unit-flags">${flags.map(flag=>`<span>${flag}</span>`).join('')}</div>${sprite(actor.img)}<b>${actor.name}</b>
    <span class="unit-condition">${actor.hp===0?'阵亡':actor.states.join(' · ')||'正常'}</span>
    <div class="battle-hp ${fraction<=.25?'critical':fraction<=.5?'low':''}"><span style="width:${fraction*100}%"></span></div>
    <span class="unit-vitals">HP ${actor.hp}/${actor.maxHp}<br>SP ${actor.sp}/${actor.maxSp}</span>
    ${delta?`<span class="unit-delta ${delta<0?'loss':'gain'}">HP 净变 ${delta>0?'+':'−'}${Math.abs(delta)}</span>`:''}
  </div>`;
}
function battleSide(step,side){
  const actors=step.after.filter(a=>a.side===side&&!(a.summoned&&a.hp===0));
  const lanes=side==='敌方'?['后排','前排']:['前排','后排'];
  return `<section class="battle-side ${side==='敌方'?'enemy-side':'ally-side'}" aria-label="${side}阵型"><h2>${side}<small>${actors.length} 名 · 本步结束状态</small></h2>${lanes.map(lane=>`<div class="battle-lane"><h3>${lane}</h3><div class="battle-rank">${actors.filter(a=>a.lane===lane).map(a=>battleUnit(a,step)).join('')||'<p>无单位</p>'}</div></div>`).join('')}</section>`;
}
function stepHighlightKind(step){return step.events.some(event=>event.kind==='death')?'death':step.kind}
function battleReport(){
  const simulation=state.report==='sim';
  if(reportReader.step===null)return `<section class="battle-report"><div class="battle-result"><p class="battle-kicker">${simulation?'模拟战 · 仅本人及有权限管理员可见':'正式战 · 公开'}</p><h1>战斗结束<span>平局</span></h1><div class="result-opponents">冒险队 <span>对阵</span> 暗黑龙与随从</div><p class="battle-loot">${simulation?'模拟结果 · 无正式收益':'已结算 · 金钱 +120'}</p><small>固定展示样例 · ${reportSteps.length} 步，非正式行动上限</small></div><div class="battle-report-types">${btn('正式战示例','report-type','data-type="formal"')}${btn('模拟战示例','report-type','data-type="sim"')}</div><h2 class="battle-directory-title">战斗经过 <small>选择一步开始复盘</small></h2><div class="battle-directory">${reportSteps.map((step,index)=>btn(`<span class="battle-step-number">${String(index+1).padStart(2,'0')}</span><span class="event-sigil kind-${stepHighlightKind(step)}" aria-hidden="true">${reportKinds[stepHighlightKind(step)][1]}</span><span class="battle-summary"><b>${step.title}</b><small>${reportKinds[stepHighlightKind(step)][0]} · ${step.events.length} 条记录</small></span>`,'report-step',`id="report-step-${index}" data-index="${index}"`)).join('')}</div><p class="battle-footnote">${simulation?'模拟战保留 30 天':'正式战保留 90 天'} · 预置样例，不是按钮出战的演算结果。</p></section>`;
  const step=reportSteps[reportReader.step];
  const actor=step.before.find(a=>a.id===step.actorId);
  const targets=step.targetIds.map(id=>step.after.find(a=>a.id===id));
  return `<article class="battle-report battle-reader"><p class="battle-kicker">${simulation?'模拟战 · 无正式收益':'正式战 · 公开'}</p>
    <h1 id="report-step-title" tabindex="-1"><span>第 ${reportReader.step+1} / ${reportSteps.length} 步</span>${step.title}</h1>
    <div class="battle-action"><div class="action-protagonist">${battlePortrait(actor,'行动者')}</div><div class="action-direction"><span class="event-sigil kind-${step.kind}" aria-hidden="true">${reportKinds[step.kind][1]}</span><strong>${step.actionLabel}</strong>${targets.length?'<span aria-hidden="true">→</span>':''}</div><div class="action-targets">${targets.length?targets.map(a=>battlePortrait(a,step.kind==='summon'?'新召唤':'目标')).join(''):`<p>${step.kind==='charge'?'尚未锁定目标':'本步无行动目标'}</p>`}</div></div>
    <div class="battle-impacts">${step.events.filter(e=>['damage','heal','death','summon','status','charge'].includes(e.kind)).map(e=>eventImpact(e,step)).join('')}</div>
    ${reportPagingControls()}
    <div class="battlefield">${battleSide(step,'敌方')}<div class="battle-frontline"><span>交战前线</span></div>${battleSide(step,'我方')}</div>
    <details class="battle-details"><summary>查看本步完整记录与前后数值</summary><ol class="battle-event-log">${step.events.map(e=>`<li class="event-${e.kind}">${e.text}</li>`).join('')}</ol>${stepChanges(step)}</details>
    ${reportPagingControls()}<form class="report-jump"><label>跳到第 <input name="step" type="number" inputmode="numeric" min="1" max="${reportSteps.length}" step="1" required value="${reportReader.step+1}"> 步</label><button type="submit">跳转</button><p class="bad" id="report-jump-error" role="alert"></p></form>
    <details class="battle-metadata"><summary>战报信息</summary><p>${simulation?'模拟战 · 私有 · 保留 30 天':'正式战 · 公开 · 保留 90 天'}<br>行动计数 ${step.actionCount} · 事件 ${step.firstEvent}–${step.lastEvent}<br>步骤不是回合。HP 净变化不是单次伤害；完整记录保留各次事件。<br>静态预置样例，未运行战斗演算。</p></details>
  </article>`;
}
