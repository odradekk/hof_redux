<?php

return [
    'display_timezone' => env('HOF_DISPLAY_TIMEZONE', env('APP_TIMEZONE', 'UTC')),
    'asset_version' => env('APP_ASSET_VERSION'),
    'menu' => [
        ['label' => '首页', 'route' => 'home', 'active' => ['home', 'player.character'], 'visibility' => 'auth'],
        ['label' => '狩猎', 'route' => 'hunt', 'active' => ['hunt*', 'bosses*', 'simulation'], 'visibility' => 'auth'],
        ['label' => '仓库', 'route' => 'player.inventory', 'active' => ['player.inventory'], 'visibility' => 'auth'],
        ['label' => '城镇', 'route' => 'town', 'active' => ['town', 'player.roster', 'player.shop*', 'player.smithy*', 'auction*', 'ranking*'], 'visibility' => 'auth'],
        ['label' => '设置', 'route' => 'account', 'active' => ['account', 'player.preferences'], 'visibility' => 'auth'],
        ['label' => '记录', 'route' => 'reports.index', 'active' => ['reports.*', 'multiplayer.report'], 'visibility' => 'auth'],
        ['label' => '管理', 'route' => 'admin.index', 'active' => ['admin.*'], 'visibility' => 'admin'],
        ['label' => '首页', 'route' => 'login', 'active' => ['login'], 'visibility' => 'guest'],
        ['label' => '新注册', 'route' => 'register', 'active' => ['register'], 'visibility' => 'guest'],
        ['label' => '规则和手册', 'route' => 'manual', 'active' => ['manual'], 'visibility' => 'guest'],
        ['label' => '游戏数据', 'route' => 'catalog', 'active' => ['catalog'], 'visibility' => 'guest'],
        ['label' => '战斗记录', 'route' => 'reports.index', 'active' => ['reports.*'], 'visibility' => 'guest'],
    ],
    'town' => [
        ['label' => '店(Shop)', 'route' => 'player.shop', 'children' => [['label' => '买', 'route' => 'player.shop'], ['label' => '卖', 'route' => 'player.shop.sell'], ['label' => '打工', 'route' => 'player.shop.work']]],
        ['label' => '人材斡旋所', 'route' => 'player.roster', 'children' => []],
        ['label' => '锻冶屋(Smithy)', 'route' => 'player.smithy.refine', 'children' => [['label' => '精炼工房', 'route' => 'player.smithy.refine'], ['label' => '制作工房', 'route' => 'player.smithy.create']]],
        ['label' => '拍卖会场(Auction)', 'route' => 'auction', 'children' => []],
        ['label' => '竞技场(Colosseum)', 'route' => 'ranking', 'children' => []],
    ],
    'item_categories' => [
        'weapon' => ['label' => '武器', 'types' => ['剑', '双手剑', '匕首', '矛', '短柄斧', '魔杖', '锤', '枪', '斧', '杖', '弓', '弩', '十字弓', '鞭']],
        'armor' => ['label' => '防具', 'types' => ['盾', 'MainGauche', '书', '甲', '衣服', '长袍']],
        'item' => ['label' => '道具', 'types' => ['道具']],
        'other' => ['label' => '其他', 'types' => ['其他', '地图', '材料', '特殊', '钥匙']],
    ],
    'report_tabs' => [
        'pve' => ['label' => '普通', 'route' => 'reports.index', 'parameters' => ['type' => 'pve']],
        'boss' => ['label' => 'BOSS', 'route' => 'reports.index', 'parameters' => ['type' => 'boss']],
        'pvp' => ['label' => '竞技场', 'route' => 'reports.index', 'parameters' => ['type' => 'pvp']],
    ],
    'catalog_tabs' => [
        'jobs' => ['label' => '职业', 'route' => 'catalog', 'parameters' => ['kind' => 'jobs']],
        'items' => ['label' => '道具', 'route' => 'catalog', 'parameters' => ['kind' => 'items']],
        'skills' => ['label' => '技能', 'route' => 'catalog', 'parameters' => ['kind' => 'skills']],
        'monsters' => ['label' => '怪物', 'route' => 'catalog', 'parameters' => ['kind' => 'monsters']],
        'areas' => ['label' => '地图', 'route' => 'catalog', 'parameters' => ['kind' => 'areas']],
        'conditions' => ['label' => '行动条件', 'route' => 'catalog', 'parameters' => ['kind' => 'conditions']],
        'enchants' => ['label' => '附魔', 'route' => 'catalog', 'parameters' => ['kind' => 'enchants']],
        'rules' => ['label' => '数值规则', 'route' => 'catalog', 'parameters' => ['kind' => 'rules']],
    ],
    'manual_tabs' => [
        'basic' => ['label' => '规则和手册', 'route' => 'manual', 'parameters' => []],
        'advanced' => ['label' => '高级指南', 'route' => 'manual', 'parameters' => ['section' => 'advanced']],
        'tutorial' => ['label' => '教学', 'route' => 'manual', 'parameters' => ['section' => 'tutorial']],
    ],
];
