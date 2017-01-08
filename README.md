# Grithin's PHP Route

See example folder

## Design Goal

-	Provide standard predictable control flow by mimicking the url path for control file loads
-	Provide modularized routing instead of monolithic routing (separated, conditionally loaded routes instead of all routes  in one file)
-	Provide extreme customisability and the ability to loop over rules similar to apache mod rewrite.

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


## The Route Loop
A route file is only run once, but it's rules may apply multiple times, if the path changes

### Example
Path: `/test1/test2/test3`
Route Loading:
-	load `/_routing.php`, run file rule set
-	load `/test1/_routing.php`, run file rule set
-	load `/test1/test2/_routing.php`, run file rule set

-	rule changes path to `/test1/bob/bill`

-	run `/_routing.php` file rule set
-	run `/test1/_routing.php` file rule set
-	load `/test1/bob/_routing.php`, run file rule set
-	load `/test1/bob/bill/_routing.php`, run file rule set

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
```simpex
'["'match_pattern'","'change'","'flag1','flag2'"]'
```

#### $match_pattern
By default, interpret match_pattern as exact, case sensitive, match pattern.  With `http://bobery.com/part1/part2/part3`, `part1/part2/part3` would match, but `part1/part2` and `part1/part2/part3/` would not.

Flags can change interpretation of match_pattern.  
-	`regex` as regular expression
-	`caseless` applies match against lower case subject


#### Regex
##### Named Matches
```php
# match anything and name it "path"
['(?<path>.*)', 'prefix/[path]', 'regex']

# match numbers and name it "id"
['old/(?<id>[0-9]+)', 'new/[id]','regex']
```

The last match is also stored in $route->regexMatch

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

Re-assignment of id
```php
['test/from/(?<id>[0-9]+)','/test/to/[id]', 'regex,last'],
```

## Control
Loaded control files have the $route instance injected into their context, along with anything else keyed by the `$route->globals` array.

If you want to end the routing prior to the tokens finishing, you must either exit or call `$route->control_end()`.  If there are remaining tokens without corresponding control files, the router will consider this a page not found event.



## Notes
Route expects at least one file excluding a primary `_control.php` file.  If some route will end on the primary `_control.php`, you must either exit or catch and dispense the RouteException.