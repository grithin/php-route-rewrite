# Grithin's PHP Route

In the beginning, Apache mapped file system path to url path on incoming requests.  This wasn't ideal for scripts, since Apache could fail and return the script in plaintext.  Matching the file system with the url path is still the avenue of least surprise.

This Route class provides the functionality of file path to url path mapping, without making the script files public, and with a few additions:
-	a `_routing.php` file can serve like an `.htaccess` file with `mod_rewrite`.
	-	looped rule checking.  When a path changes because of a route rule, it may be desirable to recheck the existing rules for the new path, and do subsequent rewrites.  This is possible with `Route`.
-	a `_control.php` file will be run for anything within it's placed path hierarchy.

The benefit to doing it this way, instead of having a single route file that maps directly to flat controller classes, is:
-	expectable location of control logic
	-	`/section/page` would be at `/section/page.php` by default
-	expectable locations of route rules for particular paths
	-	if a section had specific routes, they will either be at `/section/_routing.php` or `/_routing.php`
-	expectable locations of section specific control logic
	-	for example, if only a certain type of user can access a section, that logic would be at `section/_control.php`
-	complex routing: see [The Route Loop](#the-route-loop)


There are some downsides to this method of routing:
-	in order to get all route rules, you'd have to collect the various `_routing.php` files
-	control logic is run as a file, not a function.  Although Route isolates the context of the files so there is no variable collision, the use of files changes the way tests are written
-	in order to get all possible routes, you'd have to consider both the route rules and the default behavior of matching url paths to file paths

Let's consider some UserController in some framework X that uses flat routing.  A benefit to a UserController, that handles incoming paths like '/user/x', is the ability to share functionality and variables.  Sometimes it is useful for a set of control functions to have access to the same control related utility functions, and sometimes its useful that a section controller sets some initialization or section sepcific variable data.  However, all of this is reproducable with `Route` through section `_control.php` files and `Route` globals (see [Route Globals](#route-globals))


## Simple Example

File paths
```
public/index.php
control/page.php
```

```php
# index.php

$_SERVER['REQUEST_URI'] = '/page';

$Route = new Route(['folder'=>realpath(__DIR__.'/../')]);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$Route->handle($path); # loads control/page.php
```



## Structure
There is a control folder (`/control`), and in that control folder are route files and control files.  

The route files are loaded gradually, according to the url path.  If the url were `http://bobery.com/part1/part2/part3`, the router would attempt to load:
```
/control/_routing.php
/control/part1/_routing.php
/control/part1/part2/_routing.php
/control/part1/part2/part3/_routing.php
```

Not all of those route files need to exist.  And, at any point, a route file can change the routing tokens, resulting in a different series of route files being loaded.

The result of the route files is either a final path or a callback.

If the route files did nothing, the final path would remain as the url path.

Control files are loaded in the same way route files are, and are loaded based on the final path.  If the final path were `/part1/part2/part3`, the router would attempt to load:
```
/control/_control.php
/control/part1/_control.php
/control/part1/part2/_control.php
/control/part1/part2/part3/_control.php
```
Not all of these control files need to exist.  At any point in the path, you can use the name of the token instead of `_control.php`, and it  will take precedent.  So, this could be
```
/control/_control.php
/control/part1.php
/control/part1/part2.php
/control/part1/part2/part3.php
```
Here, `/control/part1.php` replaces `/control/part1/_control.php`.  The `/control/_control.php` is an optional global control file, which is loaded for all requests.




## Route Globals
In the course of chained control logic, it is sometimes useful for the items in the chain to share-forward functionality or variables.  To enable this, `Route` provides a global array, which it injects/unpacks into control files (like `_control.php` or `page.php`) files.  By default, two globals are always available: `Route`, which refers to the Route instance, and `control`, which is an ArrayObject intended to be where you place control functionality and variables you want to share.  However, you can add globals.


```php
# file: _control.php
$control['sale'] = '10% off all blah';
$Route->globals['customize_for_user'] = function(){};


# file: section/item.php
view($customize_for_user($control['sale']))
```




## The Route Loop
A route file is only run once, but it's rules may apply multiple times, if the path changes.  This operations in a similar fashion to the rule loop of mod_rewrite.  It also, like mod_rewrite, allows the option for a rule to be final, and discontinue the loop.

Path: `/test1/test2/test3`
Route Loading:
-	load `/_routing.php`
-	run rules from `/_routing.php`
-	no path change, continue
-	load `/test1/_routing.php`
-	run rules from `/test1/_routing.php`
-	no path change, continue
-	load `/test1/test2/_routing.php`
-	run rules from `/test1/test2/_routing.php`
-	**path changes to** `/moved_section1/bob`
-	run rules from `/_routing.php`
-	**path changes to** `/section1/bob`
-	run rules from `/_routing.php`
-	no path change, continue
-	load `/section1/_routing.php`
-	run rules from  `/section1/_routing.php`
-	no path change, continue
-	path final result: `/section1/bob`




### Stopping the Loop
A rule can have a flag of `last`, and if that rule matches, the loop will stop after it.

You can also call`$route->routing_end()` within a route file or within a route rule callback.


### Debugging

Just inspect the $route instance

```php
try{
	$route->handle('http://test.com/not/a/real/path');
}catch(RouteException $e){
	\Grithin\Debug::quit($route);
}

```

## Route Files

Route files have available `$route`, containing the Route instance.

Route files should return an array of the route rules.

```php
return [
	['bob','bill'],
	['bill','sue']
];
```

### Route Rule
```php
[$match_pattern, $change, $flags]
```

#### $match_pattern
By default, interpret match_pattern as exact, case sensitive, match pattern.  With `http://bobery.com/part1/part2/part3`, `part1/part2/part3` would match, but `part1/part2` and `part1/part2/part3/` would not.

Flags can change interpretation of match_pattern.  
-	`regex` as regular expression
-	`caseless` applies match against lower case subject


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

-	The last match is also stored in `$route->regex_last_match`
-	A compilation of matches is stored in `$route->regex_matches`
-	Both of these will have keyed values, both of the numeric indices and the named group (if a named group is present)
	-	ex `$Route->regex_matches['id']`

##### Using Matches
Apart from a match callback (see $change &gt; callable), the control files have access to the route instance.  And, with a rule like `['test/from/(?<id>[0-9]+)','/test/to/[id]', 'regex,last']`, we have:
```js
route.tokens = [
	"test",
	"to",
	"123"]
route.regexMatch = {
	"0": "test\/from\/123",
	"id": "123",
	"1": "123"}
```


#### $change

Can be a string or callable

##### string

Without the `regex` flag, will replace entire path.

With the `regex` flag, serves as specialized match replacement (like preg_replace replacement parameter).  For convenience, match groups can be used
```simpex
'['matchName']'
```
Example
```php
$rules[] = ['user/(?<id>[0-9]+)','usr/[id]','regex'];
```

##### callable
A callable `function($route, $rule)`, that conforms to Route::is_callable, that should return a new path.

If `regex` flag is present, callable serves as `preg_replace_callback` callback, in which the 3rd parameter begins the normal `preg_replace_callback` callback parameters (`function($route, $rule, $matches)`)


#### $flags
Comma separrated flags, or an array of flags

-	'once' applies rule once, then ignores it the rest of the time
-	'file:last' last rule matched in the file.  Route does not parse any more rules in the containing file, but will parse rules in subsequent files
-	'last' is the last matched rule.  Route will just stop parsing rules after this.

-	'301' will send http redirect of code 301 (permanent redirect)
-	'307' will send http redirect of code 307 (temporary redirect)
-	'303' will send http redirect of code 303  (tell client to re-issue as get request)
-	'params' keep the GET params: will append the query string to the end of the redirect on a http redirect

-	'caseless': ignore capitalisation
-	'regex': applies regex pattern matching


#### Useful Examples
Point folders to index control files
```php
return [
	['^$','index','regex,last'], # the root path, special in that the initial `/` is removed and the path is empty
	['^(?<path>.*)/$','[path]/index','regex,last'], # paths ending in `/` to be pointed to their corresponding index control files
]
```

Re-assignment of id:
-	in `_routing.php`
```php
['test/from/(?<id>[0-9]+)','/test/to/[id]', 'regex,last'],
```
-	this also provides `$Route->named_matches['id']`

## Control
Loaded control files have the $route instance injected into their context, along with anything else keyed by the `$route->globals` array.

If you want to end the routing prior to the tokens finishing, you must either exit or call `$route->control_end()`.  If there are remaining tokens without corresponding control files, the router will consider this a page not found event.



## Notes
Route expects at least one file excluding a primary `_control.php` file.  If some route will end on the primary `_control.php`, you must either exit or catch and dispense the RouteException.