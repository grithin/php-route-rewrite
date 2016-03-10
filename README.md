# Grithin's PHP Route

See example folder

## Design Goal

-	Provide standard predictable control flow by mimicking the url path for control file loads
-	Provide modularized routing instead of monolithic routing (separated, conditionally loaded routes instead of all routes  in one file)
-	Provide extreme customisability and the ability to loop over rules similar to apache mod rewrite.

## Structure
There is a control folder, and in that control folder are routes.php files and control files.  Let's say it is:
```
/control
```

The routes.php files are loaded according to the tokenised url path.  If the url were `http://bobery.com/part1/part2/part3`, the standard routes.php loaded would be
```
/control/_routing.php
/control/part1/_routing.php
/control/part1/part2/_routing.php
/control/part1/part2/part3/_routing.php
```

Not all of those routes.php files need to exist.  And, at any point, a routes.php file can change the tokens resulting in a different series of routes.php files being loaded..

Once the routes have been resolved, the result is either a new set of tokens or a callback.

If the routes.php files did nothing, the `http://bobery.com/part1/part2/part3` url would result in this series of controls being loaded:
```
/control/_control.php
/control/part1/_control.php
/control/part1/part2/_control.php
/control/part1/part2/part3/_control.php
```
Not all  of these control files need to exist.  At any point in the path, you can use the name of the token instead of `_control.php`, and it  will take precedent.  So, this could be
```
/control/control.php
/control/part1.php
/control/part1/part2.php
/control/part1/part2/part3.php
```
Note, the optional `/control/control.php` file serves as an optional system wide control file


If you want to end the routing prior to the tokens finishing, you must either exit or empty the unparsed tokens.  Loaded control files have the $route instance injected into their context, so you can empty the unparsed tokens using:
```php
$route->unparsedtokens = [];
```

## The Route Loop
Route will load all `_routing.php` files corresponding to the tokens in the current path.  If one of the rules changes the path, Route starts over and attempts to load all the `_routing.php` files corresponding to the new tokens in the new path.
```
Path: /test1/test2/test3
Route Loading:
	load /_routing.php, run file rule set
	load /test1/_routing.php, run file rule set
	load /test1/test2/_routing.php, run file rule set

	rule changes path to /test1/bob/bill

	run /_routing.php file rule set
	run /test1/_routing.php file rule set
	load /test1/bob/_routing.php, run file rule set
	load /test1/bob/bill/_routing.php, run file rule set
```

### Stopping the Loop
A rule can have a flag of `loop:last`, and if that rule matches, the loop will stop after it.

To partially stop the loop, there are two flags.
-	`file:last`:  This will cause no more rules from the file to be run
-	'once': This will prevent the rule from being run again

### Debugging

Just inspect the $route instance

```php
try{
	$route->handle('http://test.com/not/a/real/path');
}catch(RouteException $e){
	\Grithin\Debug::quit($route);
}

```

## `_routing.php` Files

`_routing.php` files should contained an variable array called `$rules`.

### `$rules` elements

Each element of the `$rules` array should follow this format
```simpex
'["'matchAgainst'","'changeInto|changeFunction'","'flag1','flag2'"]'
```

#### First Sub-element, matchAgainst

The initial path is standardized to neither start with or end with a slash.

By default, this is a case sensitive string indended to match exactly against the url path.  For `http://bobery.com/part1/part2/part3`, `part1/part2/part3` would match, but `part1/part2` and `part1/part2/part3/` would not.

There are two flags which change the nature of matchAgainst.  The `regex` flag results in considering the matchAgainst  string a regular expression.  The `caseless` flag  removes capitalization considerations.

When the `regex` flag is present, the resulting match array is available as:
```php
$route->regexMatch
```

There is a special handling of named regular expression matched groups to allow `changeInto` string to use part of the `matchAgainst` pattern.  Named regular expression groups can be used like:

```php
#match anything and name it "path"
(?<path>.*)

#match numbers and name it "id"
(?<id>[0-9]+)
```

The use of these named groups will be described  in a later section, but here is an example

```php
$rules[] = ['oldDir/(?<path>.*)','newDir/[path]','301,regex'];
```


#### Second Sub-element, `changeInto` and `changeFunction`

The second sub-element can be in one of three formats:
-	a string
-	something that returns true when applied to is_callable, but not a string

##### `changeInto`

Without the `regex` flag, the `changeInto` string represents the new absolute path used for token creation.

With the `regex` flag, the string replaces the matched part of the `matchAgainst` string, and named regular expression match groups are replaced with their matched values.  Match groups are indicated with
```simpex
'['matchName']'
```
Example
```php
$rules[] = ['user/(?<id>[0-9]+)','usr/[id]','regex'];
```

##### `changeFunction`

The `changeFunction` should return either the new path, or the part of the new path the pattern matched when using the `regex` flag.

If you want to use an anonymous function, this is rather easy
```php
$callback = function($route){
	print_r($match);
	die('end');	};
$rules[] = ['user/(?<id>[0-9]+)',$callback,'regex'];
```
The `changeFunction` gets  the $route instance as the first parameter.  Therre are some useful attributes of the $route instance that can be used:
-	`matcher` is the pattern string used for matching
-	`path` is the token path that is being matched against
-	`regexMatch` is the match array corresponding to preg_match($subject,$pattern,$match)



Using instance or class methods is also fairly easy

```php
class bob{
	function doRoute(){
		die('bob');
	}
}

$rules[] = ['bobStatic',['bob','doRoute']];
$bob = new bob;
$rules[] = ['bobInstance',[$bob,'doRoute']];

```

Unfortunately, as of yet, php has no way to, at a language level, indicate a string should be interpreted as a function.  So, it is a little more tricky when you want to use a non-method function in a rule.  You can use the Bound class for this:
```php
function doRoute(){
	die('bob')
}
$rules[] = ['bobFn',new \Grithin\Bound('doRoute')];
```


#### Third Sub-element, the Flags


-	'once' applies rule once, then ignores it the rest of the time
-	'file:last' last rule matched in the file.  Route does not parse any more rules in the containing file, but will parse rules in subsequent files
-	'last' is the last matched rule.  Route will just stop parsing rules after this.

-	'301' will send http redirect of code 301 (permanent redirect)
-	'307' will send http redirect of code 307 (temporary redirect)
-	'303' will send http redirect of code 303  (tell client to re-issue as get request)
-	'params' will append the query string to the end of the redirect on a http redirect

-	'caseless': ignore capitalisation
-	'regex': applies regex pattern matching

		Note,  the regex-match-groups from the last matched rule  are saved to $route->regexMatch.  Regex groups are created by using this syntax: `(?<id>[0-9]*)`.

## Notes
Route expects at least one file excluding a primary `_control.php` file.  If some route will end on the primary `_control.php`, you must either exit or catch and dispense the RouteException.