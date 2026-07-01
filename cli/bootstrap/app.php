<?php

use LaravelZero\Framework\Application;

$app = Application::configure(basePath: dirname(__DIR__))->create();
$app->useEnvironmentPath(dirname(__DIR__, 2));
return $app;
