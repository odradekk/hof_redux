{{-- Page title and switcher shared by the manual pages, in the legacy footer order (手册 - 教学 - 游戏数据). --}}
<x-sec as="h1" :title="$title" />
<x-tabs :items="$tabs" />
