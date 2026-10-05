#!/usr/bin/env python3
"""Static, fail-closed extraction. Never starts PHP or evaluates source expressions."""
import argparse, hashlib, json, re
from pathlib import Path

class Unsupported(ValueError): pass
TOKEN = re.compile(r'''\s+|/\*.*?\*/|//[^\n]*|\#[^\n]*|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|\d+(?:\.\d+)?|\$[A-Za-z_][\w]*|[A-Za-z_][\w]*|=>|==|&&|\|\||->|\+=|\.=|!=|<=|>=|[^\s]''', re.S)
def tokens(text):
    return [(m.group(),m.start()) for m in TOKEN.finditer(text) if not m.group().isspace() and not m.group().startswith(('//','/*','#'))]
def clean(text):
    return re.sub(r'''/\*.*?\*/|//[^\n]*|\#[^\n]*|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*' ''', lambda m: (' ' * len(m.group())) if m.group().startswith(('//','/*','#')) else m.group(), text, flags=re.S|re.X)
class Parser:
    duplicates=[]
    symbols=set()
    def __init__(self,text,variables=None): self.ts=tokens(text); self.i=0; self.variables=variables or {}
    def peek(self): return self.ts[self.i][0] if self.i<len(self.ts) else ''
    def pop(self,expected=None):
        t=self.peek()
        if not t or (expected is not None and t!=expected): raise Unsupported(f'Expected {expected}, got {t!r} at token {self.i}')
        self.i+=1; return t
    def value(self):
        value=self.atom()
        while self.peek()=='*':
            self.pop(); other=self.atom()
            if type(value) not in (int,float) or type(other) not in (int,float): raise Unsupported("Nonnumeric multiplication")
            value*=other
        return value
    def atom(self):
        t=self.pop()
        if t=='array':
            self.pop('('); result={}; index=0
            while self.peek()!=')':
                first=self.value()
                if self.peek()=='=>':
                    self.pop(); key=str(first); value=self.value()
                    if key.isdigit(): index=max(index,int(key)+1)
                else: key=str(index); index+=1; value=first
                if key in result: Parser.duplicates.append({'key':key,'previous':result[key],'replacement':value})
                result[key]=value
                if self.peek()!=',': break
                self.pop(',')
            self.pop(')')
            return list(result.values()) if list(result)==[str(i) for i in range(len(result))] else result
        if t[0] in ('"',"'"):
            s=t[1:-1]
            s=s.replace('\\'+t[0],t[0]).replace('\\\\','\\')
            if t[0]=='"':
                s=s.replace('\\n','\n').replace('\\r','\r').replace('\\t','\t').replace('{$Quantity}','←←')
                if '$' in s: raise Unsupported(f'Interpolation not whitelisted: {t}')
            return s
        if re.fullmatch(r'\d+(?:\.\d+)?',t): return float(t) if '.' in t else int(t)
        if t.lower() in ('true','false','null'): return {'true':True,'false':False,'null':None}[t.lower()]
        if t in ('FRONT','BACK'): return t.lower()
        if t in self.variables: return self.variables[t]
        if t in self.symbols: return t
        if t=='-': return -self.value()
        raise Unsupported(f'Nonliteral expression {t!r}')

def cases(text):
    text=clean(text)
    matches=list(re.finditer(r'\bcase\s+("[^"]+"|\'[^^\']+\'|[\w]+)\s*:',text))
    for i,m in enumerate(matches):
        end=matches[i+1].start() if i+1<len(matches) else len(text)
        body=text[m.end():end]
        yield m.group(1).strip('\'"'), body, m.start()

