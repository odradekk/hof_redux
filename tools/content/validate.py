#!/usr/bin/env python3
"""Validate catalog shape, references, numeric ranges and reused assets without PHP."""
import argparse,hashlib,json
from pathlib import Path

def validate(directory,assets):
    manifest=json.loads((directory/'manifest.json').read_text()); catalogs={k:json.loads((directory/f'{k}.json').read_text()) for k in manifest['counts']}; issues=[]; warnings=[]; checks=0
    def issue(code,where,detail): issues.append({'code':code,'where':where,'detail':detail})
    def reference(kind,id,where):
        nonlocal checks
        checks+=1
        if str(id) not in catalogs[kind]: issue('missing_reference',where,f'{kind}/{id}')
    def combat_reference(id,where):
        reference('monsters',id,where)
        row=catalogs['monsters'].get(str(id),{})
        if row.get('availability')=='catalog_only' or row.get('data',{}).get('preview_only'): issue('noncombat_reference',where,str(id))
    for kind,rows in catalogs.items():
        if len(rows)!=manifest['counts'][kind]: issue('count',kind,'Manifest count differs')
        for id,row in rows.items():
            where=f'{kind}/{id}'; d=row['data']; src=row['source']
            if row['id']!=id or src['sha256']!=manifest['sources'].get(src['path']): issue('provenance',where,'ID or source fingerprint mismatch')
            for key in ['img','img_male','img_female']:
                if key in d:
                    checks+=1; folder='char' if kind in ['monsters','jobs'] else 'icon'
                    if not (assets/folder/d[key]).is_file(): issue('missing_asset',where,f'{folder}/{d[key]}')
            for image in d.get('image_variants',[]):
                checks+=1
                if not (assets/'char'/image).is_file(): issue('missing_asset',where,'char/'+image)
            if 'land' in d:
                for prefix in ['land_','bg_']:
                    checks+=1
                    if not (assets/'other'/f"{prefix}{d['land']}.gif").is_file():
                        if d.get('unlock',{}).get('kind')=='unavailable': warnings.append({'code':'inactive_missing_asset','where':where,'detail':f"other/{prefix}{d['land']}.gif"})
                        else: issue('missing_asset',where,f"other/{prefix}{d['land']}.gif")
            if kind=='items':
                for id2,amount in d.get('need',{}).items():
                    reference('items',id2,where+'.need')
                    if int(amount)<=0: issue('quantity',where,str(amount))
                for field in ['buy','sell','handle']:
                    if field in d and float(d[field])<0: issue('negative_number',where+'.'+field,str(d[field]))
            if kind=='skills':
                summons=d.get('summon',[])
                if not isinstance(summons,list): summons=[summons]
                for id2 in summons: combat_reference(id2,where+'.summon')
                if 'target' in d and (len(d['target'])!=3 or d['target'][0] not in ['self','friend','enemy','all'] or d['target'][1] not in ['individual','multi','all'] or int(d['target'][2])<=0): issue('target',where,str(d['target']))
            if kind=='jobs':
                for id2 in d.get('change',[]): reference('jobs',id2,where+'.change')
            if kind in ['monsters','base_characters']:
                if 'guard' in d and d['guard'] not in ['always','never','life25','life50','life75','prob25','prob50','prob75']: issue('guard_policy',where,str(d['guard']))
                if 'position' in d and d['position'] not in ['front','back']: issue('position',where,str(d['position']))
            if kind=='monsters':
                if not d.get('preview_only') and row.get('availability')!='catalog_only':
                    for field in ['maxhp','hp','maxsp','sp','str','int','dex','spd','luk','atk','def','action','judge']:
                        if field not in d: issue('required_field',where,field)
                    for field in ['level','maxhp','hp','maxsp','sp','str','int','dex','spd','luk']:
                        value=d.get(field)
                        if isinstance(value,dict):
                            if value.get('formula')!='population_hp' or field not in ['maxhp','hp'] or value.get('base',0)<=0 or value.get('per_account',-1)<0: issue('numeric_formula',where,field)
                            continue
                        try:
                            minimum=1 if field in ['level','maxhp','hp'] else 0
                            if float(value)<minimum: issue('numeric_range',where,field)
                        except (ValueError,TypeError): issue('invalid_numeric',where,field)
                    for field,length in [('atk',2),('def',4)]:
                        if len(d.get(field,[]))!=length: issue('stat_vector',where,field)
                        for value in d.get(field,[]):
                            try:
                                if float(value)<0: issue('numeric_range',where,field)
                            except (ValueError,TypeError): issue('invalid_numeric',where,field)
                if 'quantity' in d and len(d['quantity'])!=len(d['judge']): issue('pattern_length',where,'Quantity/judge lengths differ')
                for id2 in d.get('action',[]): reference('skills',id2,where+'.action')
                for id2 in d.get('judge',[]): reference('conditions',id2,where+'.judge')
                if len(d.get('judge',[]))!=len(d.get('action',[])): issue('pattern_length',where,'Judge/action lengths differ')
                drops=d.get('itemtable',{})
                if isinstance(drops,dict):
                    for id2,weight in drops.items():
                        reference('items',str(id2)[:4],where+'.itemtable')
                        if int(weight)<0: issue('drop_weight',where,str(weight))
                    if sum(map(int,drops.values()))>10000: issue('drop_weight',where,'Drop weights exceed 10000')
                for id2 in d.get('Slave',{}): combat_reference(id2,where+'.Slave')
                for id2 in d.get('SlaveSpecify',[]): combat_reference(id2,where+'.SlaveSpecify')
            if kind=='areas':
                for id2,entry in d['encounters'].items():
                    if int(entry[0])>0: combat_reference(id2,where+'.encounters')
                    reference('monsters',id2,where+'.encounters')
                    if int(entry[0])<0: issue('encounter_weight',where,str(entry))
                if d['unlock']['kind']=='item': reference('items',d['unlock']['item'],where+'.unlock')
            if kind=='recipes':
                reference('items',d['item'],where)
                for id2 in d['ingredients']: reference('items',id2,where+'.ingredients')
            if kind=='base_characters':
                reference('jobs',d['job'],where+'.job')
                for field in ['weapon','shield','armor']:
                    if field in d: reference('items',d[field],where+'.'+field)
                for id2 in d['skill']: reference('skills',id2,where+'.skill')
                judges,quantities,actions=[x.split('<>') for x in d['Pattern'].split('|')]
                for id2 in judges: reference('conditions',id2,where+'.Pattern')
                for id2 in actions: reference('skills',id2,where+'.Pattern')
                if not len(judges)==len(quantities)==len(actions): issue('pattern_length',where,'Starter pattern lengths differ')
            if kind=='skill_tree':
                reference('skills',d['skill'],where)
                def walk(cond):
                    for op,value in cond.items():
                        if op in ['all','any']:
                            for child in value: walk(child)
                        elif op in ['learned','not_learned']: reference('skills',value,where+'.when')
                        elif op=='job': reference('jobs',value,where+'.when')
                        elif op!='minimum_level': issue('condition',where,op)
                walk(d['when'])
            if kind=='enchant_pools':
                for id2 in d['low']+d['high']: reference('enchants',id2,where)
            if kind=='economy_rules' and id=='shop':
                for id2 in d['values']: reference('items',id2,where)
            if kind=='class_changes':
                reference('jobs',id,where); reference('jobs',d['from_job'],where)
    actual=hashlib.sha256(''.join((directory/f'{k}.json').read_text() for k in sorted(catalogs)).encode()).hexdigest()
    if actual!=manifest['content_version']: issue('version','manifest','Content digest mismatch')
    return {'checks':checks,'issues':issues,'warnings':warnings,'counts':manifest['counts'],'content_version':actual}
if __name__=='__main__':
    ap=argparse.ArgumentParser(); ap.add_argument('--content',type=Path,default=Path('content')); ap.add_argument('--assets',type=Path,required=True); ap.add_argument('--report',type=Path); args=ap.parse_args(); result=validate(args.content,args.assets); text=json.dumps(result,ensure_ascii=False,indent=2)+'\n'; print(text)
    if args.report: args.report.write_text(text)
    raise SystemExit(1 if result['issues'] else 0)
