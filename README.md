# PHP Route Rewrite

For potentially complex path or destination rewriting, similar to Apache's mod_rewrite.  This is a rewrite of \Grithin\Route, to be PSR 15 middleware.

Mod rewrite provides some useful features:
-	various redirect responses
-	path rewriting without a redirect response
-	progressive rule application
-	looped rule application

RouteRewrite provides these.


## Use
Let's say I want to change every `/[0-9]*` to `/user/view/[id]`.

For this example, let's set up a simple request handler that prints the variables and the target url, and a function that does the call to the handler

```php
use Nyholm\Psr7\Factory\Psr17Factory;
class SimpleRequestHandler
	implements \Psr\Http\Server\RequestHandlerInterface
{
	public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
		$rewrite = $request->getAttribute('rewrite');
		echo 'id: '.$rewrite->variables['id'];
		echo " | ";
		echo (string)$request->getUri();

		return (new Psr17Factory)->createResponse(200);
	}
}
function do_request_through_handler($url, $rulesets){
	$Psr17Factory = new Psr17Factory();
	$request = $Psr17Factory->createServerRequest('GET', $url);
	$route = new \Grithin\RouteRewrite($rulesets);
	$handler = new SimpleRequestHandler;
	$route->process($request, $handler);
}
```
Now, let's look at the ruleset to achieve this
```php
$url = 'http://bob.com/1';
$rulesets = [
	'/' => [
		['/(?<id>[0-9]+)', '/user/view/[id]', 'regex, once']
	],
];
do_request_through_handler($url, $rulesets);
/* >
array (
  'variables' =>
  array (
    0 => '/1',
    'id' => '1',
    1 => '1',
  ),
  'url' => 'http://bob.com/user/view/1',
*/
```

RouteRewrite checks rules as it proceeds through tokens in the path.  For something like `/user/view/1`, RouteRewrite will check if there are rulesets with the keys
-	''
-	'/'
-	'/user'
-	'/user/'
-	'/user/view'
-	'/user/view/'
-	'/user/view/1'

When the path is rewritten, it will rerun the rulesets at the new path's tokens.  So, if we wanted, we could do something like

```php

$url = 'http://bob.com/1';
$rulesets = [
	'/' => [
		['.*', '/user[0]', 'regex, once']
	],
	'/user/' => [
		['([.])(?<id>[0-9]+)', '[1]view/[id]', 'regex, once']
	]
];

do_request_through_handler($url, $rulesets);
/* >
array (
  'variables' =>
  array (
    0 => '/user/1',
    1 => '/user/',
    'id' => '1',
    2 => '1',
  ),
  'url' => 'http://bob.com/user/view/1',
)

*/
```
Here' the first rule takes whatever the url was, and prefixes it with `/user`.  Then, RouteRewrite matches the `/user/` ruleset, and turns the url into `/user/view/[id]`.

Some things to note about these examples
-	they use special regex replacements in the form `[x]`.  The `x` can either by a numbered group or a named group.
-	the `[.]` is replace with the current ruleset key.  So, in this case, the ruleset key is `/user/`, so `[.]` is replaced with `/user/`.
-	the third item is a list of flags


### Route Middleware, Accumulation
Often, the route signifies what middleware should be used.  To make a general approach to allowing rules to indicate things like which middleware to use, there is a data part of the rule - the 4th array item.
```php
[$match, $replacement, $flags, $data]
```

RouteRewrite will accumulate the data and make it available to within the request.  The array of accumulated data is available as:
```php
$rewrite = $request->getAttribute('rewrite');
$rewrite->data;
```
This data could be just middleware strings, for instance

```php
$rule = ['^/user/.*', '[0]', 'last, regex', ['auth']]
```
This would then depend on further middleware to do the adding.  However, RouteRewrite also provides a data_handler option that can do this adding of middleware, and is called prior to passing to the next middleware.

```php
# $app is some framework object that can add middleware
$middleware_add = function($data, $RouteRewrite) use ($app){
	foreach($data as $item){
		$middlewares = Arrays::from($item);
		foreach($middlewares as $middleware){
			$app->addMiddleware($middleware);
		}
	}
}
$route = new \Grithin\RouteRewrite($rulesets, ['data_handler'=>$middleware_add]);
```

### Logger
Because mod_rewrite can become complex, as the rules are looped, you can also pass in a logger to keep track of what is happening

```php
$logger = function($message, \Grithin\RouteRewrite $route, $rule){
	echo $message."\n";
};
$route = new \Grithin\RouteRewrite($rulesets, ['logger'=>$logger]);
```




### Route Rule
```php
[$match_pattern, $replacement, $flags, $data]
```

#### $match_pattern
By default, interpret match_pattern as exact, case sensitive, match pattern.  With `http://bobery.com/part1/part2/part3`, `/part1/part2/part3` would match, but `/part1/part2` and `/part1/part2/part3/` would not.

Flags can change interpretation of match_pattern.  
-	`regex` as regular expression
-	`caseless` applies match against a lower case subject


#### Regex



##### Default Numeric Group Matches
```php
# reposition id
['/item/([0-9]+)/view', '/item/view/[1]', 'regex']
```


##### Named Matches
```php
# match anything and name it "path"
['(?<path>.*)', 'prefix/[path]', 'regex']

# match numbers and name it "id"
['old/(?<id>[0-9]+)', 'new/[id]','regex']
```


#### $replacement
##### closure
A closure `function($path, $rule, \Grithin\RouteRewrite $route): string`, should return a new path.

If `regex` flag is present, 4th parameter begins the normal `preg_replace_callback` callback parameters ($matches)


#### $flags
Comma separrated flags, or an array of flags

-	'once' applies rule once, then ignores it the rest of the time
-	'set:last' last rule matched in that rule set path.  Route does not parse any more rules in that pathed rule set, but will parse rules in subsequent paths
-	'last' is the last matched rule.  Route will just stop parsing rules after this.

-	'301' will send http redirect of code 301 (permanent redirect)
-	'307' will send http redirect of code 307 (temporary redirect)
-	'303' will send http redirect of code 303  (tell client to re-issue as get request)
-	[0-9] will return a response with that response code, and will use the `$data` part as a message in the response body
-	'params' keep the GET params: will append the query string to the end of the redirect on a http redirect

-	'caseless': ignore capitalisation
-	'regex': applies regex pattern matching