def main():
    ap=argparse.ArgumentParser(); ap.add_argument('--source',type=Path,required=True,help='Reference root containing data/, either original checkout or legacy/')
    ap.add_argument('--output',type=Path,default=Path('content')); args=ap.parse_args()
    source=args.source; out=args.output; out.mkdir(parents=True,exist_ok=True)
    hashes={}; catalogs={}; warnings=[]
    def read(path):
        b=(source/path).read_bytes(); hashes[path]=hashlib.sha256(b).hexdigest(); return b.decode('utf-8-sig')
    def wrap(id,data,path,text,pos): return {'id':str(id),'data':data,'source':{'path':path,'line':text[:pos].count('\n')+1,'sha256':hashes[path]}}
    constants=read('setting.reference.php')
    for constant,value in [('FRONT','front'),('BACK','back')]:
        if not re.search(r'define\("'+constant+r'","'+value+r'"\)',constants): raise Unsupported('Constant mapping changed: '+constant)
    for kind,file,var in [('items','item','item'),('skills','skill','skill'),('jobs','job','job'),('monsters','monster','monster'),('base_characters','base_char','stat'),('areas','land_info','land')]:
        path=f'data/data.{file}.php'; text=read(path); rows={}
        for id,body,pos in cases(text):
            m=re.search(r'\$'+var+r'\s*=\s*(array\s*\()',body)
            if not m:
                if id.isdigit(): raise Unsupported(f'Missing literal definition {kind}/{id}')
                continue
            variables={}
            prefix=body[:m.start()]
            if '$hp' in prefix:
                hp=re.fullmatch(r'\s*\$hp\s*=\s*(\d+)\s*\+\s*(\d+)\s*\*\s*UserAmount\(\);\s*\$exp\s*=\s*round\(\$hp/2\);\s*\$money\s*=\s*round\(\$hp/3\);\s*',prefix)
                if not hp: raise Unsupported('Unknown monster formula '+prefix)
                variables={'$hp':{'formula':'population_hp','base':int(hp.group(1)),'per_account':int(hp.group(2))},'$exp':{'formula':'round_hp_divide','divisor':2},'$money':{'formula':'round_hp_divide','divisor':3}}
            duplicate_start=len(Parser.duplicates)
            parser=Parser(body[m.start(1):],variables); data=parser.value()
            for duplicate in Parser.duplicates[duplicate_start:]: duplicate.update({'catalog':kind,'id':id,'source':path})
            tail=body[m.start(1)+parser.ts[parser.i][1]:].split('break;')[0].strip().lstrip(';').strip()
            if tail and kind!='areas':
                cycle=re.fullmatch(r'if\(date\("H"\) == (\d+)\)\s*\$monster\["cycle"\]\s*=\s*60\*60\*2;\s*else\s*\$monster\["cycle"\]\s*=\s*60\*60\*72;',tail)
                image=re.fullmatch(r'\$prob\s*=\s*mt_rand\(0,1\);\s*\$monster\["img"\]\s*=\s*\(\$prob\?"([^"]+)":"([^"]+)"\);',tail)
                if kind=='monsters' and cycle: data['cycle']={'kind':'hour_match','hour':int(cycle.group(1)),'matching_seconds':7200,'otherwise_seconds':259200,'timezone':'UTC'}
                elif kind=='monsters' and image: data['image_variants']=[image.group(2),image.group(1)]
                else: raise Unsupported(f'Unrecognized procedural tail {kind}/{id}: {tail}')
            if kind=='monsters' and id in ['1010','1011']: data['preview_only']=True
            if kind=='areas':
                n=re.search(r'\$monster\s*=\s*(array\s*\()',body); data['encounters']=Parser(body[n.start(1):]).value()
            if kind=='monsters':
                if int(id)<2000: data['moneyhold']=100
                data['monster']='1'
            if kind=='items':
                data['type2']='WEAPON' if data['type'] in ['剑','双手剑','匕首','魔杖','杖','弓','鞭'] else ('GUARD' if data['type'] in ['盾','书','甲','衣服','长袍'] else '其他')
            data['no']=str(id)
            if id in rows:
                if data!=rows[id]['data']: raise Unsupported(f'Divergent duplicate {kind}/{id}')
                warnings.append({'kind':'identical_duplicate','catalog':kind,'id':id,'line':text[:pos].count('\n')+1}); continue
            rows[id]=wrap(id,data,path,text,pos)
        catalogs[kind]=rows
    path='data/data.judge_setup.php'; text=read(path); rows={}
    for id,body,pos in cases(text):
        data={}
        for m in re.finditer(r'\$judge\["(\w+)"\]\s*=\s*',body): data[m.group(1)]=Parser(body[m.end():]).value()
        if data: rows[id]=wrap(id,data,path,text,pos)
    judge_path='data/data.judge.php'; judge_text=read(judge_path)
    for id,body,pos in cases(judge_text):
        if id not in rows:
            original=judge_text[pos:].split('\n')[0]
            label=original.split('//',1)[1].strip() if '//' in original else id
            rows[id]=wrap(id,{'exp':label,'selectable':False,'legacy_effect':'empty_unlisted_case'},judge_path,judge_text,pos)
    catalogs['conditions']=rows
    path='data/data.classchange.php'; text=read(path); rows={}
    for id,body,pos in cases(text):
        m=re.search(r'(\d+)\s*<\s*\$char->level\s*&&\s*\$char->job\s*==\s*(\d+)',body)
        if not m: raise Unsupported(f'Unsupported class change {id}')
        rows[id]=wrap(id,{'from_job':m.group(2),'minimum_level':int(m.group(1))+1},path,text,pos)
    catalogs['class_changes']=rows
    path='data/data.create.php'; text=read(path); craft=clean(text).split('return $create;')[0]
    ids=[]
    for m in re.finditer(r'array\s*\(',craft): ids+=Parser(craft[m.start():]).value()
    catalogs['recipes']={str(id):wrap(id,{'item':str(id),'ingredients':catalogs['items'][str(id)]['data']['need'],'fee':0},path,text,0) for id in ids}
    # More complex definitions are represented by a small, explicit data grammar, never PHP.
    path='data/data.enchant.php'; text=read(path); rows={}
    for id,body,pos in cases(text):
        body=body.split('break;')[0]; ops=[]
        for m in re.finditer(r'\$item((?:\["[^"]+"\])+)\s*(\+=|\.=|=(?!=))\s*',body):
            field=re.findall(r'\["([^"]+)"\]',m.group(1))
            expression=body[m.end():].split(';')[0].strip()
            mult=re.fullmatch(r'round\(\$item'+re.escape(m.group(1))+r'\s*\*\s*(\d+\.\d+)\)',expression)
            value=float(mult.group(1)) if mult else Parser(expression).value()
            op={'path':field,'operation':{'+=':'add','.=':'append','=':'set'}[m.group(2)],'value':value}
            if mult: op['operation']='multiply_round'
            if 'if(' in body or 'if (' in body: op['when_type2']='GUARD' if '} else {' in body[:m.start()] else 'WEAPON'
            ops.append(op)
        if not ops and id != '400': raise Unsupported(f'Empty enchant {id}')
        rows[id]=wrap(id,{'operations':ops},path,text,pos)
    catalogs['enchants']=rows
    Parser.symbols=set(rows)
    path='data/data.create.php'; text=read(path); pool_text=clean(text).split('function ItemAbilityPossibility')[1]
    pool_rows={}; aliases=[]
    for id,body,pos in cases(pool_text):
        aliases.append(id)
        low=re.search(r'\$low\s*=\s*(array\s*\()',body)
        high=re.search(r'\$high\s*=\s*(array\s*\()',body)
        if low and high:
            data={'low':[str(x) for x in Parser(body[low.start(1):]).value()],'high':[str(x) for x in Parser(body[high.start(1):]).value()]}
            for alias in aliases: pool_rows[alias]=wrap(alias,data,path,text,text.index('function ItemAbilityPossibility')+len('function ItemAbilityPossibility')+pos)
            aliases=[]
    catalogs['enchant_pools']=pool_rows
    path='class/global.php'; text=read(path); global_data={}
    for id,function in [('shop','ShopList'),('auction_types','CanExhibitType'),('refine_types','CanRefineType')]:
        m=re.search(r'function\s+'+function+r'\(\)\s*\{\s*return\s*(array\s*\()',clean(text))
        global_data[id]=wrap(id,{'values':Parser(clean(text)[m.start(1):]).value()},path,text,m.start())
    catalogs['economy_rules']=global_data
    extract_tree(read,wrap,catalogs)
    path='data/data.land_appear.php'; text=read(path)
    for id,row in catalogs['areas'].items():
        rule={'kind':'unavailable'}
        if id in ['gb0','gb1','gb2']: rule={'kind':'always'}
        m=re.search(r'\$user->item\["(\d+)"\]\)\s*array_push\(\$land,"'+re.escape(id)+r'"\)',clean(text))
        if m: rule={'kind':'item','item':m.group(1)}
        if id=='horh': rule={'kind':'daily_window','from':'02:50','until':'03:00','timezone':'UTC'}
        row['data']['unlock']=rule
    for id in ['1079','1900']:
        catalogs['monsters'][id]['availability']='catalog_only'
        catalogs['monsters'][id]['exclusion_reason']='Unreferenced incomplete source prototype with blank level, HP and SP; no combat stats invented.'
    catalogs['skills']['3113']['availability']='catalog_only'
    catalogs['skills']['3113']['exclusion_reason']='No learning, starter, monster or item reference; legacy effect handler is empty. Not playable.'
    adjustments=[
        {'catalog':'items','id':'7500','field':'img','before':'item_035z.png','after':'item_035.png','reason':'Missing source asset; reuse existing generic token icon'},
        {'catalog':'economy_rules','id':'shop','field':'values','removed':8012,'reason':'Source stock references undefined item; do not invent content'},
        {'catalog':'skills','id':'7000','field':'P_MAXHP','before_field':'p_maxhp','reason':'Description-backed passive +30 HP; normalize recognized effect field'},
        {'catalog':'skills','id':'7001','field':'P_MAXHP','before_field':'p_maxhp','reason':'Description-backed passive +80 HP; normalize recognized effect field'}]
    catalogs['monsters']['1055']['data']['guard']='prob50'
    adjustments.append({'catalog':'monsters','id':'1055','field':'guard','before':'pro50','after':'prob50','reason':'Correct misspelled probabilistic guard policy; no invalid-enum fallback'})
    catalogs['items']['7500']['data']['img']='item_035.png'
    catalogs['economy_rules']['shop']['data']['values'].remove(8012)
    catalogs['economy_rules']['shop']['data']['values'].extend([7510,7511,7512,7513,7520])
    adjustments.append({'catalog':'economy_rules','id':'shop','field':'values','added':[7510,7511,7512,7513,7520],'reason':'Approved completed stat and skill reset access at existing item prices'})
    for id in ['7000','7001']: catalogs['skills'][id]['data']['P_MAXHP']=catalogs['skills'][id]['data'].pop('p_maxhp')
    for adjustment in adjustments:
        catalogs[adjustment['catalog']][adjustment['id']].setdefault('adjustments',[]).append(adjustment)
    manifest={'schema_version':1,'content_version':'','sources':hashes,'adjustments':adjustments,'counts':{k:len(v) for k,v in catalogs.items()},'extraction_warnings':warnings,'duplicate_array_keys_last_value_wins':Parser.duplicates,'semantics':{'status':'definitions_extracted_not_effect_implementation_certification','monster_money_under_2000':100,'monster_unset_position':'random_front_back_at_instantiation','drop_weights':'ordered additive per 10000; first matching interval wins','inactive_areas':'preserved with unavailable unlock','duplicate_skill_7005':'identical later switch case unreachable; first definition retained'}}
    for kind,rows in catalogs.items(): (out/f'{kind}.json').write_text(json.dumps(rows,ensure_ascii=False,indent=2)+'\n')
    manifest['content_version']=hashlib.sha256(''.join((out/f'{k}.json').read_text() for k in sorted(catalogs)).encode()).hexdigest()
    (out/'manifest.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(manifest['counts']))

def extract_tree(read,wrap,catalogs):
    path='data/data.skilltree.php'; text=read(path); s=clean(text)
    start=s.index('if(') if 'if(' in s else s.index('if (')
    end=s.index('asort($list)'); p=TreeParser(s[start:end]); rules=p.block(None)
    rows={}
    for n,(skill,conditions,pos) in enumerate(rules): rows[str(n)]=wrap(n,{'skill':skill,'when':{'all':conditions}},path,text,start+pos)
    catalogs['skill_tree']=rows

class TreeParser:
    def __init__(self,s): self.s=s; self.i=0
    def ws(self):
        while self.i<len(self.s) and self.s[self.i].isspace(): self.i+=1
    def block(self,closing):
        out=[]
        while True:
            self.ws()
            if self.i>=len(self.s):
                if closing: raise Unsupported('Unclosed skill tree block')
                return out
            if closing and self.s[self.i]==closing: self.i+=1; return out
            out+=self.statement()
    def statement(self):
        self.ws(); pos=self.i
        if self.s[self.i]=='{': self.i+=1; return self.block('}')
        if self.s.startswith('if',self.i):
            self.i+=2; self.ws()
            if self.s[self.i]!='(': raise Unsupported('Expected condition')
            self.i+=1; start=self.i
            while self.s[self.i]!=')': self.i+=1
            cond=parse_condition(self.s[start:self.i]); self.i+=1
            return [(skill,[cond]+conds,p) for skill,conds,p in self.statement()]
        m=re.match(r'\$list\[\]\s*=\s*"(\d+)"\s*;',self.s[self.i:])
        if not m: raise Unsupported('Unknown skill tree statement '+self.s[self.i:self.i+100])
        self.i+=m.end(); return [(m.group(1),[],pos)]
def parse_condition(s):
    s=re.sub(r'\bor\b','||',s); s=re.sub(r'\band\b','&&',s)
    if '||' in s: return {'any':[parse_condition(x) for x in s.split('||')]}
    if '&&' in s: return {'all':[parse_condition(x) for x in s.split('&&')]}
    s=s.strip()
    m=re.fullmatch(r'(!?)\$lnd\["(\d+)"\]',s)
    if m: return {'not_learned' if m.group(1) else 'learned':m.group(2)}
    m=re.fullmatch(r'\$char->job\s*==\s*"?(\d+)"?',s)
    if m: return {'job':m.group(1)}
    m=re.fullmatch(r'(\d+)\s*<\s*\$char->level',s)
    if m: return {'minimum_level':int(m.group(1))+1}
    raise Unsupported('Unknown condition '+s)
if __name__=='__main__': main()
