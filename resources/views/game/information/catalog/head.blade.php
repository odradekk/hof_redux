{{-- Page title and the legacy "| 职业(Job) | 道具(item) | 判定 |" switcher shared by every game data page. --}}
<x-sec as="h1" title="游戏数据(GameData)"><x-slot:aside>内容版本 {{ substr($data->version(), 0, 12) }}</x-slot:aside></x-sec>
<x-tabs :items="$tabs" />
