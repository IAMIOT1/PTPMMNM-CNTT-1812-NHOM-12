<?php
require __DIR__.'/helper.php';
require __DIR__.'/../src/Input.php';
require __DIR__.'/../src/PackFilter.php';
$packs = require __DIR__.'/../data/packs.php';
same(count(filterPacks($packs,'')),3,'all');
same(count(filterPacks($packs,'viet')),1,'keyword-ignore-case');
same(count(filterPacks($packs,'xyz')),0,'not-found');
same(field(['name'=>['bad']], 'name'),'','array-input');
same(e('<b>'),'&lt;b&gt;','escape-html');
same(validateContribution(['pack_id'=>'1','name'=>"O'Neil",'email'=>'demo@example.test','text'=>'Xin chào'],$packs)['errors'],[],'valid-form');
same(count(validateContribution(['pack_id'=>'999','name'=>'','email'=>'bad','text'=>''],$packs)['errors']),4,'invalid-form');
finish();
