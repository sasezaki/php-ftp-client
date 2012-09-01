<?php
require_once dirname(__DIR__) . '/class/FtpAlternative/autoload.php';

spl_autoload_register(function($name) {
	
	$name = str_replace("_", DIRECTORY_SEPARATOR, $name) . ".php";
	$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tests';
	$fn = $dir . DIRECTORY_SEPARATOR . $name;
	
	if (is_readable($fn))
	{
		require $fn;
	}
});

if (is_readable(__DIR__ . '/config.php'))
{
	require __DIR__ . '/config.php';
}
else
{
	require __DIR__ . '/config.dist.php';
}