<?



function globally($route){
	\Grithin\Debug::out([$route->regexMatch, $route->tokens]);
	\Grithin\Debug::quit('Route Rule Function Called');
}
class example{
	static function statically($route){
		\Grithin\Debug::out([$route->regexMatch, $route->tokens]);
		\Grithin\Debug::quit('Route Rule Function Called');
	}
	function instancely($route){
		\Grithin\Debug::out([$route->regexMatch, $route->tokens]);
		\Grithin\Debug::quit('Route Rule Function Called');
	}
}

$example = new example;
$rules[] = ['globalFunction.*', new \Grithin\Bound('globally'), 'regex,caseless'];
$rules[] = ['instanceMethod.*',[$example,'instancely'], 'regex,caseless'];
$rules[] = ['staticMethod.*',['example','statically'], 'regex,caseless'];


$rules[] = ['index', '/', '301'];#< redirect

$rules[] = ['', '/index', 'last'];#< note, b/c the replacement "index" would be matched by the preceding rule, we must specify this as the last rule if matched
$rules[] = ['bill/(?<id>.*)', 'test/bob/[id]', 'regex'];