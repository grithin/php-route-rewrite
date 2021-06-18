<?php

function saySomething(){
	die('something');
}

function sayNothing(){
	die('nothing');
}
class sayNothingClass{
	static function sayNothing(){
		die('nothing class');
	}
}


return [
	['test/something','\\saySomethings'], # special namespaced string interpretted as callable by router
	['test/nothing',new \Grithin\Bound('sayNothing')], # another way to make a string interpretted as callable by the router
	['test/moreNothing',['sayNothingClass','sayNothing']],
	['test/bill','/test/bob'], # plain replacement
	['test/sue','/test/jan', 'last'],
	['test/jan','/test/jill'],
	['test/from/(?<id>[0-9]+)','/test/to/[id]', 'regex,last'],
];
