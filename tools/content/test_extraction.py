"""Run with python3 -m unittest discover -s tools/content -p 'test_*.py'."""
import json,subprocess,sys,tempfile,unittest
from pathlib import Path
from extract import Parser,Unsupported,parse_condition,clean
from validate import validate
ROOT=Path(__file__).resolve().parents[2]
SOURCE=ROOT/'legacy' if (ROOT/'legacy/data').is_dir() else ROOT
ASSETS=ROOT/'public/image' if (ROOT/'public/image').is_dir() else SOURCE/'image'

class ExtractionTests(unittest.TestCase):
    def test_literals_and_multiplication(self):
        self.assertEqual(Parser('array("日本語",true,60*60*24, array("0"=>3,"1"=>4))').value(),['日本語',True,86400,[3,4]])
    def test_no_arbitrary_calls_or_variables(self):
        for expression in ['system("id")','include("index.php")','$unknown','eval("1")','file_get_contents("/etc/passwd")']:
            with self.assertRaises(Unsupported): Parser(expression).value()
    def test_no_interpolation_except_label_quantity(self):
        self.assertEqual(Parser('"HP {$Quantity}"').value(),'HP ←←')
        with self.assertRaises(Unsupported): Parser('"$secret"').value()
    def test_comments_cannot_create_definitions(self):
        text='/* case 1: */ "case 2:" // case 3:\n'
        self.assertNotIn('case 1:',clean(text)); self.assertNotIn('case 3:',clean(text)); self.assertIn('case 2:',clean(text))
    def test_condition_grammar(self):
        self.assertEqual(parse_condition('$lnd["1001"] && $char->job == 100'),{'all':[{'learned':'1001'},{'job':'100'}]})
        with self.assertRaises(Unsupported): parse_condition('shell_exec("id")')
    def test_extraction_is_reproducible_without_executing_php(self):
        with tempfile.TemporaryDirectory() as temporary:
            subprocess.run([sys.executable,str(ROOT/'tools/content/extract.py'),'--source',str(SOURCE),'--output',temporary],check=True,capture_output=True)
            for generated in Path(temporary).glob('*.json'):
                self.assertEqual(generated.read_bytes(),(ROOT/'content'/generated.name).read_bytes(),generated.name)
    def test_full_reference_and_asset_validation(self):
        result=validate(ROOT/'content',ASSETS)
        self.assertEqual(result['issues'],[])
        self.assertGreater(result['checks'],4300)
        self.assertEqual({x['where'] for x in result['warnings']},{'areas/blow01'})
    def test_provenance_matches_original_source_bytes(self):
        import hashlib
        manifest=json.loads((ROOT/'content/manifest.json').read_text())
        for name,expected in manifest['sources'].items(): self.assertEqual(hashlib.sha256((SOURCE/name).read_bytes()).hexdigest(),expected,name)

if __name__=='__main__': unittest.main()
