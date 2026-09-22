<?php
// 由 entrypoint.sh 调用：从脱敏参考副本生成本地 dev 专用配置文件。
// 用法：gen-legacy-config.php setting <dst/setting.php>
//       gen-legacy-config.php admin <dst/admin.php>
// 凭据经环境变量 LEGACY_CRYPT_KEY / LEGACY_UP_PASS / LEGACY_ADMIN_PASSWORD
// 或 entrypoint 持久化的随机值传入；永不提交到仓库。
// 参考副本为 CRLF 换行，以下替换只触碰 ASCII  Credential 表达式，换行保持原样。

$kind = $argv[1] ?? '';
$out = $argv[2] ?? '';
if (($kind !== 'setting' && $kind !== 'admin') || $out === '') {
    fwrite(STDERR, "usage: gen-legacy-config.php setting|admin <output.php>\n");
    exit(2);
}

$dstDir = dirname($out);
$ref = $dstDir . '/' . ($kind === 'setting' ? 'setting.reference.php' : 'admin.reference.php');
if (!is_file($ref)) {
    fwrite(STDERR, "reference file missing: $ref\n");
    exit(1);
}

$needle = '(static function () { throw new RuntimeException("Reference only: credential removed"); })()';
if ($kind === 'setting') {
    $replacements = [
        'CRYPT_KEY' => getenv('LEGACY_SECRET_CRYPT_KEY'),
        'UP_PASS' => getenv('LEGACY_SECRET_UP_PASS'),
    ];
    $expected = 2;
} else {
    $replacements = [
        'ADMIN_PASSWORD' => getenv('LEGACY_SECRET_ADMIN_PASSWORD'),
    ];
    $expected = 1;
}

$content = file_get_contents($ref);
$count = 0;
foreach ($replacements as $name => $value) {
    if ($value === false || $value === '') {
        fwrite(STDERR, "missing secret env for $name\n");
        exit(1);
    }
    // var_export 保证任意随机字符串都能生成合法 PHP 字面量。
    // 注意：必须用 str_replace 而非 preg_replace——替换值含 $6$ 等 crypt 盐，
    // preg_replace 会把它们当成反向引用吃掉。
    $replacement = 'define("' . $name . '", ' . var_export($value, true) . ');';
    $fullNeedle = 'define("' . $name . '", ' . $needle . ');';
    $n = substr_count($content, $fullNeedle);
    $content = str_replace($fullNeedle, $replacement, $content);
    $count += $n;
}
if ($count !== $expected) {
    fwrite(STDERR, "replaced $count credential(s), expected $expected; reference layout changed?\n");
    exit(1);
}

file_put_contents($out, $content);
echo "generated $out\n";
