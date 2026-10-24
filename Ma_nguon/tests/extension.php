<?php
require __DIR__.'/helper.php';
require __DIR__.'/../src/PackFilter.php';
$packs = require __DIR__.'/../data/packs.php';
function ids(array $items): array { return array_column($items, 'id'); }
same(ids(filterPacks($packs,'')),[1,2,3],'all');
same(ids(filterPacks($packs,'viet')),[1],'keyword');
same(ids(filterPacks($packs,'','web')),[1,3],'platform');
same(ids(filterPacks($packs,'','',true)),[1,3],'incomplete');
same(ids(filterPacks($packs,'French','web',true)),[3],'combined');
same(ids(filterPacks($packs,'','mobile',true)),[],'complete-platform');
same(ids(filterPacks($packs,'','unknown')),[],'unknown-platform');
same(count($packs),3,'input-unchanged');
finish();
