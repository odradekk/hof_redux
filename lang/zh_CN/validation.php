<?php

return [
    'required' => ':attribute不能为空。',
    'string' => ':attribute必须是文字。',
    'integer' => ':attribute必须是整数。',
    'numeric' => ':attribute必须是数字。',
    'array' => ':attribute必须是列表。',
    'boolean' => ':attribute必须是有效选项。',
    'confirmed' => '两次输入的:attribute不一致。',
    'current_password' => '当前密码不正确。',
    'distinct' => ':attribute不能重复。',
    'in' => ':attribute选项无效。',
    'exists' => ':attribute不存在。',
    'unique' => ':attribute已被使用。',
    'uuid' => ':attribute无效，请刷新页面后重试。',
    'regex' => ':attribute格式不正确。',
    'not_regex' => ':attribute包含不允许的字符。',
    'min' => ['string' => ':attribute至少需要:min个字符。', 'numeric' => ':attribute不能小于:min。', 'array' => ':attribute至少选择:min项。'],
    'max' => ['string' => ':attribute最多允许:max个字符。', 'numeric' => ':attribute不能大于:max。', 'array' => ':attribute最多选择:max项。'],
    'between' => ['string' => ':attribute需要:min至:max个字符。', 'numeric' => ':attribute必须在:min至:max之间。', 'array' => ':attribute需要:min至:max项。'],
    'attributes' => [
        'login' => '账号', 'password' => '密码', 'password_confirmation' => '确认密码', 'current_password' => '当前密码', 'name' => '名字',
        'character_name' => '角色名字', 'base_type' => '职业', 'gender' => '性别', 'body' => '内容', 'title' => '标题', 'confirm' => '确认词',
        'party' => '出战队伍', 'party.*' => '出战角色', 'operation_id' => '操作编号', 'inventory_id' => '道具', 'quantity' => '数量', 'price' => '价格',
        'hours' => '出品时长', 'comment' => '说明', 'row' => '行动模式行', 'color' => '留言颜色', 'amount' => '金额',
    ],
];
