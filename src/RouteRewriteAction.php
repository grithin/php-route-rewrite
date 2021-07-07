<?php
namespace Grithin;


class RouteRewriteAction{
	public $status; # status code
	public $message; # message to display with status code
	public $target; # target url or path
	public $original; # original path
	public $params; # path variables extracted from regex
	public $tokens; # tokens parsed that led to this path.  Useful for getting the parsed token in some path like /user/2 (the "user" would be the last token, and the 2 assigned to a path_variable)
	public $accumulation = []; # accumulation data from the rules

	public function __construct($options){
		foreach($options as $k=>$v){
			$this->$k = $v;
		}
	}

}