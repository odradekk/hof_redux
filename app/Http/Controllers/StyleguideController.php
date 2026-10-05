<?php

namespace App\Http\Controllers;

use App\Application\Battle\BattlePresenter;
use App\Http\View\UnitCards;

final class StyleguideController
{
    public function __invoke(BattlePresenter $battles)
    {
        $unit = ['id' => 1, 'name' => '艾琳', 'level' => 2, 'label' => '战士 · 前卫', 'img' => 'image/char/mon_080r.gif', 'star' => true, 'vitals' => ['hp' => 323, 'maxhp' => 330, 'sp' => 51, 'maxsp' => 52]];
        $monster = UnitCards::monster(['name' => '持斧哥布林', 'img' => 'mon_053.gif', 'level' => 1, 'land' => 'grass'], 2);
        $item = ['icon' => 'image/icon/we_sword026.png', 'name' => '短剑', 'refine' => 3, 'type' => '剑', 'qty' => 2, 'stats' => [['tone' => 'dmg', 'text' => '物理攻击：10'], ['tone' => 'charge', 'text' => '重量：1']], 'option' => '追加能力', 'note' => '道具说明'];

        $normal = ['id' => 'ally', 'name' => '艾琳', 'img' => 'mon_080r.gif', 'position' => 'front', 'level' => 2, 'hp' => 240, 'maxhp' => 330, 'sp' => 19, 'maxsp' => 52, 'state' => 0];
        $caster = array_replace($normal, ['id' => 'caster', 'name' => '梅林', 'img' => 'mon_106.gif', 'position' => 'back', 'casting' => 2000]);
        $poison = array_replace($normal, ['id' => 'poison', 'name' => '哥布林勇士', 'img' => 'mon_052.gif', 'state' => 2]);
        $dead = array_replace($poison, ['id' => 'dead', 'name' => '倒下的哥布林', 'hp' => 0, 'state' => 1]);
        $boss = array_replace($poison, ['id' => 'boss', 'name' => '共享首领', 'boss' => true, 'hp' => 7654321, 'maxhp' => 7654321, 'sp' => 123456, 'maxsp' => 123456, 'state' => 0]);
        $battle = $battles->present(['names' => ['银翼骑士团', '对手'], 'initial_teams' => [[$normal, $caster], [$poison, $dead, $boss]], 'mode' => 'boss', 'winner' => 0, 'events' => [
            ['type' => 'ActorSelected', 'actor' => 'ally'], ['type' => 'SkillUsed', 'actor' => 'ally', 'skill' => 1000],
            ['type' => 'DamageApplied', 'actor' => 'ally', 'target' => 'poison', 'amount' => 10, 'resource' => 'hp', 'before' => 250, 'after' => 240],
            ['type' => 'ActorSelected', 'actor' => 'poison'], ['type' => 'ActionSkipped', 'actor' => 'poison'],
        ]]);

        return view('dev.styleguide', ['unit' => $unit, 'monster' => $monster, 'item' => $item, 'battle' => $battle]);
    }
}
